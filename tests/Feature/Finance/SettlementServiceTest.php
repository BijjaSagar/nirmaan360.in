<?php

namespace Tests\Feature\Finance;

use App\Exceptions\ImmutableRecordException;
use App\Models\ClientReceipt;
use App\Models\VendorPayment;
use App\Services\Finance\SettlementService;
use App\Services\RaBillService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SettlementServiceTest extends FinanceTestCase
{
    private function clientRow($bill): array
    {
        return app(SettlementService::class)->clientBills($this->organization->id, $this->project->id)->firstWhere(fn ($row) => $row['bill']->id === $bill->id);
    }

    private function payableRow($bill): array
    {
        return app(SettlementService::class)->payableBills($this->organization->id, $this->project->id)->firstWhere(fn ($row) => $row['bill']->is($bill));
    }

    public function test_part_payments_settle_a_vendor_bill(): void
    {
        $bill = $this->approvedVendorBill();

        $this->pay($bill, '50000.25');
        $this->assertSame('PART_PAID', $bill->refresh()->status);
        $this->assertSame('66999.75', $this->payableRow($bill)['outstanding']);

        $this->pay($bill, '66999.75');
        $this->assertSame('PAID', $bill->refresh()->status);
        $this->assertNotNull($bill->paid_at);
        $this->assertSame('0.00', $this->payableRow($bill)['outstanding']);
        $this->assertSame('117000.00', $this->payableRow($bill)['paid']);
    }

    public function test_a_payment_cannot_exceed_the_balance(): void
    {
        $bill = $this->approvedVendorBill();
        $this->pay($bill, '117000');

        $this->expectException(ValidationException::class);
        $this->pay($bill->refresh(), '0.01');
    }

    public function test_an_unapproved_bill_cannot_be_paid(): void
    {
        $this->expectException(ValidationException::class);
        $this->pay($this->draftVendorBill(), '10');
    }

    public function test_subcontractor_bill_is_paid_net_of_its_deductions(): void
    {
        $bill = $this->approvedSubBill();
        $this->assertSame('64200.00', $this->payableRow($bill)['payable']);

        $this->paySub($bill, '40000');
        $this->assertSame('APPROVED', $bill->refresh()->status);
        $this->assertSame('24200.00', $this->payableRow($bill)['outstanding']);
        $this->assertSame('6000.00', $this->payableRow($bill)['retention']);

        $this->paySub($bill, '24200');
        $this->assertSame('PAID', $bill->refresh()->status);
    }

    public function test_a_client_bill_cannot_take_a_subcontractor_payment(): void
    {
        $this->expectException(ValidationException::class);
        $this->paySub($this->approvedClientBill(), '100');
    }

    public function test_repeating_a_client_uuid_records_one_payment(): void
    {
        $bill = $this->approvedVendorBill();
        $data = ['paid_on' => '2026-11-15', 'amount' => '1000', 'mode' => 'bank', 'client_uuid' => (string) Str::uuid()];

        $first = app(SettlementService::class)->payVendorBill($bill, $data, $this->accounts);
        $second = app(SettlementService::class)->payVendorBill($bill, $data, $this->accounts);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, VendorPayment::count());
    }

    public function test_reversing_a_payment_reopens_the_bill(): void
    {
        $bill = $this->approvedVendorBill();
        $payment = $this->pay($bill, '117000');

        $reversal = app(SettlementService::class)->reversePayment($payment, 'Cheque bounced', $this->accounts);

        $this->assertSame('-117000.00', $reversal->amount);
        $this->assertSame($payment->id, $reversal->reversal_of_id);
        $this->assertSame('APPROVED', $bill->refresh()->status);
        $this->assertNull($bill->paid_at);
        $this->assertSame('117000.00', $this->payableRow($bill)['outstanding']);
    }

    public function test_an_entry_can_be_reversed_only_once(): void
    {
        $payment = $this->pay($this->approvedVendorBill(), '100');
        $reversal = app(SettlementService::class)->reversePayment($payment, 'Mistake', $this->accounts);

        foreach ([$payment, $reversal] as $entry) {
            try {
                app(SettlementService::class)->reversePayment($entry, 'Again', $this->accounts);
                $this->fail('A second reversal must be refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('reason', $exception->errors());
            }
        }

        $this->assertSame(2, VendorPayment::count());
    }

    public function test_posted_entries_cannot_be_edited_or_deleted(): void
    {
        $payment = $this->pay($this->approvedVendorBill(), '100');

        try {
            $payment->update(['amount' => '1']);
            $this->fail('A posted payment must not change.');
        } catch (ImmutableRecordException) {
            $this->assertSame('100.00', $payment->fresh()->amount);
        }

        $this->expectException(ImmutableRecordException::class);
        $this->receive($this->approvedClientBill(), '100')->delete();
    }

    public function test_receipts_with_part_payment_and_tds_settle_a_bill_that_already_shows_tds(): void
    {
        // Gross 2,50,000 less retention 12,500 and TDS 5,000, plus GST 45,000 = net 2,77,500.
        // Before TDS the client owes 2,82,500.
        $bill = $this->approvedClientBill();
        $this->assertSame('277500.00', $bill->net_payable);
        $this->assertSame('282500.00', $this->clientRow($bill)['receivable']);

        $this->receive($bill, '100000', '2000');
        $row = $this->clientRow($bill);
        $this->assertSame('APPROVED', $bill->refresh()->status);
        $this->assertSame('102000.00', $row['settled']);
        $this->assertSame('180500.00', $row['outstanding']);
        $this->assertSame('177500.00', $row['cash_expected']);

        $this->receive($bill, '177500', '3000');
        $row = $this->clientRow($bill);
        $this->assertSame('PAID', $bill->refresh()->status);
        $this->assertSame('277500.00', $row['received']);
        $this->assertSame('5000.00', $row['tds']);
        $this->assertSame('0.00', $row['outstanding']);
        $this->assertSame('12500.00', $row['retention']);
    }

    public function test_receipt_settles_a_bill_made_without_tds_when_client_deducts_it_at_payment(): void
    {
        $this->project->update(['tds_pct' => '0']);
        $bill = $this->approvedClientBill();

        // Gross 2,50,000 less retention 12,500 plus GST 45,000 = 2,82,500, with no TDS on the bill.
        $this->assertSame('282500.00', $this->clientRow($bill)['receivable']);

        $this->receive($bill, '277500', '5000');

        $this->assertSame('PAID', $bill->refresh()->status);
        $this->assertSame('0.00', $this->clientRow($bill)['outstanding']);
    }

    public function test_a_receipt_cannot_settle_more_than_the_bill(): void
    {
        $bill = $this->approvedClientBill();

        try {
            $this->receive($bill, '282500', '0.01');
            $this->fail('Over-settling must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $this->assertSame(0, ClientReceipt::count());
    }

    public function test_a_receipt_needs_money_or_tds(): void
    {
        $this->expectException(ValidationException::class);
        $this->receive($this->approvedClientBill(), '0', '0');
    }

    public function test_a_tds_only_receipt_is_allowed(): void
    {
        $receipt = $this->receive($this->approvedClientBill(), '0', '5000');

        $this->assertSame('5000.00', $receipt->settled());
    }

    public function test_receipt_needs_an_approved_client_bill(): void
    {
        $this->expectException(ValidationException::class);
        $this->receive($this->approvedSubBill(), '10');
    }

    public function test_reversing_a_receipt_reopens_a_paid_bill(): void
    {
        $bill = $this->approvedClientBill();
        $receipt = $this->receive($bill, '277500', '5000');
        $this->assertSame('PAID', $bill->refresh()->status);

        $reversal = app(SettlementService::class)->reverseReceipt($receipt, 'Wrong client', $this->accounts);

        $this->assertSame('-277500.00', $reversal->amount);
        $this->assertSame('-5000.00', $reversal->tds_amount);
        $this->assertSame('APPROVED', $bill->refresh()->status);
        $this->assertNull($bill->paid_at);
        $this->assertSame('282500.00', $this->clientRow($bill)['outstanding']);
    }

    public function test_receipts_follow_a_bill_marked_paid_by_hand(): void
    {
        $bill = app(RaBillService::class)->markPaid($this->approvedClientBill());
        $this->assertSame('PAID', $bill->status);
        $this->assertSame('282500.00', $this->clientRow($bill)['outstanding']);

        $this->receive($bill, '100000');

        $this->assertSame('APPROVED', $bill->refresh()->status);
    }

    public function test_repeating_a_client_uuid_records_one_receipt(): void
    {
        $bill = $this->approvedClientBill();
        $data = ['received_on' => '2026-11-16', 'amount' => '100', 'mode' => 'bank', 'client_uuid' => (string) Str::uuid()];

        app(SettlementService::class)->recordReceipt($bill, $data, $this->accounts);
        app(SettlementService::class)->recordReceipt($bill, $data, $this->accounts);

        $this->assertSame(1, ClientReceipt::count());
    }
}
