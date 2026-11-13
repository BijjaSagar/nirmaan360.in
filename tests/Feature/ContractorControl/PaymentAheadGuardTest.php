<?php

namespace Tests\Feature\ContractorControl;

use App\Models\Alert;
use App\Models\ApprovalRule;
use App\Models\AuditLog;
use App\Models\ContractorPayment;
use App\Models\CustomRole;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\ContractorControl\ContractorPaymentService;
use App\Services\ContractorControl\PaymentAheadGuard;
use App\Services\ContractorPortal\ContractorPortalService;
use App\Services\SiteAlerts\Sources\PaymentAheadSource;
use App\Support\Dec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * No contractor payment beyond the work done unless the owner overrides in writing. The line is the
 * payment-ahead alert's own: the same work done, paid to date and project limit (default 5 points).
 * The contract is 100 units at ₹1,000, so ₹1,00,000, and every ₹1,000 is one point.
 */
class PaymentAheadGuardTest extends TestCase
{
    use BuildsContractorControl, RefreshDatabase;

    private const REASON = 'The contractor has to buy steel for the next slab before the price rises on Monday.';

    private const BASE_KEYS = ['id', 'number', 'status', 'work_order_id', 'work_order_number', 'contractor', 'boq_item_id', 'milestone_id', 'paid_on', 'entered_by', 'client_uuid'];

    private const MONEY_KEYS = ['amount', 'mode', 'paid_to', 'reference', 'is_advance', 'notes', 'has_bill', 'is_reversal', 'approval_id', 'impact'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake();
        $this->site();
    }

    private function act(ContractorPayment $payment, string|User $as, ?string $reason = null): void
    {
        $user = match (true) {
            $as instanceof User => $as,
            $as === 'owner' => $this->owner,
            $as === 'pm' => $this->manager,
            default => $this->member($this->organization, $as),
        };

        app(ApprovalService::class)->act($payment->currentApproval()->first(), $user, 'approve', context: ['override_reason' => $reason]);
    }

    private function refusedFor(ContractorPayment $payment, string|User $as, ?string $reason = null): string
    {
        try {
            $this->act($payment, $as, $reason);
        } catch (ValidationException $exception) {
            $this->assertSame('IN_REVIEW', $payment->refresh()->status);

            return $exception->errors()['override_reason'][0];
        }

        $this->fail(($as instanceof User ? $as->name : $as).' approved a payment ahead of the work.');
    }

