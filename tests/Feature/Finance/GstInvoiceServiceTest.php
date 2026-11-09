<?php

namespace Tests\Feature\Finance;

use App\Exceptions\ImmutableRecordException;
use App\Models\GstInvoice;
use App\Models\RaBill;
use App\Services\Finance\Gst;
use App\Services\Finance\GstInvoiceService;
use App\Services\RaBillService;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

class GstInvoiceServiceTest extends FinanceTestCase
{
    private function issue(RaBill $bill, string $date = '2026-11-20'): GstInvoice
    {
        return app(GstInvoiceService::class)->issue($bill, ['invoice_date' => $date, 'sac' => '9954', 'description' => 'Works contract services'], $this->accounts);
    }

    private function irn(): array
    {
        return ['irn' => str_repeat('ab12', 16), 'irn_ack_no' => '112610000000001', 'irn_ack_date' => '2026-11-20 11:00:00'];
    }

    public function test_intra_state_invoice_matches_a_worked_example(): void
    {
        $bill = $this->approvedClientBill();

        $invoice = $this->issue($bill);

        $this->assertSame('INV/26-27/00001', $invoice->invoice_no);
        $this->assertSame('2026-27', $invoice->financial_year);
        $this->assertSame('intra', $invoice->supply_type);
        $this->assertSame('27', $invoice->place_of_supply_code);
        $this->assertSame('Maharashtra', $invoice->place_of_supply);
        $this->assertSame('250000.00', $invoice->taxable_amount);
        $this->assertSame('18.00', $invoice->gst_pct);
        $this->assertSame('22500.00', $invoice->cgst_amount);
        $this->assertSame('22500.00', $invoice->sgst_amount);
        $this->assertSame('0.00', $invoice->igst_amount);
        $this->assertSame('295000.00', $invoice->invoice_value);
        $this->assertSame('27AAACA1234A1Z5', $invoice->supplier_gstin);
        $this->assertSame('27AABCM1234D1Z2', $invoice->buyer_gstin);
        $this->assertLessThanOrEqual(16, strlen($invoice->invoice_no));
    }

    public function test_invoice_reconciles_with_its_ra_bill(): void
    {
        $bill = $this->approvedClientBill();
        $invoice = $this->issue($bill);

        $items = $bill->items->reduce(fn (BigDecimal $sum, $line) => $sum->plus(Dec::of($line->current_amount)), BigDecimal::zero());
        $this->assertTrue($items->isEqualTo(Dec::of($invoice->taxable_amount)), 'Line amounts must add up to the taxable value.');
        $this->assertSame($bill->gst_amount, $invoice->taxAmount());

        $net = Dec::of($invoice->invoice_value)->minus(Dec::of($bill->deduction_total));
        $this->assertTrue($net->isEqualTo(Dec::of($bill->net_payable)), 'Invoice value less deductions must equal net payable.');
    }

    public function test_project_in_another_state_is_charged_igst(): void
    {
        $invoice = $this->issue($this->approvedClientBill($this->projectIn('Karnataka', $this->client)));

        $this->assertSame('inter', $invoice->supply_type);
        $this->assertSame('29', $invoice->place_of_supply_code);
        $this->assertSame('45000.00', $invoice->igst_amount);
        $this->assertSame('0.00', $invoice->cgst_amount);
        $this->assertSame('0.00', $invoice->sgst_amount);
    }

    public function test_numbers_run_in_order_and_restart_each_financial_year(): void
    {
        $this->assertSame('INV/26-27/00001', $this->issue($this->approvedClientBill())->invoice_no);
        $this->assertSame('INV/26-27/00002', $this->issue($this->approvedClientBill(), '2027-03-31')->invoice_no);
        $this->assertSame('INV/27-28/00001', $this->issue($this->approvedClientBill(), '2027-04-01')->invoice_no);
    }

    public function test_issuing_twice_returns_the_same_invoice(): void
    {
        $bill = $this->approvedClientBill();

        $this->assertSame($this->issue($bill)->id, $this->issue($bill)->id);
        $this->assertSame(1, GstInvoice::count());
    }

    public function test_cancelled_invoice_keeps_its_number_and_the_bill_can_be_invoiced_again(): void
    {
        $bill = $this->approvedClientBill();
        app(GstInvoiceService::class)->cancel($this->issue($bill));

        $second = $this->issue($bill);

        $this->assertSame('INV/26-27/00002', $second->invoice_no);
        $this->assertSame(2, GstInvoice::count());
    }

