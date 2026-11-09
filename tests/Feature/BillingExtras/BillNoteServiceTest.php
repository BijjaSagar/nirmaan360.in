<?php

namespace Tests\Feature\BillingExtras;

use App\Exceptions\ImmutableRecordException;
use App\Models\ApprovalRule;
use App\Models\BillNote;
use App\Models\RaBill;
use App\Services\ApprovalService;
use App\Services\BillingExtras\BillNoteService;
use App\Services\BillingExtras\ContractPositionService;
use App\Services\RaBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * FR-BIL-03: credit and debit notes.
 *
 * The client bill used throughout is 50 cum × 5,000 = 2,50,000 gross. Retention 12,500,
 * advance 25,000, TDS 5,000, GST 45,000, so net payable is 2,52,500.
 */
class BillNoteServiceTest extends TestCase
{
    use BuildsContracts, RefreshDatabase;

    private function service(): BillNoteService
    {
        return app(BillNoteService::class);
    }

    private function raise(RaBill $bill, object $contract, string $kind, string $amount, array $overrides = []): BillNote
    {
        return $this->service()->create($bill, ['kind' => $kind, 'note_date' => '2026-11-05', 'reason' => 'Rate correction', 'taxable_amount' => $amount, ...$overrides], $contract->owner);
    }

    private function approve(BillNote $note, object $contract): BillNote
    {
        $approval = $this->service()->submit($note, $contract->owner);

        while ($approval && $approval->status === 'IN_REVIEW') {
            $approval = app(ApprovalService::class)->act($approval, $contract->owner, 'approve');
        }

        return $note->refresh();
    }

    private function billedClient(): array
    {
        $contract = $this->clientContract();
        $this->measure($contract, '50');

        return [$contract, $this->approvedClientBill($contract)];
    }

    private function billedSubcontractor(array $terms = []): array
    {
        $contract = $this->clientContract();
        $workOrder = $this->workOrder($contract, $terms);
        $this->measure($contract, '50', $workOrder);

        return [$contract, $workOrder, $this->approveBill($this->subBill($contract, $workOrder), $contract->owner)];
    }

    public function test_a_credit_note_lowers_what_the_client_owes(): void
    {
        [$contract, $bill] = $this->billedClient();

        $note = $this->approve($this->raise($bill, $contract, 'credit', '10000'), $contract);

        // 10,000 + 18% GST 1,800 = 11,800. 2,52,500 − 11,800 = 2,40,700.
        $this->assertSame('10000.00', $note->taxable_amount);
        $this->assertSame('18.00', $note->gst_pct);
        $this->assertSame('1800.00', $note->gst_amount);
        $this->assertSame('11800.00', $note->total_amount);
        $this->assertSame('-11800.00', $note->net_effect);
        $this->assertSame('240700.00', $bill->adjustedNet());
    }

    public function test_a_debit_note_raises_what_the_client_owes(): void
    {
        [$contract, $bill] = $this->billedClient();

        $note = $this->approve($this->raise($bill, $contract, 'debit', '2500.50'), $contract);

        // 2,500.50 + GST 450.09 = 2,950.59. 2,52,500 + 2,950.59 = 2,55,450.59.
        $this->assertSame('450.09', $note->gst_amount);
        $this->assertSame('2950.59', $note->net_effect);
        $this->assertSame('255450.59', $bill->adjustedNet());
    }

    public function test_a_debit_note_lowers_what_we_owe_a_subcontractor(): void
    {
        [$contract, , $bill] = $this->billedSubcontractor(['gst_pct' => '18']);

        // 50 × 1,200 = 60,000. Retention 6,000, TDS 600, GST 10,800. Net 64,200.
        $this->assertSame('64200.00', $bill->net_payable);

        $note = $this->approve($this->raise($bill, $contract, 'debit', '5000'), $contract);

        // 5,000 + GST 900 = 5,900 back-charged. 64,200 − 5,900 = 58,300.
        $this->assertSame('-5900.00', $note->net_effect);
        $this->assertSame('58300.00', $bill->adjustedNet());
    }

