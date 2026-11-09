<?php

namespace Tests\Feature\Finance;

use App\Models\Party;
use App\Services\Finance\GstInvoiceService;
use App\Services\Finance\SettlementService;
use App\Services\Finance\TallyExportService;
use App\Services\Finance\VendorBillService;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use SimpleXMLElement;

class TallyExportServiceTest extends FinanceTestCase
{
    private function vouchers(?string $bank = null): array
    {
        return app(TallyExportService::class)->vouchers($this->organization->id, '2026-10-01', '2026-12-31', null, $bank);
    }

    private function service(): TallyExportService
    {
        return app(TallyExportService::class);
    }

    private function everything(): void
    {
        $clientBill = $this->approvedClientBill();
        app(GstInvoiceService::class)->issue($clientBill, ['invoice_date' => '2026-11-20', 'sac' => '9954', 'description' => 'Works'], $this->accounts);
        $vendorBill = $this->approvedVendorBill();
        $subBill = $this->approvedSubBill();
        $this->receive($clientBill, '100000', '5000');
        $this->pay($vendorBill, '50000');
        $this->paySub($subBill, '10000');
        $wallet = $this->wallet();
        $this->petty($wallet, 'topup', '5000');
        $this->petty($wallet, 'expense', '123.45', 'Fuel');
    }

    private function assertBalanced(array $vouchers): void
    {
        $this->assertNotEmpty($vouchers);

        foreach ($vouchers as $voucher) {
            $sum = array_reduce($voucher['entries'], fn (BigDecimal $total, array $entry) => $total->plus(Dec::of($entry['amount'])), BigDecimal::zero());
            $this->assertTrue($sum->isZero(), "Voucher {$voucher['number']} ({$voucher['type']}) is off by {$sum}.");
        }
    }

    private function entries(array $voucher): array
    {
        return collect($voucher['entries'])->mapWithKeys(fn ($entry) => [$entry['ledger'] => $entry['amount']])->all();
    }

    private function voucherOfType(string $type, array $vouchers): array
    {
        return collect($vouchers)->firstWhere('type', $type);
    }

    public function test_every_voucher_balances(): void
    {
        $this->everything();

        $vouchers = $this->vouchers();

        $this->assertBalanced($vouchers);
        $this->assertEqualsCanonicalizing(['Sales', 'Purchase', 'Purchase', 'Receipt', 'Payment', 'Payment', 'Payment', 'Contra'], array_column($vouchers, 'type'));
    }

    public function test_sales_voucher_matches_the_invoice(): void
    {
        $this->everything();

        $sales = $this->voucherOfType('Sales', $this->vouchers());

        $this->assertSame('INV/26-27/00001', $sales['number']);
        $this->assertSame([
            'Metro Developers' => '282500.00',
            'Retention Receivable' => '12500.00',
            'Contract Revenue' => '-250000.00',
            'Output CGST' => '-22500.00',
            'Output SGST' => '-22500.00',
        ], $this->entries($sales));
    }

    public function test_purchase_voucher_matches_the_vendor_bill(): void
    {
        $this->everything();

        $purchase = collect($this->vouchers())->firstWhere('number', 'SSC-101');

        $this->assertSame([
            'Sai Steel & Cement' => '-117000.00',
            'Materials' => '100000.00',
            'Input CGST' => '9000.00',
            'Input SGST' => '9000.00',
            'TDS Payable - 194C' => '-1000.00',
        ], $this->entries($purchase));
    }

    public function test_receipt_voucher_splits_bank_and_tds(): void
    {
        $this->everything();

        $this->assertSame([
            'Bank Account' => '100000.00',
            'TDS Receivable' => '5000.00',
            'Metro Developers' => '-105000.00',
        ], $this->entries($this->voucherOfType('Receipt', $this->vouchers())));
    }

    public function test_subcontractor_voucher_books_retention_and_tds(): void
    {
        $this->approvedSubBill();

        $voucher = $this->vouchers()[0];

        $this->assertBalanced([$voucher]);
        $this->assertSame([
            'Shree Formwork' => '-64200.00',
            'Subcontractor Charges' => '60000.00',
            'Input CGST' => '5400.00',
            'Input SGST' => '5400.00',
            'Retention Payable' => '-6000.00',
            'TDS Payable - 194C' => '-600.00',
        ], $this->entries($voucher));
    }

    public function test_advance_and_material_recoveries_balance(): void
    {
        $this->project->update(['advance_amount' => '100000', 'advance_recovery_pct' => '10']);

        $bill = $this->approvedClientBill();

        $voucher = $this->vouchers()[0];
        $this->assertBalanced([$voucher]);
        $this->assertSame('25000.00', $this->entries($voucher)['Mobilisation Advance from Clients']);
        $this->assertSame($bill->number, $voucher['number']);
    }

    public function test_reversals_balance_and_swap_voucher_type(): void
    {
        $vendorBill = $this->approvedVendorBill();
        $payment = $this->pay($vendorBill, '117000');
        app(SettlementService::class)->reversePayment($payment, 'Bounced', $this->accounts);
        app(VendorBillService::class)->reverse($vendorBill->refresh(), 'Wrong', $this->owner);

        $vouchers = $this->vouchers();

        $this->assertBalanced($vouchers);
        $this->assertEqualsCanonicalizing(['Purchase', 'Journal', 'Payment', 'Receipt'], array_column($vouchers, 'type'));
    }

