<?php

namespace Tests\Feature\ContractorControl;

use App\Models\ApprovalRule;
use App\Models\ApprovalStep;
use App\Models\AuditLog;
use App\Models\ContractorPayment;
use App\Models\CustomRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The payment-ahead rule (PaymentAheadGuard) as the phone meets it: what GET /approvals says in
 * document.owner_override and document.check, and what POST /approvals/{id}/act does with override_reason.
 * The rule itself is PaymentAheadGuardTest's; this is the API in front of it.
 * The contract is 100 units at ₹1,000, so ₹1,00,000, and every ₹1,000 is one point. The project's limit is 5 points.
 */
class ContractorPaymentOverrideApiTest extends TestCase
{
    use BuildsContractorControl, RefreshDatabase;

    private const API = '/api/v1';

    private const REASON = 'The contractor has to buy steel for the next slab before the price rises on Monday.';

    private const BLOCKED = 'Approving this payment would make it 80% paid against 70% work done, 10 points ahead, beyond this project\'s limit of 5 points. Only the owner can approve it, with a written reason.';

    private const BLOCKED_NO_FIGURES = 'Approving this payment would put payment ahead of the work done on this contract, past what this project allows. Only the owner can approve it, with a written reason.';

    private const NO_VALUE = 'This contract has no value, as its lines have no quantity, so no payment on it is covered by work done. Only the owner can approve it, with a written reason.';

    private const WRITE_WHY = 'Write why this payment may go ahead of the work done, in at least one full sentence.';