    public function test_a_credit_note_raises_what_we_owe_a_subcontractor(): void
    {
        [$contract, , $bill] = $this->billedSubcontractor();

        $note = $this->approve($this->raise($bill, $contract, 'credit', '1000'), $contract);

        // The bill has no GST. 53,400 + 1,000 = 54,400.
        $this->assertSame('0.00', $note->gst_amount);
        $this->assertSame('1000.00', $note->net_effect);
        $this->assertSame('54400.00', $bill->adjustedNet());
    }

    public function test_gst_is_rounded_half_up_to_the_paisa(): void
    {
        [$contract, $bill] = $this->billedClient();

        $note = $this->raise($bill, $contract, 'credit', '1333.33');

        // 1,333.33 × 18% = 239.9994, rounded to 240.00.
        $this->assertSame('240.00', $note->gst_amount);
        $this->assertSame('1573.33', $note->total_amount);
    }

    public function test_a_bill_raised_without_gst_gets_notes_without_gst(): void
    {
        $contract = $this->clientContract();
        $this->measure($contract, '50');
        $bill = $this->approveBill(app(RaBillService::class)->generate($contract->project, 'client', '2026-11-01', '2026-11-30', $contract->owner, withGst: false), $contract->owner);

        $note = $this->raise($bill, $contract, 'credit', '1000');

        $this->assertSame('0.00', $note->gst_pct);
        $this->assertSame('1000.00', $note->total_amount);
    }

    public function test_a_note_counts_only_once_approved(): void
    {
        [$contract, $bill] = $this->billedClient();

        $note = $this->raise($bill, $contract, 'credit', '10000');
        $this->assertSame('252500.00', $bill->adjustedNet());

        $this->approve($note, $contract);
        $this->assertSame('240700.00', $bill->adjustedNet());
    }

    public function test_only_approved_or_paid_bills_take_notes(): void
    {
        $contract = $this->clientContract();
        $this->measure($contract, '50');
        $draft = $this->clientBill($contract);

        $this->expectException(ValidationException::class);
        $this->raise($draft, $contract, 'credit', '100');
    }

