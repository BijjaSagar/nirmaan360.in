<?php

namespace Tests\Feature\BillingExtras;

use App\Models\BillNote;
use App\Models\RaBill;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ApprovalService;
use App\Services\BillingExtras\BillNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Permission, tenant isolation and rendering for the billing screens. */
class BillingExtrasRoutesTest extends TestCase
{
    use BuildsContracts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function billed(): array
    {
        $contract = $this->clientContract();
        $this->measure($contract, '50');

        return [$contract, $this->approvedClientBill($contract)];
    }

    private function note(RaBill $bill, object $contract): BillNote
    {
        return app(BillNoteService::class)->create($bill, ['kind' => 'credit', 'note_date' => '2026-11-05', 'reason' => 'Rate correction', 'taxable_amount' => '10000'], $contract->owner);
    }

    private function noteForm(array $overrides = []): array
    {
        return ['kind' => 'credit', 'note_date' => '2026-11-05', 'reason' => 'Rate correction', 'taxable_amount' => '10000', 'client_uuid' => (string) Str::uuid(), ...$overrides];
    }

    private function outsider(): User
    {
        return $this->member($this->tenant('Other Co'), 'owner');
    }

    public function test_a_note_goes_from_the_bill_page_through_approval_to_a_pdf(): void
    {
        [$contract, $bill] = $this->billed();
        $qs = $this->member($contract->organization, 'qs');
        $this->actingAs($qs);

        $this->get(route('ra-bills.show', $bill))->assertOk()->assertSee('Credit and debit notes')->assertSee(route('bill-notes.create', $bill));
        $this->get(route('bill-notes.create', $bill))->assertOk()->assertSee('₹2,52,500.00');
        $this->post(route('bill-notes.store', $bill), $this->noteForm())->assertSessionHasNoErrors();

        $note = BillNote::sole();
        $this->assertSame('11800.00', $note->total_amount);

        $this->get(route('bill-notes.show', $note))->assertOk()->assertSee('Credit note')->assertSee('₹11,800.00')->assertSee('Lowers what the client owes us');
        $this->post(route('bill-notes.submit', $note))->assertSessionHasNoErrors();
        $this->assertSame('IN_REVIEW', $note->refresh()->status);

        $this->actingAs($contract->owner);
        $this->get(route('approvals.index'))->assertOk()->assertSee($note->number)->assertSee(route('bill-notes.show', $note));

        $approval = $note->currentApproval;
        while ($approval->status === 'IN_REVIEW') {
            $this->post(route('approvals.act', $approval), ['action' => 'approve'])->assertSessionHasNoErrors();
            $approval = $approval->refresh();
        }

        $this->assertSame('APPROVED', $note->refresh()->status);
        $this->get(route('ra-bills.show', $bill))->assertOk()->assertSee('₹2,40,700.00');
        $this->get(route('bill-notes.pdf', $note))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_an_overdrawing_note_comes_back_as_a_form_error(): void
    {
        [$contract, $bill] = $this->billed();
        $this->actingAs($contract->owner);

        $this->post(route('bill-notes.store', $bill), $this->noteForm(['taxable_amount' => '9999999']))->assertSessionHasErrors('taxable_amount');
        $this->assertSame(0, BillNote::count());
    }

    public function test_note_amounts_must_be_positive_to_the_paisa(): void
    {
        [$contract, $bill] = $this->billed();
        $this->actingAs($contract->owner);

        $this->post(route('bill-notes.store', $bill), $this->noteForm(['taxable_amount' => '0']))->assertSessionHasErrors('taxable_amount');
        $this->post(route('bill-notes.store', $bill), $this->noteForm(['taxable_amount' => '10.555']))->assertSessionHasErrors('taxable_amount');
        $this->post(route('bill-notes.store', $bill), $this->noteForm(['kind' => 'refund']))->assertSessionHasErrors('kind');
    }

    public function test_the_note_form_is_refused_on_a_draft_bill(): void
    {
        $contract = $this->clientContract();
        $this->measure($contract, '50');
        $draft = $this->clientBill($contract);
        $this->actingAs($contract->owner);

        $this->get(route('bill-notes.create', $draft))->assertStatus(409);
    }

    public function test_note_routes_need_their_permission(): void
    {
        [$contract, $bill] = $this->billed();
        $note = $this->note($bill, $contract);

        // Site engineers have no billing permissions.
        $this->actingAs($contract->engineer);
        $this->get(route('bill-notes.show', $note))->assertForbidden();
        $this->get(route('bill-notes.pdf', $note))->assertForbidden();
        $this->get(route('bill-notes.create', $bill))->assertForbidden();
        $this->post(route('bill-notes.store', $bill), $this->noteForm())->assertForbidden();

        // Viewers can read but not raise.
        $this->actingAs($this->member($contract->organization, 'viewer'));
        $this->get(route('bill-notes.show', $note))->assertOk();
        $this->post(route('bill-notes.store', $bill), $this->noteForm())->assertForbidden();
        $this->post(route('bill-notes.submit', $note))->assertForbidden();
        $this->post(route('bill-notes.cancel', $note))->assertForbidden();

        $this->assertSame('DRAFT', $note->refresh()->status);
    }

    public function test_only_roles_with_the_approve_permission_can_approve_a_note(): void
    {
        [$contract, $bill] = $this->billed();
        $note = $this->note($bill, $contract);
        $approval = app(BillNoteService::class)->submit($note, $contract->owner);

        $this->assertFalse(app(ApprovalService::class)->canAct($approval, $contract->engineer));
        $this->assertTrue(app(ApprovalService::class)->canAct($approval, $this->member($contract->organization, 'qs')));
    }

    public function test_another_companys_notes_are_a_404(): void
    {
        [$contract, $bill] = $this->billed();
        $note = $this->note($bill, $contract);
        $this->actingAs($this->outsider());

        $this->get(route('bill-notes.show', $note))->assertNotFound();
        $this->get(route('bill-notes.pdf', $note))->assertNotFound();
        $this->get(route('bill-notes.create', $bill))->assertNotFound();
        $this->post(route('bill-notes.store', $bill), $this->noteForm())->assertNotFound();
        $this->post(route('bill-notes.submit', $note))->assertNotFound();
        $this->post(route('bill-notes.cancel', $note))->assertNotFound();

        $this->assertSame('DRAFT', $note->refresh()->status);
    }

    public function test_a_note_is_cancelled_from_its_page(): void
    {
        [$contract, $bill] = $this->billed();
        $note = $this->note($bill, $contract);
        $this->actingAs($contract->owner);

        $this->post(route('bill-notes.cancel', $note))->assertSessionHasNoErrors();

        $this->assertSame('CANCELLED', $note->refresh()->status);
    }

    public function test_the_position_page_lists_each_contract_and_notes(): void
    {
        [$contract, $bill] = $this->billed();
        $workOrder = $this->workOrder($contract);
        $note = $this->note($bill, $contract);
        $this->actingAs($contract->owner);

        $this->get(route('billing-extras.position', $contract->project))->assertOk()
            ->assertSee('Client contract')
            ->assertSee($workOrder->number)
            ->assertSee('Shree Formwork')
            ->assertSee('2,52,500.00')
            ->assertSee('Prepare final bill')
            ->assertSee($note->number);
    }

    public function test_the_position_page_is_in_the_project_menu(): void
    {
        [$contract] = $this->billed();
        $this->actingAs($contract->owner);

        $this->get(route('ra-bills.index', $contract->project))->assertOk()->assertSee(route('billing-extras.position', $contract->project));
    }

    public function test_the_menu_and_page_are_kept_from_plans_without_billing(): void
    {
        $organization = $this->tenant('Small Co', 'free');
        $owner = $this->member($organization, 'owner');
        $project = $this->makeProject($organization);
        $this->actingAs($owner);

        $this->get(route('issues.index', $project))->assertOk()->assertDontSee(route('billing-extras.position', $project));
        $this->get(route('billing-extras.position', $project))->assertForbidden();
    }

    public function test_position_and_final_bill_routes_need_their_permission(): void
    {
        [$contract] = $this->billed();
        $project = $contract->project;

        $this->actingAs($contract->engineer);
        $this->get(route('billing-extras.position', $project))->assertForbidden();
        $this->get(route('billing-extras.final-bills.create', $project))->assertForbidden();
        $this->post(route('billing-extras.final-bills.store', $project), ['type' => 'client', 'period_from' => '2026-11-01', 'period_to' => '2026-11-30'])->assertForbidden();

        $this->actingAs($this->member($contract->organization, 'viewer'));
        $this->get(route('billing-extras.position', $project))->assertOk();
        $this->get(route('billing-extras.final-bills.create', $project))->assertForbidden();
    }

    public function test_another_companys_position_and_final_bills_are_a_404(): void
    {
        [$contract] = $this->billed();
        $project = $contract->project;
        $this->actingAs($this->outsider());

        $this->get(route('billing-extras.position', $project))->assertNotFound();
        $this->get(route('billing-extras.final-bills.create', $project))->assertNotFound();
        $this->post(route('billing-extras.final-bills.store', $project), ['type' => 'client', 'period_from' => '2026-11-01', 'period_to' => '2026-11-30'])->assertNotFound();
    }

    public function test_a_work_order_from_another_project_is_rejected(): void
    {
        [$contract] = $this->billed();
        $elsewhere = (object) [...(array) $contract, 'project' => $this->makeProject($contract->organization)];
        $elsewhere->item = $this->makeBoqItem($elsewhere->project);
        $foreign = $this->workOrder($elsewhere);
        $this->actingAs($contract->owner);

        $this->get(route('billing-extras.final-bills.create', [$contract->project, 'work_order_id' => $foreign->id]))->assertNotFound();
        $this->post(route('billing-extras.final-bills.store', $contract->project), ['type' => 'subcontractor', 'work_order_id' => $foreign->id, 'period_from' => '2026-11-01', 'period_to' => '2026-11-30'])
            ->assertSessionHasErrors('work_order_id');
    }

    public function test_a_final_bill_is_prepared_shown_and_printed(): void
    {
        [$contract] = $this->billed();
        $this->actingAs($contract->owner);

        $this->get(route('billing-extras.final-bills.create', $contract->project))->assertOk()->assertSee('₹12,500.00')->assertSee('₹75,000.00');

        $this->post(route('billing-extras.final-bills.store', $contract->project), ['type' => 'client', 'period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'gst' => 'with'])
            ->assertSessionHasNoErrors();

        $final = RaBill::where('is_final', true)->sole();
        // 12,500 released − 75,000 advance recovered = −62,500.
        $this->assertSame('-62500.00', $final->net_payable);

        $this->get(route('ra-bills.show', $final))->assertOk()->assertSee('Final bill')->assertSee('Retention released')->assertSee('recoverable from the party');
        $this->get(route('ra-bills.pdf', $final))->assertOk();
        $this->get(route('billing-extras.position', $contract->project))->assertOk()->assertSee($final->number);
    }

    public function test_a_further_bill_on_a_closed_contract_comes_back_as_a_form_error(): void
    {
        [$contract] = $this->billed();
        $this->approveBill($this->finalClientBill($contract), $contract->owner);
        $this->measure($contract, '5');
        $this->actingAs($contract->owner);

        $this->post(route('ra-bills.store', $contract->project), ['type' => 'client', 'period_from' => '2026-11-01', 'period_to' => '2026-11-30'])
            ->assertSessionHasErrors('type');
        $this->assertSame(2, RaBill::count());
    }

    public function test_the_client_sign_off_page_shows_the_retention_release(): void
    {
        [$contract] = $this->billed();
        $final = $this->finalClientBill($contract);

        $this->get(URL::temporarySignedRoute('client.bill', now()->addDay(), ['bill' => $final->id]))->assertOk()->assertSee('Retention released')->assertSee('₹12,500.00');
    }

    public function test_closed_work_orders_leave_the_billing_and_measurement_forms(): void
    {
        [$contract] = $this->billed();
        $open = $this->workOrder($contract);
        $closed = $this->workOrder($contract);
        WorkOrder::whereKey($closed->id)->update(['status' => 'CLOSED']);
        $this->actingAs($contract->owner);

        $this->get(route('ra-bills.create', $contract->project))->assertOk()->assertSee($open->number)->assertDontSee($closed->number);
        $this->get(route('measurements.create', $contract->project))->assertOk()->assertSee($open->number)->assertDontSee($closed->number);
    }

    public function test_the_work_order_page_shows_its_rate_type(): void
    {
        [$contract] = $this->billed();
        $workOrder = $this->workOrder($contract, ['rate_type' => 'labour_only']);
        $this->actingAs($contract->owner);

        $this->get(route('work-orders.show', $workOrder))->assertOk()->assertSee('Labour only');
    }
}