    public function test_invoice_is_issued_with_empty_irn_fields_and_makes_no_outside_call(): void
    {
        Http::preventStrayRequests();

        $invoice = $this->issue($this->approvedClientBill());

        $this->assertNull($invoice->irn);
        $this->assertNull($invoice->irn_ack_no);
        $this->assertNull($invoice->irn_ack_date);
    }

    public function test_irn_can_be_recorded_once(): void
    {
        $invoice = $this->issue($this->approvedClientBill());

        app(GstInvoiceService::class)->recordIrn($invoice, $this->irn());

        $this->assertSame(str_repeat('ab12', 16), $invoice->refresh()->irn);
        $this->assertSame('112610000000001', $invoice->irn_ack_no);

        $this->expectException(ValidationException::class);
        app(GstInvoiceService::class)->recordIrn($invoice, $this->irn());
    }

    public function test_invoice_with_an_irn_cannot_be_cancelled(): void
    {
        $invoice = $this->issue($this->approvedClientBill());
        app(GstInvoiceService::class)->recordIrn($invoice, $this->irn());

        $this->expectException(ValidationException::class);
        app(GstInvoiceService::class)->cancel($invoice->refresh());
    }

    public function test_tax_figures_cannot_be_changed_after_issue(): void
    {
        $invoice = $this->issue($this->approvedClientBill());

        $this->expectException(ImmutableRecordException::class);
        $invoice->update(['taxable_amount' => '1.00']);
    }

    #[DataProvider('refusals')]
    public function test_invoice_is_refused_when_it_cannot_be_valid(callable $prepare): void
    {
        $bill = $prepare($this);

        try {
            $this->issue($bill);
            $this->fail('This invoice must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice', $exception->errors());
            $this->assertSame(0, GstInvoice::count());
        }
    }

    public static function refusals(): array
    {
        return [
            'company has no GSTIN' => [function ($test) {
                $bill = $test->approvedClientBill();
                $test->organization->update(['gstin' => null]);

                return $bill;
            }],
            'project has no state' => [fn ($test) => $test->approvedClientBill($test->projectIn('', $test->client))],
            'project state is not recognised' => [fn ($test) => $test->approvedClientBill($test->projectIn('Atlantis', $test->client))],
            'subcontractor bill' => [fn ($test) => $test->approvedSubBill()],
        ];
    }

    public function test_bill_without_gst_cannot_have_a_tax_invoice(): void
    {
        $item = $this->makeBoqItem($this->project);
        $this->approveEntry($this->draftEntry($this->project, $item, $this->pm), $this->pm, $this->owner);
        $bill = $this->approveDocument(app(RaBillService::class)->generate($this->project, 'client', '2026-11-01', '2026-11-30', $this->owner, withGst: false));

        $this->expectException(ValidationException::class);
        $this->issue($bill);
    }

    public function test_unapproved_bill_cannot_have_a_tax_invoice(): void
    {
        $item = $this->makeBoqItem($this->project);
        $this->approveEntry($this->draftEntry($this->project, $item, $this->pm), $this->pm, $this->owner);

        $this->expectException(ValidationException::class);
        $this->issue(app(RaBillService::class)->generate($this->project, 'client', '2026-11-01', '2026-11-30', $this->owner));
    }

    public function test_state_codes_and_financial_years(): void
    {
        $this->assertSame('27', Gst::stateCode(' maharashtra '));
        $this->assertSame('01', Gst::stateCode('Jammu and Kashmir'));
        $this->assertNull(Gst::stateCode('Atlantis'));
        $this->assertSame('29', Gst::codeFromGstin('29AAACA1234A1Z5'));
        $this->assertNull(Gst::codeFromGstin('99AAACA1234A1Z5'));
        $this->assertSame('2026-27', Gst::financialYear(Carbon::parse('2026-04-01')));
        $this->assertSame('2025-26', Gst::financialYear(Carbon::parse('2026-03-31')));
        $this->assertSame(['2026-10-01', '2026-12-31'], Gst::quarterRange(Carbon::parse('2026-11-20')));
        $this->assertSame(['2027-01-01', '2027-03-31'], Gst::quarterRange(Carbon::parse('2027-02-10')));
        $this->assertSame(['2026-04-01', '2026-06-30'], Gst::quarterRange(Carbon::parse('2026-04-01')));
    }
}