    public function test_a_reduction_can_take_the_bill_to_exactly_zero_but_no_further(): void
    {
        [$contract, $bill] = $this->billedClient();

        // 2,13,983.05 + GST 38,516.95 = 2,52,500.00, the whole net payable.
        $note = $this->raise($bill, $contract, 'credit', '213983.05');
        $this->assertSame('252500.00', $note->total_amount);

        try {
            $this->raise($bill, $contract, 'credit', '0.01');
            $this->fail('The bill was reduced below zero.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('taxable_amount', $exception->errors());
        }
    }

    public function test_a_pending_reduction_reserves_its_room_until_rejected(): void
    {
        [$contract, $bill] = $this->billedClient();

        // 2,00,000 + GST 36,000 = 2,36,000, leaving 16,500 of room.
        $first = $this->raise($bill, $contract, 'credit', '200000');
        $this->assertSame('16500.00', $this->service()->reducibleAmount($bill));

        // 20,000 + GST 3,600 = 23,600 would overdraw the bill while the first is still pending.
        try {
            $this->raise($bill, $contract, 'credit', '20000');
            $this->fail('Two pending reductions overdrew the bill.');
        } catch (ValidationException) {
            $this->assertSame(1, BillNote::count());
        }

        $approval = $this->service()->submit($first, $contract->owner);
        app(ApprovalService::class)->act($approval, $contract->owner, 'reject', 'Wrong rate');

        $this->assertSame('252500.00', $this->service()->reducibleAmount($bill));
        $this->assertSame('DRAFT', $this->raise($bill, $contract, 'credit', '20000')->status);
    }

    public function test_an_approved_increase_adds_room_but_a_pending_one_does_not(): void
    {
        [$contract, $bill] = $this->billedClient();

        $debit = $this->raise($bill, $contract, 'debit', '1000');
        $this->assertSame('252500.00', $this->service()->reducibleAmount($bill));

        $this->approve($debit, $contract);
        // 2,52,500 + 1,180 = 2,53,680.
        $this->assertSame('253680.00', $this->service()->reducibleAmount($bill));
    }

    public function test_notes_follow_the_companys_bill_approvers_without_the_client_level(): void
    {
        [$contract, $bill] = $this->billedClient();
        ApprovalRule::where('organization_id', $contract->organization->id)->where('doc_type', 'ra_bill')->update(['levels' => ['qs', 'accounts', 'client']]);

        $note = $this->raise($bill, $contract, 'credit', '1000');
        $approval = $this->service()->submit($note, $contract->engineer);

        $this->assertSame(['qs', 'accounts'], $approval->levels);
        $this->assertSame('bill_note', $approval->doc_type);
        $this->assertSame('IN_REVIEW', $note->refresh()->status);

        $approvals = app(ApprovalService::class);
        $approvals->act($approval, $this->member($contract->organization, 'qs'), 'approve');
        $approval = $approvals->act($approval->refresh(), $this->member($contract->organization, 'accounts'), 'approve');

        $this->assertSame('APPROVED', $approval->status);
        $this->assertSame('APPROVED', $note->refresh()->status);
        $this->assertNotNull($note->approved_at);
    }

    public function test_a_company_with_no_bill_approvers_approves_notes_at_once(): void
    {
        [$contract, $bill] = $this->billedClient();
        ApprovalRule::where('organization_id', $contract->organization->id)->where('doc_type', 'ra_bill')->update(['levels' => []]);

        $note = $this->raise($bill, $contract, 'credit', '1000');

        $this->assertNull($this->service()->submit($note, $contract->owner));
        $this->assertSame('APPROVED', $note->refresh()->status);
    }

    public function test_a_returned_note_can_be_resubmitted(): void
    {
        [$contract, $bill] = $this->billedClient();
        $note = $this->raise($bill, $contract, 'credit', '1000');
        $approval = $this->service()->submit($note, $contract->owner);

        app(ApprovalService::class)->act($approval, $contract->owner, 'return', 'Add the reference');
        $this->assertSame('RETURNED', $note->refresh()->status);

        $this->assertSame('IN_REVIEW', tap($note, fn ($n) => $this->service()->submit($n, $contract->owner))->refresh()->status);
    }

    public function test_cancelling_frees_the_bill_and_writes_the_audit_log(): void
    {
        [$contract, $bill] = $this->billedClient();
        $note = $this->raise($bill, $contract, 'credit', '200000');

        $this->service()->cancel($note);

        $this->assertSame('CANCELLED', $note->refresh()->status);
        $this->assertSame('252500.00', $this->service()->reducibleAmount($bill));
        $this->assertDatabaseHas('audit_logs', ['action' => 'bill_note.cancelled', 'auditable_id' => $note->id]);
    }

    public function test_an_approved_note_cannot_be_changed_cancelled_or_deleted(): void
    {
        [$contract, $bill] = $this->billedClient();
        $note = $this->approve($this->raise($bill, $contract, 'credit', '1000'), $contract);

        try {
            $this->service()->cancel($note);
            $this->fail('An approved note was cancelled.');
        } catch (ValidationException) {
            $this->assertSame('APPROVED', $note->refresh()->status);
        }

        try {
            $note->update(['taxable_amount' => '1.00']);
            $this->fail('An approved note was edited.');
        } catch (ImmutableRecordException) {
            $this->assertSame('1000.00', $note->refresh()->taxable_amount);
        }

        $this->expectException(ImmutableRecordException::class);
        $note->delete();
    }

    public function test_a_repeated_client_uuid_returns_the_original_note(): void
    {
        [$contract, $bill] = $this->billedClient();
        $uuid = (string) Str::uuid();

        $first = $this->raise($bill, $contract, 'credit', '1000', ['client_uuid' => $uuid]);
        $second = $this->raise($bill, $contract, 'credit', '1000', ['client_uuid' => $uuid]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BillNote::count());
    }

    public function test_credit_and_debit_notes_are_numbered_separately_per_project(): void
    {
        [$contract, $bill] = $this->billedClient();
        $code = $contract->project->code;

        $this->assertSame("CN-{$code}-001", $this->raise($bill, $contract, 'credit', '100')->number);
        $this->assertSame("CN-{$code}-002", $this->raise($bill, $contract, 'credit', '100')->number);
        $this->assertSame("DN-{$code}-001", $this->raise($bill, $contract, 'debit', '100')->number);
    }

    public function test_the_position_shows_billed_notes_paid_and_outstanding(): void
    {
        [$contract, $bill] = $this->billedClient();
        $this->approve($this->raise($bill, $contract, 'credit', '10000'), $contract);

        $position = app(ContractPositionService::class)->forContract($contract->project, 'client');

        // 2,52,500 − 11,800 = 2,40,700. Retention 12,500 held. Advance 1,00,000 − 25,000 = 75,000 left.
        $this->assertSame('252500.00', $position['billed']);
        $this->assertSame('-11800.00', $position['notes']);
        $this->assertSame('240700.00', $position['adjusted']);
        $this->assertSame('0.00', $position['paid']);
        $this->assertSame('240700.00', $position['outstanding']);
        $this->assertSame('12500.00', $position['retention_held']);
        $this->assertSame('75000.00', $position['advance_outstanding']);
        $this->assertFalse($position['closed']);
    }

    public function test_payment_records_the_net_after_notes_and_a_later_note_reopens_the_balance(): void
    {
        [$contract, $bill] = $this->billedClient();
        $this->approve($this->raise($bill, $contract, 'credit', '10000'), $contract);

        app(RaBillService::class)->markPaid($bill->refresh());
        $this->assertSame('240700.00', $bill->refresh()->paid_amount);
        $this->assertSame('0.00', app(ContractPositionService::class)->forContract($contract->project, 'client')['outstanding']);

        // A debit note after payment: 2,500.50 + GST 450.09 = 2,950.59 now due.
        $this->approve($this->raise($bill, $contract, 'debit', '2500.50'), $contract);
        $position = app(ContractPositionService::class)->forContract($contract->project, 'client');
        $this->assertSame('240700.00', $position['paid']);
        $this->assertSame('243650.59', $position['adjusted']);
        $this->assertSame('2950.59', $position['outstanding']);

        // A larger credit note after payment leaves the client in credit: 2,950.59 − (3,500 + 630) = −1,179.41.
        $this->approve($this->raise($bill, $contract, 'credit', '3500'), $contract);
        $this->assertSame('-1179.41', app(ContractPositionService::class)->forContract($contract->project, 'client')['outstanding']);
    }

    public function test_a_bill_paid_before_amounts_were_recorded_counts_at_its_net_payable(): void
    {
        [$contract, $bill] = $this->billedClient();
        RaBill::whereKey($bill->id)->update(['status' => 'PAID', 'paid_amount' => null]);

        $this->assertSame('252500.00', app(ContractPositionService::class)->forContract($contract->project, 'client')['paid']);
    }

    public function test_the_subcontractor_position_moves_the_right_way(): void
    {
        [$contract, $workOrder, $bill] = $this->billedSubcontractor();
        $this->approve($this->raise($bill, $contract, 'debit', '3000'), $contract);

        $position = app(ContractPositionService::class)->forContract($contract->project, 'subcontractor', $workOrder);

        // 53,400 − 3,000 back-charge = 50,400 still owed. Retention 6,000 held.
        $this->assertSame('50400.00', $position['outstanding']);
        $this->assertSame('6000.00', $position['retention_held']);
        $this->assertSame('Shree Formwork', $position['party']->name);
    }

    public function test_the_dashboard_adjustment_uses_value_before_tax_on_client_bills_only(): void
    {
        [$contract, $bill] = $this->billedClient();
        $this->approve($this->raise($bill, $contract, 'credit', '10000'), $contract);
        $this->approve($this->raise($bill, $contract, 'debit', '2500.50'), $contract);

        $this->assertSame('-7499.50', app(ContractPositionService::class)->clientNoteAdjustment());
    }
}
