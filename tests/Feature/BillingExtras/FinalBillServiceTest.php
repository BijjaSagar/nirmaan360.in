<?php

namespace Tests\Feature\BillingExtras;

use App\Exceptions\ImmutableRecordException;
use App\Models\ApprovalRule;
use App\Models\AuditLog;
use App\Services\ApprovalService;
use App\Services\BillingExtras\ContractPositionService;
use App\Services\MeasurementService;
use App\Services\RaBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** FR-BIL-03: the final bill settles a contract and closes it. */
class FinalBillServiceTest extends TestCase
{
    use BuildsContracts, RefreshDatabase;

    private function assertRefused(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('The action was allowed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_final_bill_adds_new_work_releases_retention_and_recovers_the_remaining_advance(): void
    {
        $contract = $this->clientContract(['advance_amount' => '50000']);
        $this->measure($contract, '50');
        $this->approvedClientBill($contract);

        $this->measure($contract, '10');
        $final = $this->finalClientBill($contract);

        // Work: 10 × 5,000 = 50,000. No retention is held back from the last bill.
        // Advance: 50,000 − 25,000 recovered = 25,000, all of it now. TDS 2% = 1,000.
        // GST 18% = 9,000. Retention released from the first bill: 12,500.
        // Net = 50,000 − 26,000 + 9,000 + 12,500 = 45,500.
        $this->assertTrue($final->is_final);
        $this->assertSame('50000.00', $final->gross_amount);
        $this->assertSame('26000.00', $final->deduction_total);
        $this->assertSame('9000.00', $final->gst_amount);
        $this->assertSame('12500.00', $final->retention_released);
        $this->assertSame('45500.00', $final->net_payable);
        $this->assertSame(['advance', 'tds'], $final->deductions->pluck('type')->all());
        $this->assertSame('FB-'.$contract->project->code.'-002', $final->number);
        $this->assertSame('DRAFT', $final->status);
    }

    public function test_retention_is_gathered_from_every_earlier_bill(): void
    {
        $contract = $this->clientContract(['advance_amount' => '0']);
        $this->measure($contract, '10');
        $this->approvedClientBill($contract);
        $this->measure($contract, '20');
        $this->approvedClientBill($contract);

        $final = $this->finalClientBill($contract);

        // 5% of 50,000 + 5% of 1,00,000 = 2,500 + 5,000 = 7,500. No new work, GST or TDS.
        $this->assertSame('7500.00', $final->retention_released);
        $this->assertSame('0.00', $final->gross_amount);
        $this->assertSame('7500.00', $final->net_payable);
    }

    public function test_a_shortfall_in_the_advance_leaves_a_negative_amount_due_from_the_party(): void
    {
        $contract = $this->clientContract();
        $workOrder = $this->workOrder($contract, ['advance_amount' => '20000', 'advance_recovery_pct' => '10']);
        $this->measure($contract, '50', $workOrder);
        $this->approveBill($this->subBill($contract, $workOrder), $contract->owner);

        $final = $this->finalSubBill($contract, $workOrder);

        // First bill: 60,000, advance 10% = 6,000, retention 6,000. Advance left 14,000.
        // Final: no work, advance 14,000 recovered, retention 6,000 released. 6,000 − 14,000 = −8,000.
        $this->assertSame('14000.00', $final->deduction_total);
        $this->assertSame('6000.00', $final->retention_released);
        $this->assertSame('-8000.00', $final->net_payable);
        $this->assertSame('FSB-'.$workOrder->id.'-'.$contract->project->code.'-002', $final->number);
    }

    public function test_on_a_running_balance_percentages_stop_once_the_advance_has_taken_it_below_zero(): void
    {
        $contract = $this->clientContract();
        $workOrder = $this->workOrder($contract, ['advance_amount' => '50000', 'advance_recovery_pct' => '10', 'deduction_basis' => 'running', 'deduction_order' => ['advance', 'tds']]);
        $this->measure($contract, '10', $workOrder);
        $this->approveBill($this->subBill($contract, $workOrder), $contract->owner);

        $this->measure($contract, '5', $workOrder);
        $final = $this->finalSubBill($contract, $workOrder);

        // First bill: 12,000 gross, advance 1,200, then TDS on 10,800 = 108. Advance left 48,800.
        // Final: 5 × 1,200 = 6,000 gross, advance 48,800 takes the balance to −42,800, so TDS is nil.
        $this->assertSame('48800.00', $final->deduction_total);
        $this->assertSame(['advance'], $final->deductions->pluck('type')->all());
        $this->assertSame('-42800.00', $final->net_payable);
    }

    public function test_the_final_bill_blocks_further_bills_from_the_moment_it_exists(): void
    {
        $contract = $this->clientContract();
        $this->measure($contract, '10');
        $this->approvedClientBill($contract);
        $this->finalClientBill($contract);

        $this->measure($contract, '5');

        $this->assertRefused(fn () => $this->clientBill($contract), 'type');
        $this->assertRefused(fn () => $this->finalClientBill($contract), 'type');
    }

    public function test_cancelling_a_draft_final_bill_reopens_the_contract(): void
    {
        $contract = $this->clientContract();
        $this->measure($contract, '10');
        $this->approvedClientBill($contract);
        $final = $this->finalClientBill($contract);

        app(RaBillService::class)->cancel($final);

        $this->measure($contract, '5');
        $this->assertSame('25000.00', $this->clientBill($contract)->gross_amount);
    }

    public function test_approving_a_final_bill_closes_the_work_order_and_writes_the_audit_log(): void
    {
        $contract = $this->clientContract();
        $workOrder = $this->workOrder($contract);
        $this->measure($contract, '10', $workOrder);
        $this->approveBill($this->subBill($contract, $workOrder), $contract->owner);

        $final = $this->finalSubBill($contract, $workOrder);
        $this->assertSame('ACTIVE', $workOrder->refresh()->status);

        $this->approveBill($final, $contract->owner);

        $this->assertSame('CLOSED', $workOrder->refresh()->status);
        $this->assertNotNull($workOrder->closed_at);
        $this->assertSame(1, AuditLog::where('action', 'contract.closed')->where('auditable_id', $final->id)->count());

        $position = app(ContractPositionService::class)->forContract($contract->project, 'subcontractor', $workOrder);
        $this->assertTrue($position['closed']);
        $this->assertSame('0.00', $position['retention_held']);

        $this->measure($contract, '5', null);
        $this->assertRefused(fn () => $this->subBill($contract, $workOrder), 'work_order_id');
    }

    public function test_closing_one_work_order_leaves_the_others_and_the_client_contract_open(): void
    {
        $contract = $this->clientContract();
        $closing = $this->workOrder($contract);
        $this->measure($contract, '10', $closing);
        $this->approveBill($this->subBill($contract, $closing), $contract->owner);
        $this->approveBill($this->finalSubBill($contract, $closing), $contract->owner);

        $other = $this->workOrder($contract);
        $this->measure($contract, '5', $other);
        $this->assertSame('6000.00', $this->subBill($contract, $other)->gross_amount);
        $this->assertSame('75000.00', $this->approvedClientBill($contract)->gross_amount);
    }

    public function test_a_client_sign_off_can_close_the_contract_with_no_user_signed_in(): void
    {
        $contract = $this->clientContract();
        $workOrder = $this->workOrder($contract);
        $this->measure($contract, '10', $workOrder);
        $this->approveBill($this->subBill($contract, $workOrder), $contract->owner);
        ApprovalRule::where('organization_id', $contract->organization->id)->where('doc_type', 'ra_bill')->update(['levels' => ['client']]);

        $approval = app(ApprovalService::class)->submit($this->finalSubBill($contract, $workOrder), $contract->owner);
        auth()->logout();
        app(ApprovalService::class)->actAsClient($approval, 'A. Client', 'approve');

        $this->assertSame('CLOSED', $workOrder->refresh()->status);
    }

    public function test_the_approved_final_bill_is_immutable(): void
    {
        $contract = $this->clientContract();
        $this->measure($contract, '10');
        $this->approvedClientBill($contract);
        $final = $this->approveBill($this->finalClientBill($contract), $contract->owner);

        $this->expectException(ImmutableRecordException::class);
        $final->update(['retention_released' => '0.00']);
    }

    public function test_an_unsettled_bill_stops_the_final_bill(): void
    {
        $contract = $this->clientContract();
        $this->measure($contract, '10');
        $draft = $this->clientBill($contract);

        try {
            $this->finalClientBill($contract);
            $this->fail('A final bill was raised with a draft bill open.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($draft->number, $exception->errors()['type'][0]);
        }
    }

    public function test_measurements_still_in_progress_stop_the_final_bill(): void
    {
        $contract = $this->clientContract();
        $this->measure($contract, '10');
        $this->approvedClientBill($contract);
        $this->draftEntry($contract->project, $contract->item, $contract->engineer);

        $this->assertRefused(fn () => $this->finalClientBill($contract), 'type');
    }

    public function test_approved_work_dated_after_the_period_stops_the_final_bill(): void
    {
        $contract = $this->clientContract();
        $this->measure($contract, '10');
        $this->approvedClientBill($contract);
        $this->measure($contract, '5', null, ['measured_on' => '2026-12-05']);

        $this->assertRefused(fn () => $this->finalClientBill($contract), 'period_to');
    }

    public function test_a_contract_with_nothing_billed_has_nothing_to_finalise(): void
    {
        $contract = $this->clientContract();

        $this->assertRefused(fn () => $this->finalClientBill($contract), 'period_to');
    }

    public function test_a_final_bill_can_carry_the_first_and_only_work_on_a_contract(): void
    {
        $contract = $this->clientContract(['advance_amount' => '0']);
        $this->measure($contract, '10');

        $final = $this->finalClientBill($contract);

        // 50,000 gross, nothing retained, TDS 1,000, GST 9,000. Net 58,000.
        $this->assertSame('0.00', $final->retention_released);
        $this->assertSame('58000.00', $final->net_payable);
    }

    public function test_a_closed_work_order_takes_no_new_measurements(): void
    {
        $contract = $this->clientContract();
        $workOrder = $this->workOrder($contract);
        $this->measure($contract, '10', $workOrder);
        $this->approveBill($this->subBill($contract, $workOrder), $contract->owner);
        $this->approveBill($this->finalSubBill($contract, $workOrder), $contract->owner);

        $validator = validator(
            ['boq_item_id' => $contract->item->id, 'work_order_id' => $workOrder->id, 'measured_on' => '2026-11-01', 'nos' => 1],
            app(MeasurementService::class)->rules($contract->project),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('work_order_id', $validator->errors()->toArray());
    }
}