    private const NOT_NEEDED = ['needed' => false, 'allowed' => false, 'message' => null];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->site();
    }

    /** Work 70%, paid 60%, and a request that would make it 80% paid: 10 points ahead, past the limit of 5. */
    private function ahead(): ContractorPayment
    {
        $order = $this->contract();
        $this->workDone($this->item, '70');
        $this->paid($order, '60000');

        return $this->raise($order, '20000');
    }

    /** The contractor payment in this person's inbox. */
    private function document(User $user): array
    {
        Sanctum::actingAs($user);
        $rows = collect($this->getJson(self::API.'/approvals')->assertOk()->json('data'))->where('doc_type', 'contractor')->values();
        $this->assertCount(1, $rows);

        return $rows[0]['document'];
    }

    private function decide(ContractorPayment $payment, User $as, array $body): TestResponse
    {
        Sanctum::actingAs($as);

        return $this->postJson(self::API.'/approvals/'.$payment->currentApproval()->withoutGlobalScopes()->first()->id.'/act', $body);
    }

    private function refused(TestResponse $response, string $message): void
    {
        $response->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['override_reason']])
            ->assertJsonPath('errors.override_reason', [$message]);
    }

    private function approverWithoutMoney(): User
    {
        $role = CustomRole::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Payment passer',
            'permissions' => ['contractor.view', 'contractor.approve'],
            'approval_role' => 'pm',
        ]);
        $user = $this->member($this->organization, 'pm');
        $user->forceFill(['custom_role_id' => $role->id])->save();
        $this->assertFalse($user->refresh()->can('money.view'));

        return $user;
    }

    public function test_a_manager_is_told_only_the_owner_can_approve_and_is_refused(): void
    {
        $payment = $this->ahead();

        $doc = $this->document($this->manager);
        $this->assertSame(['needed' => true, 'allowed' => false, 'message' => self::BLOCKED], $doc['owner_override']);
        $this->assertSame([
            'contract_value' => '100000.00',
            'work_done' => '70000.00',
            'work_pct' => '70.00',
            'paid' => '60000.00',
            'paid_pct' => '60.00',
            'paid_after' => '80000.00',
            'paid_pct_after' => '80.00',
            'gap_after' => '10.00',
            'limit_pct' => '5.00',
            'blocked' => true,
        ], $doc['check']);

        $this->refused($this->decide($payment, $this->manager, ['action' => 'approve', 'client_uuid' => (string) Str::uuid()]), self::BLOCKED);
        // A reason from anyone but the owner changes nothing.
        $this->refused($this->decide($payment, $this->manager, ['action' => 'approve', 'override_reason' => self::REASON]), self::BLOCKED);

        // Admin holds every permission and may act at any level, but is not the owner.
        $admin = $this->member($this->organization, 'admin');
        $this->assertSame(['needed' => true, 'allowed' => false, 'message' => self::BLOCKED], $this->document($admin)['owner_override']);
        $this->refused($this->decide($payment, $admin, ['action' => 'approve', 'override_reason' => self::REASON]), self::BLOCKED);

        $payment->refresh();
        $this->assertSame('IN_REVIEW', $payment->status);
        $this->assertNull($payment->override_reason);
        $this->assertSame(0, ApprovalStep::where('approval_id', $payment->currentApproval->id)->where('action', 'approve')->count());
        $this->assertSame(0, AuditLog::where('action', 'contractor_payment.owner_override')->count());

        // Sending it back or rejecting it is never stopped.
        $this->decide($payment, $this->manager, ['action' => 'return', 'remark' => 'Wait for the slab to finish'])->assertOk()->assertJsonPath('data.status', 'RETURNED');
    }

    public function test_allowed_is_user_is_owner_and_not_the_role_column_or_the_permission_list(): void
    {
        // As ahead(), but the request waits at the owner's own level, so a custom role whose role column says
        // "owner" may act on it too.
        $order = $this->contract();
        $this->workDone($this->item, '70');
        $this->paid($order, '60000');
        ApprovalRule::create(['organization_id' => $this->organization->id, 'doc_type' => 'contractor', 'levels' => ['owner'], 'active' => true]);
        $payment = $this->raise($order, '20000');
        $this->assertSame(['owner'], $payment->currentApproval->levels);

        $role = CustomRole::withoutGlobalScopes()->create(['organization_id' => $this->organization->id, 'name' => 'Partner', 'permissions' => ['*']]);
        $partner = $this->member($this->organization, 'owner');
        $partner->forceFill(['custom_role_id' => $role->id])->save();
        $partner->refresh();
        $this->assertSame('owner', $partner->role);
        $this->assertTrue($partner->can('contractor.approve'));
        $this->assertFalse($partner->isOwner());

        $this->assertSame(['needed' => true, 'allowed' => false, 'message' => self::BLOCKED], $this->document($partner)['owner_override']);
        $this->refused($this->decide($payment, $partner, ['action' => 'approve', 'override_reason' => self::REASON]), self::BLOCKED);
        $this->assertSame('IN_REVIEW', $payment->refresh()->status);
        $this->assertNull($payment->override_reason);

        $this->assertSame(['needed' => true, 'allowed' => true, 'message' => self::BLOCKED], $this->document($this->owner)['owner_override']);
    }

    public function test_the_owner_overrides_from_the_phone_with_a_written_reason_kept_and_audited(): void
    {
        $payment = $this->ahead();

        $this->assertSame(['needed' => true, 'allowed' => true, 'message' => self::BLOCKED], $this->document($this->owner)['owner_override']);

        $body = ['action' => 'approve', 'override_reason' => '  '.self::REASON.'  ', 'client_uuid' => (string) Str::uuid()];
        $first = $this->decide($payment, $this->owner, $body)->assertOk()
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.document.status', 'APPROVED')
            // Decided, so the rule has nothing more to ask.
            ->assertJsonPath('data.document.owner_override', self::NOT_NEEDED)
            ->assertJsonPath('data.document.check', null)
            ->json('data');

        $payment->refresh();
        $this->assertSame('APPROVED', $payment->status);
        $this->assertSame(self::REASON, $payment->override_reason);
        $this->assertSame($this->owner->id, $payment->override_by);
        $this->assertNotNull($payment->override_at);

        $audit = AuditLog::where('action', 'contractor_payment.owner_override')->get();
        $this->assertCount(1, $audit);
        $this->assertSame($this->owner->id, $audit[0]->user_id);
        $this->assertSame(self::REASON, $audit[0]->new_values['reason']);
        $this->assertSame('80000.00', $audit[0]->new_values['paid_after']);

        // The phone resends after a lost reply: the same answer, and nothing written twice.
        $this->assertSame($first, $this->decide($payment, $this->owner, $body)->assertOk()->json('data'));
        $this->assertSame(1, AuditLog::where('action', 'contractor_payment.owner_override')->count());
        $this->assertSame(1, ApprovalStep::where('approval_id', $first['id'])->where('action', 'approve')->count());
    }

    public function test_the_owner_is_refused_without_a_full_sentence_of_reason(): void
    {
        $payment = $this->ahead();

        // Nothing, a word, four words under twenty characters, and twenty-five characters in one word.
        foreach ([null, '', '   ', 'Urgent', 'Pay it now ok', str_repeat('a', 25)] as $reason) {
            $this->refused($this->decide($payment, $this->owner, ['action' => 'approve', 'override_reason' => $reason]), self::WRITE_WHY);
        }
        $this->refused($this->decide($payment, $this->owner, ['action' => 'approve']), self::WRITE_WHY);

        // Longer than the column: refused by the request's own rule, on the same field.
        $this->decide($payment, $this->owner, ['action' => 'approve', 'override_reason' => str_repeat('too long ', 120)])
            ->assertUnprocessable()->assertJsonValidationErrors('override_reason');

        $payment->refresh();
        $this->assertSame('IN_REVIEW', $payment->status);
        $this->assertNull($payment->override_reason);
        $this->assertSame(0, AuditLog::where('action', 'contractor_payment.owner_override')->count());

        // The same request with a sentence goes through, so a refusal leaves nothing behind that blocks the retry.
        $uuid = (string) Str::uuid();
        $this->refused($this->decide($payment, $this->owner, ['action' => 'approve', 'override_reason' => 'Urgent', 'client_uuid' => $uuid]), self::WRITE_WHY);
        $this->decide($payment, $this->owner, ['action' => 'approve', 'override_reason' => self::REASON, 'client_uuid' => $uuid])->assertOk()->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_an_approver_without_money_view_is_told_why_with_no_figure(): void
    {
        $payment = $this->ahead();
        $passer = $this->approverWithoutMoney();

        Sanctum::actingAs($passer);
        $inbox = $this->getJson(self::API.'/approvals')->assertOk();
        $doc = collect($inbox->json('data'))->firstWhere('doc_type', 'contractor')['document'];

        $this->assertSame(['needed' => true, 'allowed' => false, 'message' => self::BLOCKED_NO_FIGURES], $doc['owner_override']);
        $this->assertArrayHasKey('check', $doc);
        $this->assertNull($doc['check']);
        $this->assertNull($doc['impact']);
        $this->assertNull($doc['paid_pct']);
        foreach (['80%', '10 points', '5 points', '60000', '80000', '20000', 'work_done', 'paid_after', 'gap_after'] as $leak) {
            $this->assertStringNotContainsString($leak, $inbox->getContent(), "{$leak} reached someone without money.view.");
        }

        $reply = $this->decide($payment, $passer, ['action' => 'approve']);
        $this->refused($reply, self::BLOCKED_NO_FIGURES);
        $this->assertStringNotContainsString('%', $reply->getContent());
        $this->assertStringNotContainsString('points', $reply->getContent());
        $this->assertSame('IN_REVIEW', $payment->refresh()->status);
    }

    public function test_a_contract_with_no_value_says_so_and_only_the_owner_passes_it(): void
    {
        // Lines with a rate and no quantity: the contract is worth nothing, so no work on it covers a payment.
        $order = $this->contract(qty: '0.000');
        $this->workDone($this->item, '40');
        $payment = $this->raise($order, '25000');

        $doc = $this->document($this->manager);
        $this->assertSame(['needed' => true, 'allowed' => false, 'message' => self::NO_VALUE], $doc['owner_override']);
        $this->assertSame('0.00', $doc['check']['contract_value']);
        $this->assertSame('0.00', $doc['check']['work_done']);
        $this->assertSame('25000.00', $doc['check']['paid_after']);
        $this->assertTrue($doc['check']['blocked']);
        // Both percentages are 0 here, so the app cannot read "ahead" from them: over_limit is what says it.
        $this->assertSame('none', $doc['impact']['after']['ratio_status']);
        $this->assertTrue($doc['impact']['after']['over_limit']);
        $this->assertSame('0.00', $doc['impact']['after']['paid_pct']);

        // Without money.view the reason is the plain one, as for any other contract.
        $this->assertSame(self::BLOCKED_NO_FIGURES, $this->document($this->approverWithoutMoney())['owner_override']['message']);

        $this->refused($this->decide($payment, $this->manager, ['action' => 'approve']), self::NO_VALUE);
        $this->assertSame(['needed' => true, 'allowed' => true, 'message' => self::NO_VALUE], $this->document($this->owner)['owner_override']);
        $this->decide($payment, $this->owner, ['action' => 'approve', 'override_reason' => self::REASON])->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->assertSame(self::REASON, $payment->refresh()->override_reason);
    }

    public function test_within_the_limit_nothing_is_asked_and_a_reason_sent_anyway_is_not_kept(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '70');
        $this->paid($order, '60000');
        // 75% paid against 70% work: 5 points ahead, which is at the limit and not past it.
        $payment = $this->raise($order, '15000');

        foreach ([$this->manager, $this->owner] as $user) {
            $doc = $this->document($user);
            $this->assertSame(self::NOT_NEEDED, $doc['owner_override']);
            $this->assertFalse($doc['check']['blocked']);
            $this->assertSame('5.00', $doc['check']['gap_after']);
        }

        $this->decide($payment, $this->manager, ['action' => 'approve', 'override_reason' => self::REASON])->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $this->assertNull($payment->refresh()->override_reason);
        $this->assertSame(0, AuditLog::where('action', 'contractor_payment.owner_override')->count());
    }

    public function test_before_the_last_level_the_approver_is_warned_and_not_stopped(): void
    {
        ApprovalRule::create(['organization_id' => $this->organization->id, 'doc_type' => 'contractor', 'levels' => ['pm', 'owner'], 'active' => true]);
        $order = $this->contract();
        $this->workDone($this->item, '20');
        $payment = $this->raise($order, '50000');
        $this->assertSame(['pm', 'owner'], $payment->currentApproval->levels);

        $doc = $this->document($this->manager);
        $this->assertFalse($doc['owner_override']['needed']);
        $this->assertFalse($doc['owner_override']['allowed']);
        $this->assertStringStartsWith('As it stands, the last approval of this payment will need the owner', $doc['owner_override']['message']);
        $this->assertTrue($doc['check']['blocked']);

        // The manager's level only moves it on.
        $this->decide($payment, $this->manager, ['action' => 'approve'])->assertOk()->assertJsonPath('data.current_level', 2)->assertJsonPath('data.status', 'IN_REVIEW');

        // The owner's level is the one that makes it count, so that is where the reason is asked.
        $this->assertTrue($this->document($this->owner)['owner_override']['needed']);
        $this->assertTrue($this->document($this->owner)['owner_override']['allowed']);
        $this->decide($payment, $this->owner, ['action' => 'approve'])->assertUnprocessable()->assertJsonValidationErrors('override_reason');
        $this->decide($payment, $this->owner, ['action' => 'approve', 'override_reason' => self::REASON])->assertOk()->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_another_companys_owner_cannot_override_it(): void
    {
        $payment = $this->ahead();
        $approvalId = $payment->currentApproval->id;
        $outsider = $this->member($this->tenant('Other Co'), 'owner');

        Sanctum::actingAs($outsider);
        $this->getJson(self::API.'/approvals')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(self::API."/approvals/{$approvalId}/act", ['action' => 'approve', 'override_reason' => self::REASON])->assertNotFound();

        $payment = ContractorPayment::withoutGlobalScopes()->findOrFail($payment->id);
        $this->assertSame('IN_REVIEW', $payment->status);
        $this->assertNull($payment->override_reason);
    }
}
