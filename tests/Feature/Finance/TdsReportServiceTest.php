<?php

namespace Tests\Feature\Finance;

use App\Services\Finance\SettlementService;
use App\Services\Finance\TdsReportService;
use App\Services\Finance\VendorBillService;
use App\Support\Dec;
use Brick\Math\BigDecimal;

class TdsReportServiceTest extends FinanceTestCase
{
    private function report(string $from = '2026-10-01', string $to = '2026-12-31'): array
    {
        return app(TdsReportService::class)->report($this->organization->id, $from, $to);
    }

    public function test_tds_totals_match_a_worked_example(): void
    {
        $this->approvedVendorBill();
        $this->approvedVendorBill(['bill_no' => 'SSC-102', 'taxable_amount' => '20000', 'tds_section' => '194J', 'tds_pct' => '10']);
        $this->approvedSubBill();
        $this->receive($this->approvedClientBill(), '100000', '5000');

        $report = $this->report();

        // Vendor 1,000 + professional 2,000 + subcontractor 600.
        $this->assertSame('3600.00', $report['deducted_total']);
        $this->assertSame('180000.00', $report['deducted_base']);
        $this->assertSame('5000.00', $report['client_total']);
        $this->assertSame('100000.00', $report['client_received']);

        $sections = collect($report['by_section'])->keyBy('section');
        $this->assertSame('1600.00', $sections['194C']['tds']);
        $this->assertSame('160000.00', $sections['194C']['base']);
        $this->assertSame(2, $sections['194C']['count']);
        $this->assertSame('2000.00', $sections['194J']['tds']);
    }

    public function test_section_totals_add_up_to_the_report_total(): void
    {
        $this->approvedVendorBill(['taxable_amount' => '333.33', 'tds_pct' => '1']);
        $this->approvedVendorBill(['bill_no' => 'X-2', 'taxable_amount' => '777.77', 'tds_section' => '194J', 'tds_pct' => '10']);
        $this->approvedSubBill();

        $report = $this->report();
        $sum = collect($report['by_section'])->reduce(fn (BigDecimal $total, $row) => $total->plus(Dec::of($row['tds'])), BigDecimal::zero());

        $this->assertSame($report['deducted_total'], Dec::money($sum));
    }

    public function test_reversed_bill_nets_to_nothing(): void
    {
        app(VendorBillService::class)->reverse($this->approvedVendorBill(), 'Wrong', $this->owner);

        $report = $this->report();

        $this->assertCount(2, $report['deducted']);
        $this->assertSame('0.00', $report['deducted_total']);
    }

    public function test_reversed_receipt_nets_to_nothing(): void
    {
        $receipt = $this->receive($this->approvedClientBill(), '1000', '50');
        app(SettlementService::class)->reverseReceipt($receipt, 'Wrong', $this->accounts);

        $this->assertSame('0.00', $this->report()['client_total']);
    }

    public function test_dates_outside_the_range_are_left_out_and_end_dates_are_included(): void
    {
        $this->approvedVendorBill(['bill_date' => '2026-09-30']);
        $this->approvedVendorBill(['bill_no' => 'B', 'bill_date' => '2026-12-31', 'due_date' => null]);

        $this->assertSame(['2026-12-31'], $this->report()['deducted']->pluck('date')->all());
    }

    public function test_bills_without_tds_are_not_listed(): void
    {
        $this->approvedVendorBill(['tds_pct' => '0', 'tds_section' => null]);

        $this->assertCount(0, $this->report()['deducted']);
    }

    public function test_missing_pan_is_counted_once_per_deductee(): void
    {
        $this->vendor->update(['pan' => null]);
        $this->approvedVendorBill();
        $this->approvedVendorBill(['bill_no' => 'SSC-102']);

        $this->assertSame(1, $this->report()['missing_pan']);
    }

    public function test_drafts_and_other_companies_are_left_out(): void
    {
        $this->draftVendorBill();

        $this->assertCount(0, $this->report()['deducted']);
        $this->assertCount(0, app(TdsReportService::class)->report($this->tenant('Other')->id, '2026-10-01', '2026-12-31')['deducted']);
    }

    public function test_excel_lists_both_sides_with_totals(): void
    {
        $this->approvedVendorBill();
        $this->receive($this->approvedClientBill(), '100', '10');

        $book = app(TdsReportService::class)->spreadsheet($this->report());

        $this->assertSame(['TDS deducted by us', 'TDS deducted by clients', 'Read me'], $book->getSheetNames());
        $this->assertEquals(1000, $book->getSheet(0)->getCell('I2')->getValue());
        $this->assertSame('Total', $book->getSheet(0)->getCell('A3')->getValue());
        $this->assertEquals(10, $book->getSheet(1)->getCell('H2')->getValue());
    }
}