    public function test_cash_payments_use_the_cash_ledger_and_the_bank_name_can_be_set(): void
    {
        $bill = $this->approvedVendorBill();
        app(SettlementService::class)->payVendorBill($bill, ['paid_on' => '2026-11-15', 'amount' => '100', 'mode' => 'cash'], $this->accounts);
        app(SettlementService::class)->payVendorBill($bill, ['paid_on' => '2026-11-16', 'amount' => '200', 'mode' => 'bank'], $this->accounts);

        $payments = array_values(array_filter($this->vouchers('HDFC Current A/c'), fn ($voucher) => $voucher['type'] === 'Payment'));

        $this->assertArrayHasKey('Cash', $this->entries($payments[0]));
        $this->assertArrayHasKey('HDFC Current A/c', $this->entries($payments[1]));
    }

    public function test_dates_outside_the_range_are_left_out(): void
    {
        $this->approvedVendorBill(['bill_date' => '2026-09-01']);

        $this->assertSame([], $this->vouchers());
    }

    public function test_voucher_xml_is_well_formed_and_uses_tally_signs(): void
    {
        $this->everything();

        $xml = new SimpleXMLElement($this->service()->vouchersXml($this->vouchers()));

        $this->assertSame('Import Data', (string) $xml->HEADER->TALLYREQUEST);
        $this->assertSame('Vouchers', (string) $xml->BODY->IMPORTDATA->REQUESTDESC->REPORTNAME);
        $messages = $xml->BODY->IMPORTDATA->REQUESTDATA->TALLYMESSAGE;
        $this->assertCount(8, $messages);

        foreach ($messages as $message) {
            $total = BigDecimal::zero();

            foreach ($message->VOUCHER->{'ALLLEDGERENTRIES.LIST'} as $entry) {
                $amount = Dec::of((string) $entry->AMOUNT);
                $total = $total->plus($amount);
                $this->assertSame((string) $entry->ISDEEMEDPOSITIVE === 'Yes', $amount->isNegative(), 'Debits are negative in Tally.');
            }

            $this->assertTrue($total->isZero());
        }
    }

    public function test_party_entries_carry_bill_references(): void
    {
        $this->everything();

        $xml = new SimpleXMLElement($this->service()->vouchersXml($this->vouchers()));
        $found = $xml->xpath('//VOUCHER[VOUCHERNUMBER="SSC-101"]/*[LEDGERNAME="Sai Steel & Cement"]/*[BILLTYPE]');

        $this->assertSame('SSC-101', (string) $found[0]->NAME);
        $this->assertSame('New Ref', (string) $found[0]->BILLTYPE);
        $this->assertSame('117000.00', (string) $found[0]->AMOUNT);
    }

    public function test_special_characters_are_escaped(): void
    {
        $this->everything();

        $raw = $this->service()->vouchersXml($this->vouchers());

        $this->assertStringContainsString('Sai Steel &amp; Cement', $raw);
        new SimpleXMLElement($raw);
    }

    public function test_every_ledger_a_voucher_uses_is_in_the_masters_file(): void
    {
        $this->everything();
        $vouchers = $this->vouchers();

        $ledgers = $this->service()->ledgers($this->organization->id, $vouchers);
        $names = array_column($ledgers, 'name');

        foreach ($vouchers as $voucher) {
            foreach ($voucher['entries'] as $entry) {
                $this->assertContains($entry['ledger'], $names);
            }
        }
    }

    public function test_masters_use_tally_groups_and_party_details(): void
    {
        $this->everything();
        $vouchers = $this->vouchers();

        $xml = new SimpleXMLElement($this->service()->ledgersXml($this->service()->ledgers($this->organization->id, $vouchers)));
        $parent = fn (string $name) => (string) $xml->xpath("//LEDGER[@NAME=\"{$name}\"]/PARENT")[0];

        $this->assertSame('All Masters', (string) $xml->BODY->IMPORTDATA->REQUESTDESC->REPORTNAME);
        $this->assertSame('Sundry Debtors', $parent('Metro Developers'));
        $this->assertSame('Sundry Creditors', $parent('Sai Steel & Cement'));
        $this->assertSame('Bank Accounts', $parent('Bank Account'));
        $this->assertSame('Cash-in-Hand', $parent('Site Petty Cash'));
        $this->assertSame('Sales Accounts', $parent('Contract Revenue'));
        $this->assertSame('Duties & Taxes', $parent('Output CGST'));
        $this->assertSame('Duties & Taxes', $parent('TDS Payable - 194C'));
        $this->assertSame('Direct Expenses', $parent('Materials'));
        $this->assertSame('AAFCS5678E', (string) $xml->xpath('//LEDGER[@NAME="Sai Steel & Cement"]/INCOMETAXNUMBER')[0]);
        $this->assertSame('Central Tax', (string) $xml->xpath('//LEDGER[@NAME="Output CGST"]/GSTDUTYHEAD')[0]);
    }

    public function test_two_parties_with_one_name_stay_separate_ledgers(): void
    {
        $twin = Party::create(['organization_id' => $this->organization->id, 'type' => 'vendor', 'name' => 'Sai Steel & Cement']);
        $this->approvedVendorBill();
        $this->approvedVendorBill(['party_id' => $twin->id, 'bill_no' => 'TWIN-1']);

        $names = array_column($this->service()->ledgers($this->organization->id, $this->vouchers()), 'name');

        $this->assertContains('Sai Steel & Cement ('.$this->vendor->id.')', $names);
        $this->assertContains('Sai Steel & Cement ('.$twin->id.')', $names);
    }

    public function test_another_company_is_not_included(): void
    {
        $this->approvedVendorBill();

        $this->assertSame([], $this->service()->vouchers($this->tenant('Other')->id, '2026-10-01', '2026-12-31'));
    }
}
