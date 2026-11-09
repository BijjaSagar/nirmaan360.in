<?php

namespace Tests\Feature\Finance;

use App\Exceptions\ImmutableRecordException;
use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\AuditLog;
use App\Models\VendorBill;
use App\Services\ApprovalService;
use App\Services\Finance\Gst;
use App\Services\Finance\VendorBillService;
use App\Support\Dec;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VendorBillServiceTest extends FinanceTestCase
{
    public function test_intra_state_bill_matches_a_worked_example(): void
    {
        $bill = $this->draftVendorBill();

        $this->assertSame('100000.00', $bill->taxable_amount);
        $this->assertSame('9000.00', $bill->cgst_amount);
        $this->assertSame('9000.00', $bill->sgst_amount);
        $this->assertSame('0.00', $bill->igst_amount);
        $this->assertSame('118000.00', $bill->total_amount);
        $this->assertSame('1000.00', $bill->tds_amount);
        $this->assertSame('117000.00', $bill->net_payable);
        $this->assertSame('DRAFT', $bill->status);
    }

    public function test_inter_state_bill_puts_all_gst_under_igst(): void
    {
        $bill = $this->draftVendorBill(['supply_type' => 'inter', 'taxable_amount' => '45000.50', 'gst_pct' => '12', 'tds_pct' => '2']);

        // 12% of 45,000.50 = 5,400.06. TDS 2% = 900.01. Total 50,400.56. Net 49,500.55.
        $this->assertSame('5400.06', $bill->igst_amount);
        $this->assertSame('0.00', $bill->cgst_amount);
        $this->assertSame('50400.56', $bill->total_amount);
        $this->assertSame('900.01', $bill->tds_amount);
        $this->assertSame('49500.55', $bill->net_payable);
    }

    public function test_cgst_and_sgst_always_add_back_to_the_tax(): void
    {
        $this->assertSame(['cgst' => '9.01', 'sgst' => '9.00', 'igst' => '0.00'], Gst::split('18.01', false));

        foreach (['100.05', '0.03', '777.77', '3333.33', '1.00'] as $taxable) {
            $computed = app(VendorBillService::class)->compute($taxable, '18', 'intra', '0');
            $parts = Dec::of($computed['cgst_amount'])->plus(Dec::of($computed['sgst_amount']));

            $this->assertTrue($parts->isEqualTo(Dec::of(Dec::percentOf($taxable, '18'))), "GST parts must add up for {$taxable}");
            $this->assertTrue(Dec::of($computed['total_amount'])->isEqualTo(Dec::of($computed['taxable_amount'])->plus($parts)));
            $this->assertTrue(Dec::of($computed['net_payable'])->isEqualTo(Dec::of($computed['total_amount'])->minus(Dec::of($computed['tds_amount']))));
        }
    }

    public function test_bill_goes_through_the_approval_chain_before_it_counts(): void
    {
        $bill = $this->draftVendorBill();
        $approval = app(VendorBillService::class)->submit($bill, $this->pm);

        $this->assertSame('IN_REVIEW', $bill->refresh()->status);
        $this->assertSame(['pm', 'accounts'], $approval->levels);
        $this->assertSame(['pm', 'accounts'], ApprovalRule::where('doc_type', 'vendor_bill')->first()->levels);

        $otherPm = $this->member($this->organization, 'pm');
        $approval = app(ApprovalService::class)->act($approval, $otherPm, 'approve');
        $this->assertSame('IN_REVIEW', $bill->refresh()->status);

        app(ApprovalService::class)->act($approval, $this->accounts, 'approve');

        $this->assertSame('APPROVED', $bill->refresh()->status);
        $this->assertNotNull($bill->approved_at);
    }

    public function test_a_company_rule_decides_the_chain_after_first_use(): void
    {
        ApprovalRule::create(['organization_id' => $this->organization->id, 'doc_type' => 'vendor_bill', 'levels' => ['accounts'], 'active' => true]);

        $approval = app(VendorBillService::class)->submit($this->draftVendorBill(), $this->pm);

        $this->assertSame(['accounts'], $approval->levels);
    }

    public function test_submitter_cannot_approve_their_own_bill(): void
    {
        $approval = app(VendorBillService::class)->submit($this->draftVendorBill(), $this->pm);

        $this->assertFalse(app(ApprovalService::class)->canAct($approval, $this->pm));
    }

    public function test_approved_bill_cannot_be_changed_or_deleted(): void
    {
        $bill = $this->approvedVendorBill();

        try {
            $bill->update(['taxable_amount' => '1.00']);
            $this->fail('An approved bill must not change.');
        } catch (ImmutableRecordException) {
            $this->assertSame('100000.00', $bill->fresh()->taxable_amount);
        }

        $this->expectException(ImmutableRecordException::class);
        $bill->delete();
    }

    public function test_only_draft_or_returned_bills_can_be_edited(): void
    {
        $bill = $this->draftVendorBill();
        app(VendorBillService::class)->submit($bill, $this->pm);

        $this->expectException(ValidationException::class);
        app(VendorBillService::class)->update($bill->refresh(), $this->billData(['taxable_amount' => '5']));
    }

    public function test_returned_bill_can_be_fixed_and_resubmitted(): void
    {
        $bill = $this->draftVendorBill();
        $approval = app(VendorBillService::class)->submit($bill, $this->pm);
        app(ApprovalService::class)->act($approval, $this->owner, 'return', 'Wrong amount');

        app(VendorBillService::class)->update($bill->refresh(), $this->billData(['taxable_amount' => '200000']));

        $this->assertSame('236000.00', $bill->refresh()->total_amount);
        $this->assertSame('RETURNED', $bill->status);
        $this->assertInstanceOf(Approval::class, app(VendorBillService::class)->submit($bill, $this->pm));
    }

    public function test_same_vendor_invoice_cannot_be_entered_twice(): void
    {
        $this->draftVendorBill();

        try {
            $this->draftVendorBill();
            $this->fail('A duplicate vendor bill number must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bill_no', $exception->errors());
        }

        $other = $this->party('vendor', 'Another Vendor');
        $this->assertSame('SSC-101', $this->draftVendorBill(['party_id' => $other->id])->bill_no);
    }

    public function test_a_cancelled_bill_number_can_be_entered_again(): void
    {
        app(VendorBillService::class)->cancel($this->draftVendorBill());

        $this->assertSame('DRAFT', $this->draftVendorBill()->status);
    }

    public function test_repeating_a_client_uuid_returns_the_first_bill(): void
    {
        $uuid = (string) Str::uuid();

        $first = $this->draftVendorBill(['client_uuid' => $uuid]);
        $second = $this->draftVendorBill(['client_uuid' => $uuid]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, VendorBill::count());
    }

    public function test_reversal_is_an_equal_and_opposite_entry(): void
    {
        $bill = $this->approvedVendorBill();

        $reversal = app(VendorBillService::class)->reverse($bill, 'Entered against wrong project', $this->owner);

        $this->assertSame('REVERSED', $bill->refresh()->status);
        $this->assertSame('REVERSAL', $reversal->status);
        $this->assertSame($bill->id, $reversal->reversal_of_id);
        $this->assertSame('-118000.00', $reversal->total_amount);
        $this->assertSame('-1000.00', $reversal->tds_amount);
        $this->assertSame('-117000.00', $reversal->net_payable);
        $this->assertSame('2026-11-20', $reversal->bill_date->toDateString());
        $this->assertTrue(AuditLog::where('action', 'vendor_bill.reversed')->exists());
    }

    public function test_bill_with_payments_cannot_be_reversed_until_they_are(): void
    {
        $bill = $this->approvedVendorBill();
        $this->pay($bill, '1000');

        $this->expectException(ValidationException::class);
        app(VendorBillService::class)->reverse($bill->refresh(), 'Mistake', $this->owner);
    }
}
