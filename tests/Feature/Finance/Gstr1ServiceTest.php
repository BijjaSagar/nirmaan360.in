<?php

namespace Tests\Feature\Finance;

use App\Services\Finance\GstInvoiceService;
use App\Services\Finance\Gstr1Service;

class Gstr1ServiceTest extends FinanceTestCase
{
    private function issue($bill, string $date = '2026-11-20')
    {
        return app(GstInvoiceService::class)->issue($bill, ['invoice_date' => $date, 'sac' => '9954', 'description' => 'Works contract services'], $this->accounts);
    }

    /** One invoice in each section. Each is ₹2,50,000 taxable and ₹45,000 GST. */
    private function threeInvoices(): void
    {
        $unregistered = $this->party('client', 'Mr Deshmukh');

        $this->issue($this->approvedClientBill());
        $this->issue($this->approvedClientBill($this->projectIn('Karnataka', $unregistered)));
        $this->issue($this->approvedClientBill($this->projectIn('Maharashtra', $unregistered)));
    }

    private function report(string $month = '2026-11'): array
    {
        return app(Gstr1Service::class)->report($this->organization->id, $month);
    }

    public function test_invoices_land_in_the_right_sections(): void
    {
        $this->threeInvoices();

        $report = $this->report();

        $this->assertCount(1, $report['b2b']);
        $this->assertCount(1, $report['b2cl']);
        $this->assertCount(1, $report['b2cs']);
        $this->assertSame('27AABCM1234D1Z2', $report['b2b']->first()->buyer_gstin);
        $this->assertSame('29', $report['b2cl']->first()->place_of_supply_code);
        $this->assertSame('27', $report['b2cs'][0]['pos']);
    }

    public function test_inter_state_invoice_to_an_unregistered_buyer_at_or_below_the_limit_is_b2cs(): void
    {
        config(['modules.finance.b2cl_limit' => '295000']);
        $unregistered = $this->party('client', 'Mr Deshmukh');

        $this->issue($this->approvedClientBill($this->projectIn('Karnataka', $unregistered)));

        $report = $this->report();
        $this->assertCount(0, $report['b2cl']);
        $this->assertCount(1, $report['b2cs']);
        $this->assertTrue($report['b2cs'][0]['inter']);
    }

    public function test_every_total_reconciles_across_sections_and_hsn(): void
    {
        $this->threeInvoices();

        $report = $this->report();
        $total = $report['summary']['total'];

        $this->assertTrue($report['reconciled']);
        $this->assertSame(3, $total['count']);
        $this->assertSame('750000.00', $total['taxable']);
        $this->assertSame('45000.00', $total['igst']);
        $this->assertSame('45000.00', $total['cgst']);
        $this->assertSame('45000.00', $total['sgst']);
        $this->assertSame('885000.00', $total['value']);

        foreach (['taxable', 'igst', 'cgst', 'sgst', 'value'] as $key) {
            $this->assertSame($total[$key], $report['hsn_total'][$key]);
        }

        $this->assertSame('250000.00', $report['hsn_b2b'][0]['taxable']);
        $this->assertSame('500000.00', $report['hsn_b2c'][0]['taxable']);
    }

    public function test_only_this_months_issued_invoices_count(): void
    {
        $this->threeInvoices();
        $this->issue($this->approvedClientBill(), '2026-10-31');
        $cancelled = $this->issue($this->approvedClientBill());
        app(GstInvoiceService::class)->cancel($cancelled);

        $report = $this->report();

        $this->assertSame(3, $report['summary']['total']['count']);
        $this->assertSame([['from' => 'INV/26-27/00001', 'to' => 'INV/26-27/00005', 'total' => 4, 'cancelled' => 1]], $report['docs']);
    }

    public function test_last_day_of_the_month_is_included(): void
    {
        $this->issue($this->approvedClientBill(), '2026-11-30');

        $this->assertSame(1, $this->report()['summary']['total']['count']);
    }

    public function test_approved_bills_without_an_invoice_are_counted(): void
    {
        $this->approvedClientBill();

        $this->assertSame(1, $this->report()['uninvoiced_bills']);
    }