    /** A custom role at the project manager's approval level, allowed to pass contractor payments, without money.view. */
    private function approverWithoutMoney(): User
    {
        $role = CustomRole::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Payment passer',
            'permissions' => ['contractor.view', 'contractor.approve'],
            'approval_role' => 'pm',
        ]);
        $user = $this->member($this->organization, $role->baseRole());
        $user->forceFill(['custom_role_id' => $role->id])->save();

        $this->assertTrue($user->can('contractor.approve'));
        $this->assertFalse($user->can('money.view'));

        return $user;
    }

    private function aheadAlerts(): int
    {
        return count(iterator_to_array(app(PaymentAheadSource::class)->findings($this->project, today()), false));
    }

    public function test_raising_ahead_of_the_work_is_allowed_from_the_app_and_shows_the_warning_as_before(): void
    {
        $order = $this->contract();
        Sanctum::actingAs($this->supervisor);

        $raised = $this->postJson('/api/v1/work-orders/'.$order->id.'/contractor-payments', ['paid_on' => '2026-11-01', 'amount' => 90000, 'mode' => 'upi'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'IN_REVIEW');
        $this->assertSame(self::BASE_KEYS, array_keys($raised->json('data')));

        Sanctum::actingAs($this->manager);
        $shown = $this->getJson('/api/v1/contractor-payments/'.$raised->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.impact.after.label', 'Paid 90%, Work 0%')
            ->assertJsonPath('data.impact.after.over_limit', true);
        $this->assertSame([...self::BASE_KEYS, ...self::MONEY_KEYS], array_keys($shown->json('data')));
    }

    public function test_the_project_manager_cannot_approve_a_payment_past_the_limit(): void
    {
        $payment = $this->raise($this->contract(), '6000');

        $message = $this->refusedFor($payment, 'pm');

        $this->assertSame("Approving this payment would make it 6% paid against 0% work done, 6 points ahead, beyond this project's limit of 5 points. Only the owner can approve it, with a written reason.", $message);
        $this->assertSame(1, $payment->currentApproval->steps()->count(), 'Only the submit step is on record.');
        $this->assertSame(0, AuditLog::where('action', 'approval.approve')->count());
        $this->assertNull($payment->override_reason);
    }

    public function test_up_to_the_limit_the_manager_approves_and_the_alert_agrees(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '70');

        // Work 70%, paid 75%: exactly 5 points ahead, the limit, which the alert does not raise either.
        $this->act($this->raise($order, '75000'), 'pm');
        $this->assertSame(0, $this->aheadAlerts());

        // ₹10 more is 75.01%: past the limit for both.
        $next = $this->raise($order, '10');
        $this->refusedFor($next, 'pm');
        $this->assertSame(0, $this->aheadAlerts());

        $this->act($next, 'owner', self::REASON);
        $this->assertSame('APPROVED', $next->refresh()->status);
        $this->assertSame(1, $this->aheadAlerts());
    }

    public function test_the_projects_own_limit_is_the_line(): void
    {
        $this->project->update(['payment_gap_alert_pct' => 20]);
        $order = $this->contract();

        // 15 points ahead is inside a 20-point limit.
        $first = $this->raise($order, '15000');
        $this->act($first, 'pm');
        $this->assertSame('APPROVED', $first->refresh()->status);

        $this->assertStringContainsString('limit of 20 points', $this->refusedFor($this->raise($order, '6000'), 'pm'));
    }

    public function test_admin_holds_everything_but_is_not_the_owner(): void
    {
        $payment = $this->raise($this->contract(), '50000');

        $this->refusedFor($payment, 'admin', self::REASON);
        $this->assertNull($payment->refresh()->override_reason);
    }

    public function test_the_owner_overrides_only_with_a_written_reason_which_is_kept_and_audited(): void
    {
        $payment = $this->raise($this->contract(), '50000');

        $this->assertStringContainsString('Only the owner can approve it', $this->refusedFor($payment, 'pm'));
        $this->assertStringContainsString('at least one full sentence', $this->refusedFor($payment, 'owner'));
        $this->assertStringContainsString('at least one full sentence', $this->refusedFor($payment, 'owner', 'ok fine'));
        $this->assertStringContainsString('at least one full sentence', $this->refusedFor($payment, 'owner', 'Approved approved approved'));

        // Hindi counts as well as English.
        $this->act($payment, 'owner', 'ठेकेदार को अगले स्लैब के लिए सरिया खरीदना है।');

        $payment->refresh();
        $this->assertSame('APPROVED', $payment->status);
        $this->assertSame('ठेकेदार को अगले स्लैब के लिए सरिया खरीदना है।', $payment->override_reason);
        $this->assertSame($this->owner->id, $payment->override_by);
        $this->assertNotNull($payment->override_at);

        $log = AuditLog::where('action', 'contractor_payment.owner_override')->sole();
        $this->assertSame($payment->id, $log->auditable_id);
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame('ठेकेदार को अगले स्लैब के लिए सरिया खरीदना है।', $log->new_values['reason']);
        $this->assertSame(['0.00', '0.00', '50000.00', '50.00', '5.00'], [$log->new_values['work_done'], $log->new_values['paid'], $log->new_values['paid_after'], $log->new_values['gap_after'], $log->new_values['limit_pct']]);
    }

    public function test_only_the_last_level_is_stopped(): void
    {
        ApprovalRule::create(['organization_id' => $this->organization->id, 'doc_type' => 'contractor', 'levels' => ['pm', 'owner'], 'active' => true]);
        $payment = $this->raise($this->contract(), '50000');
        $this->assertSame(['pm', 'owner'], $payment->currentApproval->levels);

        // The manager's level only moves it on; the owner's is the one that makes it count.
        $this->act($payment, 'pm');
        $this->assertSame(2, $payment->currentApproval()->first()->current_level);

        $this->refusedFor($payment, 'owner');
        $this->act($payment, 'owner', self::REASON);
        $this->assertSame('APPROVED', $payment->refresh()->status);
    }

    public function test_an_empty_approval_chain_sends_a_payment_ahead_of_the_work_to_the_owner(): void
    {
        ApprovalRule::create(['organization_id' => $this->organization->id, 'doc_type' => 'contractor', 'levels' => [], 'active' => true]);
        $order = $this->contract();

        $ahead = $this->raise($order, '50000');
        $this->assertSame('IN_REVIEW', $ahead->status);
        $this->assertSame(['owner'], $ahead->currentApproval->levels);

        $small = $this->raise($order, '1000');
        $this->assertSame('APPROVED', $small->status, 'Within the limit it still passes on its own.');
    }

    public function test_the_approvals_screen_shows_the_figures_why_it_is_blocked_and_the_owners_field(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '20');
        $payment = $this->raise($order, '40000');
        $approval = $payment->currentApproval;

        $page = $this->actingAs($this->manager)->get(route('approvals.index'))->assertOk();
        $page->assertSee('Value of work done')->assertSee('₹20,000.00')
            ->assertSee('Paid so far')
            ->assertSee('Paid if approved')->assertSee('₹40,000.00')
            ->assertSee('Approving this payment would make it 40% paid against 20% work done, 20 points ahead', false)
            ->assertDontSee('value="approve"', false)
            ->assertDontSee('Owner override');

        $this->post(route('approvals.act', $approval), ['action' => 'approve', 'override_reason' => self::REASON])->assertSessionHasErrors('override_reason');
        $this->assertSame('IN_REVIEW', $payment->refresh()->status);

        $this->actingAs($this->owner)->get(route('approvals.index'))->assertOk()
            ->assertSee('Owner override')
            ->assertSee('Approve with override')
            ->assertSee('name="override_reason"', false);

        $this->post(route('approvals.act', $approval), ['action' => 'approve'])->assertSessionHasErrors('override_reason');
        $this->post(route('approvals.act', $approval), ['action' => 'approve', 'override_reason' => self::REASON])->assertSessionHasNoErrors();
        $this->assertSame('APPROVED', $payment->refresh()->status);

        // Kept with the payment, for the people who see its money.
        $this->actingAs($this->manager)->get(route('contractor-control.payments.show', $payment))->assertOk()
            ->assertSee('Approved ahead of the work done by the owner')
            ->assertSee(self::REASON);
        $this->actingAs($this->supervisor)->get(route('contractor-control.payments.show', $payment))->assertOk()
            ->assertDontSee(self::REASON);
    }

    public function test_the_payment_page_offers_the_same_decision(): void
    {
        $payment = $this->raise($this->contract(), '50000');

        $this->actingAs($this->manager)->get(route('contractor-control.payments.show', $payment))->assertOk()
            ->assertSee('Only the owner can approve it, with a written reason.')
            ->assertSee('value="return"', false)
            ->assertDontSee('value="approve"', false);

        $this->actingAs($this->owner)->get(route('contractor-control.payments.show', $payment))->assertOk()
            ->assertSee('name="override_reason"', false)
            ->assertSee('Approve with override');
    }

    public function test_a_payment_within_the_limit_shows_no_block(): void
    {
        $payment = $this->raise($this->contract(), '5000');

        $this->actingAs($this->manager)->get(route('approvals.index'))->assertOk()
            ->assertSee('value="approve"', false)
            ->assertDontSee('Only the owner can approve it');
        $this->assertFalse(app(PaymentAheadGuard::class)->blocks($payment));
    }

    public function test_the_app_approval_endpoint_refuses_in_its_usual_error_shape(): void
    {
        $payment = $this->raise($this->contract(), '50000');

        foreach ([$this->manager, $this->owner] as $user) {
            Sanctum::actingAs($user);

            // The app has no override field: the owner overrides on the website.
            $this->postJson('/api/v1/approvals/'.$payment->currentApproval->id.'/act', ['action' => 'approve'])
                ->assertUnprocessable()
                ->assertJsonStructure(['message', 'errors' => ['override_reason']]);
        }

        $this->assertSame('IN_REVIEW', $payment->refresh()->status);

        Sanctum::actingAs($this->manager);
        $this->postJson('/api/v1/approvals/'.$payment->currentApproval->id.'/act', ['action' => 'return', 'remark' => 'Wait for the slab to finish'])->assertOk();
        $this->assertSame('RETURNED', $payment->refresh()->status);
    }

    public function test_an_override_never_reaches_the_app_or_the_contractors_portal(): void
    {
        $order = $this->contract();
        $payment = $this->raise($order, '50000');
        $this->act($payment, 'owner', self::REASON);

        Sanctum::actingAs($this->manager);
        $shown = $this->getJson('/api/v1/contractor-payments/'.$payment->id)->assertOk();
        $this->assertSame([...self::BASE_KEYS, ...self::MONEY_KEYS], array_keys($shown->json('data')));
        $this->assertStringNotContainsString(self::REASON, $shown->getContent());

        $portal = app(ContractorPortalService::class)->payments($order->party)->sole();
        $this->assertSame(['id', 'number', 'paid_on', 'amount', 'mode', 'mode_label', 'paid_to', 'reference', 'is_advance', 'is_reversal', 'work_order_id', 'work_order_number', 'work_order_title', 'project', 'against'], array_keys($portal));
    }

    public function test_the_project_manager_cannot_lift_the_limit_to_pass_the_payment_himself(): void
    {
        $payment = $this->raise($this->contract(), '90000');
        $this->assertStringContainsString('limit of 5 points', $this->refusedFor($payment, 'pm'));

        // The board shows him the limit but no field to change it.
        $this->actingAs($this->manager)->get(route('contractor-control.board', $this->project))->assertOk()
            ->assertSee('Only the owner can change this limit')
            ->assertDontSee('name="payment_gap_alert_pct"', false);

        // Sending it anyway is refused, and nothing in the request is saved.
        $this->actingAs($this->manager)->patch(route('contractor-control.settings', $this->project), ['payment_gap_alert_pct' => '100', 'deadline_date' => '2027-03-31'])
            ->assertSessionHasErrors('payment_gap_alert_pct');
        $this->project->refresh();
        $this->assertSame('5.00', Dec::money($this->project->payment_gap_alert_pct));
        $this->assertNull($this->project->deadline_date);

        // So the payment is still refused, with the same limit.
        $this->assertStringContainsString('limit of 5 points', $this->refusedFor($payment, 'pm'));

        // Admin holds everything but is not the owner, here as at the approval.
        $admin = $this->member($this->organization, 'admin');
        $this->actingAs($admin)->patch(route('contractor-control.settings', $this->project), ['payment_gap_alert_pct' => '100'])->assertSessionHasErrors('payment_gap_alert_pct');
        $this->assertSame('5.00', Dec::money($this->project->refresh()->payment_gap_alert_pct));

        // The owner moves it; the change is in the audit log with its old value.
        $this->actingAs($this->owner)->get(route('contractor-control.board', $this->project))->assertOk()->assertSee('name="payment_gap_alert_pct"', false);
        $this->actingAs($this->owner)->patch(route('contractor-control.settings', $this->project), ['payment_gap_alert_pct' => '95'])->assertSessionHasNoErrors();
        $this->assertSame('95.00', Dec::money($this->project->refresh()->payment_gap_alert_pct));
        $log = AuditLog::where('action', 'updated')->where('auditable_type', $this->project->getMorphClass())->where('auditable_id', $this->project->id)->latest('id')->first();
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame('95.00', Dec::money($log->new_values['payment_gap_alert_pct']));
        $this->assertSame('5.00', Dec::money($log->old_values['payment_gap_alert_pct']));

        // With the owner's limit, the manager's approval passes.
        $this->act($payment, 'pm');
        $this->assertSame('APPROVED', $payment->refresh()->status);
    }

    public function test_an_approver_without_money_view_is_told_why_with_no_figure(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '20');
        $payment = $this->raise($order, '90000');
        $passer = $this->approverWithoutMoney();
        $plain = 'Approving this payment would put payment ahead of the work done on this contract, past what this project allows. Only the owner can approve it, with a written reason.';

        $this->assertSame($plain, $this->refusedFor($payment, $passer));

        $this->actingAs($passer)->get(route('approvals.index'))->assertOk()
            ->assertSee('past what this project allows')
            ->assertDontSee('90%')
            ->assertDontSee('20% work done')
            ->assertDontSee('70 points')
            ->assertDontSee('Value of work done')
            ->assertDontSee('value="approve"', false);

        // The app's approval endpoint carries the same reason, in its usual error shape.
        Sanctum::actingAs($passer);
        $reply = $this->postJson('/api/v1/approvals/'.$payment->currentApproval->id.'/act', ['action' => 'approve'])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['override_reason']])
            ->assertJsonPath('errors.override_reason.0', $plain);
        $this->assertStringNotContainsString('%', $reply->getContent());
        $this->assertStringNotContainsString('points', $reply->getContent());

        // The project manager sees money, so he still gets the figures.
        $this->assertStringContainsString('90% paid against 20% work done, 70 points ahead', $this->refusedFor($payment, 'pm'));
        $this->assertSame('IN_REVIEW', $payment->refresh()->status);
    }

    public function test_a_contract_with_no_value_cannot_be_paid_without_the_owner_and_the_alert_agrees(): void
    {
        // An item-rate work order saved with a rate but no quantity is worth nothing, so no work done on it has a value.
        $order = $this->contract(qty: '0.000');
        $this->workDone($this->item, '40');
        $this->assertSame(0, $this->aheadAlerts(), 'Nothing paid, nothing ahead.');

        $payment = $this->raise($order, '1000000');
        $this->assertTrue(app(PaymentAheadGuard::class)->blocks($payment));
        $this->assertSame(
            'This contract has no value, as its lines have no quantity, so no payment on it is covered by work done. Only the owner can approve it, with a written reason.',
            $this->refusedFor($payment, 'pm'),
        );
        $this->assertSame(0, AuditLog::where('action', 'approval.approve')->count());

        $this->act($payment, 'owner', self::REASON);
        $this->assertSame('APPROVED', $payment->refresh()->status);
        $this->assertSame(self::REASON, $payment->override_reason);

        // Once paid, the alert raises it too, worded without the percentages, which are both 0.
        $findings = iterator_to_array(app(PaymentAheadSource::class)->findings($this->project, today()), false);
        $this->assertCount(1, $findings);
        $this->assertSame(':contract (:party): paid on a contract with no value, so none of the payment is covered by work done.', $findings[0]->message);
        $this->assertSame(Alert::CRITICAL, $findings[0]->severity);

        // The app's board marks it over the limit, in the same shape as any other contract.
        $valued = $this->contract();
        Sanctum::actingAs($this->manager);
        $rows = collect($this->getJson('/api/v1/projects/'.$this->project->id.'/contractor-control')->assertOk()->json('data'))->keyBy('work_order_id');
        $this->assertTrue($rows[$order->id]['over_limit']);
        $this->assertSame('none', $rows[$order->id]['ratio_status']);
        $this->assertFalse($rows[$valued->id]['over_limit']);
        $this->assertSame(array_keys($rows[$valued->id]), array_keys($rows[$order->id]));
    }

    public function test_a_reversal_of_an_overridden_payment_carries_no_override(): void
    {
        $payment = $this->raise($this->contract(), '50000');
        $this->act($payment, 'owner', self::REASON);

        $reversal = app(ContractorPaymentService::class)->reverse($payment->refresh(), 'Paid into the wrong account by mistake.', $this->manager);

        $this->assertNull($reversal->override_reason);
        $this->assertNull($reversal->override_by);
        $this->assertNull($reversal->override_at);
        $this->assertSame(self::REASON, $payment->refresh()->override_reason, 'The original keeps its override.');
        $this->assertSame(1, ContractorPayment::whereNotNull('override_by')->count());

        $created = AuditLog::where('action', 'created')->where('auditable_type', $reversal->getMorphClass())->where('auditable_id', $reversal->id)->sole();
        $this->assertNull($created->new_values['override_reason'] ?? null);
        $this->assertNull($created->new_values['override_by'] ?? null);

        $this->actingAs($this->manager)->get(route('contractor-control.payments.show', $reversal))->assertOk()
            ->assertDontSee('Approved ahead of the work done by the owner')
            ->assertDontSee(self::REASON);
        $this->get(route('contractor-control.payments.show', $payment))->assertOk()
            ->assertSee('Approved ahead of the work done by the owner')
            ->assertSee(self::REASON);
    }

    public function test_another_company_cannot_act_on_the_payment(): void
    {
        $payment = $this->raise($this->contract(), '50000');
        $approvalId = $payment->currentApproval->id;
        $rival = $this->member($this->tenant('Rival Infra'), 'owner');

        $this->actingAs($rival)->post(route('approvals.act', $approvalId), ['action' => 'approve', 'override_reason' => self::REASON])->assertNotFound();
        Sanctum::actingAs($rival);
        $this->postJson('/api/v1/approvals/'.$approvalId.'/act', ['action' => 'approve'])->assertNotFound();

        $this->assertSame('IN_REVIEW', ContractorPayment::withoutGlobalScopes()->find($payment->id)->status);
    }
}
