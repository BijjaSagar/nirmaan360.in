<?php

namespace Tests\Feature\Finance;

use App\Services\Finance\ProjectLedgerService;
use App\Services\Finance\SettlementService;
use App\Services\Finance\VendorBillService;
use App\Services\RaBillService;
use App\Support\Dec;
use Brick\Math\BigDecimal;

class ProjectLedgerServiceTest extends FinanceTestCase
{
    private function ledger(?string $from = null, ?string $to = null): array
    {
        return app(ProjectLedgerService::class)->statement($this->project, $from, $to);
    }

    /** Every document type once. Expected figures are worked out by hand in the test below. */
    private function fullProject(): array
    {
        $clientBill = $this->approvedClientBill();
        $vendorBill = $this->approvedVendorBill();
        $subBill = $this->approvedSubBill();
        $this->receive($clientBill, '100000', '5000');
        $this->pay($vendorBill, '50000');
        $this->paySub($subBill, '64200');
        $wallet = $this->wallet();
        $this->petty($wallet, 'topup', '20000');
        $this->petty($wallet, 'expense', '4500.50', 'Fuel');

        return [$clientBill, $vendorBill, $subBill];
    }

    private function assertLedgerAddsUp(array $statement): void
    {
        $sum = Dec::of($statement['opening']);
        $debit = BigDecimal::zero();
        $credit = BigDecimal::zero();

        foreach ($statement['entries'] as $entry) {
            $sum = $sum->plus(Dec::of($entry['debit']))->minus(Dec::of($entry['credit']));
            $debit = $debit->plus(Dec::of($entry['debit']));
            $credit = $credit->plus(Dec::of($entry['credit']));
            $this->assertSame(Dec::money($sum), $entry['balance'], 'Running balance must equal the sum of entries so far.');
        }

        $this->assertSame(Dec::money($sum), $statement['closing']);
        $this->assertSame(Dec::money($debit), $statement['total_debit']);
        $this->assertSame(Dec::money($credit), $statement['total_credit']);
    }

    public function test_ledger_matches_a_worked_example(): void
    {
        $this->fullProject();

        $statement = $this->ledger();

        // Dr: client 2,82,500 + vendor payment 50,000 + subcontractor payment 64,200 + top-up 20,000 = 4,16,700.
        // Cr: vendor 1,17,000 + subcontractor 64,200 + receipt 1,05,000 + petty 4,500.50 = 2,90,700.50.
        $this->assertCount(8, $statement['entries']);
        $this->assertSame('416700.00', $statement['total_debit']);
        $this->assertSame('290700.50', $statement['total_credit']);
        $this->assertSame('125999.50', $statement['closing']);
        $this->assertLedgerAddsUp($statement);
    }

    public function test_ledger_balance_equals_receivable_less_payable_plus_petty_cash(): void
    {
        $this->fullProject();

        $s = $this->ledger()['summary'];

        $this->assertSame('177500.00', $s['receivable_outstanding']);
        $this->assertSame('67000.00', $s['payable_outstanding']);
        $this->assertSame('15499.50', $s['petty_in_hand']);
        $this->assertSame('125999.50', $s['net_position']);
        $this->assertSame($this->ledger()['closing'], $s['net_position']);
    }

    public function test_summary_reports_cash_and_retention(): void
    {
        $this->fullProject();

        $s = $this->ledger()['summary'];

        // Received 1,00,000 less paid 1,14,200 less top-ups 20,000.
        $this->assertSame('-34200.00', $s['net_cash']);
        $this->assertSame('5000.00', $s['tds_by_client']);
        $this->assertSame('12500.00', $s['retention_with_client']);
        $this->assertSame('6000.00', $s['retention_from_subcontractors']);
    }

    public function test_ledger_stays_exact_with_paisa_that_would_drift_as_floats(): void
    {
        $wallet = $this->wallet();

        foreach (range(1, 30) as $step) {
            $this->petty($wallet, 'topup', '0.10');
            $this->petty($wallet, 'expense', '0.07', 'Food');
        }

        $statement = $this->ledger();

        $this->assertSame('3.00', $statement['total_debit']);
        $this->assertSame('2.10', $statement['total_credit']);
        $this->assertSame('0.90', $statement['closing']);
        $this->assertLedgerAddsUp($statement);
    }

    public function test_reversals_cancel_out_and_the_ledger_still_adds_up(): void
    {
        $vendorBill = $this->approvedVendorBill();
        $payment = $this->pay($vendorBill, '117000');
        app(SettlementService::class)->reversePayment($payment, 'Bounced', $this->accounts);
        app(VendorBillService::class)->reverse($vendorBill->refresh(), 'Wrong bill', $this->owner);

        $clientBill = $this->approvedClientBill();
        $receipt = $this->receive($clientBill, '1000', '50');
        app(SettlementService::class)->reverseReceipt($receipt, 'Wrong entry', $this->accounts);

        $statement = $this->ledger();

        $this->assertLedgerAddsUp($statement);
        $this->assertSame('282500.00', $statement['closing']);
        $this->assertSame('0.00', $statement['summary']['payable_outstanding']);
        $this->assertSame($statement['closing'], $statement['summary']['net_position']);
    }

    public function test_date_range_carries_earlier_entries_into_the_opening_balance(): void
    {
        $this->fullProject();

        $whole = $this->ledger();
        $window = $this->ledger('2026-11-11', '2026-11-15');

        // Before 11 Nov: vendor bill −1,17,000 (5 Nov), top-up +20,000 and expense −4,500.50 (10 Nov).
        $this->assertSame('-101500.50', $window['opening']);
        // 15 Nov has the two payments: +50,000 and +64,200.
        $this->assertCount(2, $window['entries']);
        $this->assertSame('12699.50', $window['closing']);
        $this->assertLedgerAddsUp($window);
        $this->assertSame($whole['summary'], $window['summary']);
    }

    public function test_range_before_any_entries_is_empty_and_still_adds_up(): void
    {
        $this->fullProject();

        $statement = $this->ledger('2025-01-01', '2025-01-31');

        $this->assertSame([], $statement['entries']);
        $this->assertSame('0.00', $statement['closing']);
    }

    public function test_range_after_all_entries_shows_the_whole_balance_as_opening(): void
    {
        $this->fullProject();

        $statement = $this->ledger('2027-01-01');

        $this->assertSame('125999.50', $statement['opening']);
        $this->assertSame('125999.50', $statement['closing']);
    }

    public function test_drafts_and_cancelled_bills_stay_out(): void
    {
        $this->draftVendorBill();
        $item = $this->makeBoqItem($this->project);
        $this->approveEntry($this->draftEntry($this->project, $item, $this->pm), $this->pm, $this->owner);
        $draft = app(RaBillService::class)->generate($this->project, 'client', '2026-11-01', '2026-11-30', $this->owner);
        app(RaBillService::class)->cancel($draft);

        $this->assertSame([], $this->ledger()['entries']);
    }

    public function test_other_projects_stay_out(): void
    {
        $otherProject = $this->projectIn('Maharashtra', $this->client);
        $this->approvedClientBill($otherProject);
        $this->petty($this->wallet($otherProject), 'topup', '999');

        $this->assertSame([], $this->ledger()['entries']);
    }

    public function test_bill_marked_paid_without_a_receipt_is_flagged(): void
    {
        app(RaBillService::class)->markPaid($this->approvedClientBill());

        $this->assertSame(1, $this->ledger()['summary']['marked_paid_without_entries']);
    }
}