    public function test_json_uses_the_portal_field_names_and_formats(): void
    {
        $this->threeInvoices();

        $raw = app(Gstr1Service::class)->json($this->report());
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('27AAACA1234A1Z5', $data['gstin']);
        $this->assertSame('112026', $data['fp']);

        $invoice = $data['b2b'][0]['inv'][0];
        $this->assertSame('27AABCM1234D1Z2', $data['b2b'][0]['ctin']);
        $this->assertSame('20-11-2026', $invoice['idt']);
        $this->assertSame('27', $invoice['pos']);
        $this->assertSame('N', $invoice['rchrg']);
        $this->assertSame('R', $invoice['inv_typ']);
        $this->assertSame(1801, $invoice['itms'][0]['num']);
        $this->assertEquals(295000, $invoice['val']);
        $this->assertEquals(18, $invoice['itms'][0]['itm_det']['rt']);
        $this->assertEquals(250000, $invoice['itms'][0]['itm_det']['txval']);
        $this->assertEquals(22500, $invoice['itms'][0]['itm_det']['camt']);
        $this->assertArrayNotHasKey('iamt', $invoice['itms'][0]['itm_det']);

        $this->assertSame('29', $data['b2cl'][0]['pos']);
        $this->assertEquals(45000, $data['b2cl'][0]['inv'][0]['itms'][0]['itm_det']['iamt']);

        $this->assertSame('INTRA', $data['b2cs'][0]['sply_ty']);
        $this->assertSame('OE', $data['b2cs'][0]['typ']);

        $this->assertSame('9954', $data['hsn']['hsn_b2b'][0]['hsn_sc']);
        $this->assertSame('NA', $data['hsn']['hsn_b2b'][0]['uqc']);
        $this->assertSame(1, $data['doc_issue']['doc_det'][0]['doc_num']);
        $this->assertSame(3, $data['doc_issue']['doc_det'][0]['docs'][0]['net_issue']);
    }

    public function test_json_amounts_are_numbers_with_exact_decimal_text(): void
    {
        $this->issue($this->approvedClientBill());

        $raw = app(Gstr1Service::class)->json($this->report());

        $this->assertStringContainsString('"val": 295000.00', $raw);
        $this->assertStringContainsString('"txval": 250000.00', $raw);
        $this->assertStringNotContainsString('"val": "', $raw);
    }

    public function test_excel_follows_the_offline_tool_layout(): void
    {
        $this->threeInvoices();

        $book = app(Gstr1Service::class)->spreadsheet($this->report());

        $this->assertSame(['b2b,sez,de', 'b2cl', 'b2cs', 'hsn(b2b)', 'hsn(b2c)', 'docs', 'Read me'], $book->getSheetNames());

        $b2b = $book->getSheetByName('b2b,sez,de');
        $this->assertSame(
            ['GSTIN/UIN of Recipient', 'Receiver Name', 'Invoice Number', 'Invoice date', 'Invoice Value', 'Place Of Supply', 'Reverse Charge', 'Applicable % of Tax Rate', 'Invoice Type', 'E-Commerce GSTIN', 'Rate', 'Taxable Value', 'Cess Amount'],
            $b2b->rangeToArray('A4:M4')[0],
        );
        $this->assertSame('27AABCM1234D1Z2', $b2b->getCell('A5')->getValue());
        $this->assertSame('20-Nov-2026', $b2b->getCell('D5')->getValue());
        $this->assertEquals(295000, $b2b->getCell('E5')->getValue());
        $this->assertSame('27-Maharashtra', $b2b->getCell('F5')->getValue());
        $this->assertSame('Regular B2B', $b2b->getCell('I5')->getValue());
        $this->assertEquals(250000, $b2b->getCell('L5')->getValue());

        $this->assertSame('29-Karnataka', $book->getSheetByName('b2cl')->getCell('D5')->getValue());
        $this->assertSame('OE', $book->getSheetByName('b2cs')->getCell('A5')->getValue());
        $this->assertSame('HSN', $book->getSheetByName('hsn(b2c)')->getCell('A4')->getValue());
        $this->assertEquals(500000, $book->getSheetByName('hsn(b2c)')->getCell('G5')->getValue());
        $this->assertSame('Invoices for outward supply', $book->getSheetByName('docs')->getCell('A5')->getValue());
        $this->assertStringContainsString('Chartered Accountant', $book->getSheetByName('Read me')->getCell('A3')->getValue());
    }

    public function test_an_empty_month_still_reconciles_and_exports(): void
    {
        $report = $this->report('2026-01');

        $this->assertTrue($report['reconciled']);
        $this->assertSame('0.00', $report['summary']['total']['taxable']);
        $this->assertSame([], json_decode(app(Gstr1Service::class)->json($report), true)['b2b']);
        $this->assertSame('b2cl', app(Gstr1Service::class)->spreadsheet($report)->getSheet(1)->getTitle());
    }

    public function test_another_company_is_not_included(): void
    {
        $this->issue($this->approvedClientBill());
        $other = $this->tenant('Other Infra');

        $this->assertSame(0, app(Gstr1Service::class)->report($other->id, '2026-11')['summary']['total']['count']);
    }
}
