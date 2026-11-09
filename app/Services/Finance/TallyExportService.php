<?php

namespace App\Services\Finance;

use App\Models\ClientReceipt;
use App\Models\GstInvoice;
use App\Models\Organization;
use App\Models\Party;
use App\Models\PettyCashEntry;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\VendorBill;
use App\Models\VendorPayment;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use XMLWriter;

/**
 * Builds Tally-compatible XML files that an accountant imports by hand.
 * There is no live link: a two-way connector needs software running beside Tally on the customer's PC.
 *
 * Inside this class a positive amount is a debit and a negative amount is a credit,
 * so every voucher must add up to zero. Tally writes debits as negative, which only the XML step handles.
 */
class TallyExportService
{
    public const DEFAULT_BANK_LEDGER = 'Bank Account';

    private const CASH = 'Cash';

    private const PETTY_CASH = 'Site Petty Cash';

    private const REVENUE = 'Contract Revenue';

    private const TDS_RECEIVABLE = 'TDS Receivable';

    private const RETENTION_RECEIVABLE = 'Retention Receivable';

    private const RETENTION_PAYABLE = 'Retention Payable';

    private const CLIENT_ADVANCE = 'Mobilisation Advance from Clients';

    private const CLIENT_MATERIAL = 'Material Recovered by Clients';

    private const SUB_CHARGES = 'Subcontractor Charges';

    private const SUB_ADVANCE = 'Advances to Subcontractors';

    private const SUB_MATERIAL = 'Material Recovered from Subcontractors';

    /** @var array<string, string> Ledger name => Tally group. */
    private const GROUPS = [
        self::CASH => 'Cash-in-Hand',
        self::PETTY_CASH => 'Cash-in-Hand',
        self::REVENUE => 'Sales Accounts',
        self::TDS_RECEIVABLE => 'Loans & Advances (Asset)',
        self::RETENTION_RECEIVABLE => 'Loans & Advances (Asset)',
        self::RETENTION_PAYABLE => 'Current Liabilities',
        self::CLIENT_ADVANCE => 'Current Liabilities',
        self::CLIENT_MATERIAL => 'Direct Expenses',
        self::SUB_CHARGES => 'Direct Expenses',
        self::SUB_ADVANCE => 'Loans & Advances (Asset)',
        self::SUB_MATERIAL => 'Direct Expenses',
    ];

    public function __construct(private SettlementService $settlement) {}

    /** @return list<array{type:string, date:string, number:string, reference:string, party:?string, narration:string, entries:list<array{ledger:string, amount:string, party_id:?int, bill:?array{name:string, type:string}}>}> */
    public function vouchers(int $organizationId, string $from, string $to, ?int $projectId = null, ?string $bankLedger = null): array
    {
        $bank = filled($bankLedger) ? trim($bankLedger) : self::DEFAULT_BANK_LEDGER;
        $names = $this->partyNames($organizationId);
        $organization = Organization::findOrFail($organizationId);
        $vouchers = [];

        $invoices = GstInvoice::withoutGlobalScopes()->where('organization_id', $organizationId)->where('status', 'ISSUED')->get()->keyBy('ra_bill_id');

        foreach ($this->settlement->clientBills($organizationId, $projectId) as $row) {
            $bill = $row['bill'];
            $invoice = $invoices->get($bill->id);
            $date = ($invoice?->invoice_date ?? $bill->approved_at ?? $bill->period_to)->toDateString();

            if ($this->inRange($date, $from, $to)) {
                $vouchers[] = $this->clientBillVoucher($row, $invoice, $date, $names, $organization);
            }
        }

        foreach ($this->settlement->payableBills($organizationId, $projectId) as $row) {
            $bill = $row['bill'];
            $date = ($row['kind'] === 'vendor' ? $bill->bill_date : ($bill->approved_at ?? $bill->period_to))->toDateString();

            if ($this->inRange($date, $from, $to)) {
                $vouchers[] = $row['kind'] === 'vendor'
                    ? $this->vendorBillVoucher($bill, $date, $names)
                    : $this->subBillVoucher($bill, $date, $names, $organization);
            }
        }

        foreach ($this->dated(ClientReceipt::withoutGlobalScopes()->with('raBill', 'project'), 'received_on', $organizationId, $from, $to, $projectId) as $receipt) {
            $vouchers[] = $this->receiptVoucher($receipt, $names, $bank, $invoices);
        }

        foreach ($this->dated(VendorPayment::withoutGlobalScopes()->with('vendorBill', 'raBill', 'project'), 'paid_on', $organizationId, $from, $to, $projectId) as $payment) {
            $vouchers[] = $this->paymentVoucher($payment, $names, $bank);
        }

        $projectCodes = Project::withoutGlobalScopes()->withTrashed()->where('organization_id', $organizationId)->pluck('code', 'id');

        foreach ($this->pettyEntries($organizationId, $from, $to, $projectId) as $entry) {
            $vouchers[] = $this->pettyVoucher($entry, $bank, (string) $projectCodes->get($entry->wallet?->project_id));
        }

        usort($vouchers, fn ($a, $b) => [$a['date'], $a['number']] <=> [$b['date'], $b['number']]);

        return $vouchers;
    }

    /** Every ledger the vouchers use, so importing masters first means no voucher fails on a missing ledger. */
    public function ledgers(int $organizationId, array $vouchers, ?string $bankLedger = null): array
    {
        $bank = filled($bankLedger) ? trim($bankLedger) : self::DEFAULT_BANK_LEDGER;
        $parties = Party::withoutGlobalScopes()->withTrashed()->where('organization_id', $organizationId)->get()->keyBy('id');
        $ledgers = [];

        foreach ($vouchers as $voucher) {
            foreach ($voucher['entries'] as $entry) {
                $name = $entry['ledger'];

                if (isset($ledgers[$name])) {
                    continue;
                }

                $party = $entry['party_id'] ? $parties->get($entry['party_id']) : null;

                $ledgers[$name] = [
                    'name' => $name,
                    'parent' => $party ? ($party->type === 'client' ? 'Sundry Debtors' : 'Sundry Creditors') : $this->groupFor($name, $bank),
                    'bill_wise' => $party !== null,
                    'gstin' => $party?->gstin,
                    'pan' => $party?->pan,
                    'address' => $party?->address,
                    'gst_head' => $this->gstHead($name),
                ];
            }
        }

        ksort($ledgers);

        return array_values($ledgers);
    }

    public function vouchersXml(array $vouchers): string
    {
        return $this->envelope('Vouchers', function (XMLWriter $xml) use ($vouchers) {
            foreach ($vouchers as $voucher) {
                $xml->startElement('TALLYMESSAGE');
                $xml->writeAttribute('xmlns:UDF', 'TallyUDF');
                $xml->startElement('VOUCHER');
                $xml->writeAttribute('VCHTYPE', $voucher['type']);
                $xml->writeAttribute('ACTION', 'Create');
                $xml->writeAttribute('OBJVIEW', 'Accounting Voucher View');
                $this->text($xml, 'DATE', str_replace('-', '', $voucher['date']));
                $this->text($xml, 'NARRATION', $voucher['narration']);
                $this->text($xml, 'VOUCHERTYPENAME', $voucher['type']);
                $this->text($xml, 'VOUCHERNUMBER', $voucher['number']);
                $this->text($xml, 'REFERENCE', $voucher['reference']);

                if ($voucher['party']) {
                    $this->text($xml, 'PARTYLEDGERNAME', $voucher['party']);
                }

                $this->text($xml, 'PERSISTEDVIEW', 'Accounting Voucher View');

                foreach ($voucher['entries'] as $entry) {
                    $this->ledgerEntry($xml, $entry);
                }

                $xml->endElement();
                $xml->endElement();
            }
        });
    }

    public function ledgersXml(array $ledgers): string
    {
        return $this->envelope('All Masters', function (XMLWriter $xml) use ($ledgers) {
            foreach ($ledgers as $ledger) {
                $xml->startElement('TALLYMESSAGE');
                $xml->writeAttribute('xmlns:UDF', 'TallyUDF');
                $xml->startElement('LEDGER');
                $xml->writeAttribute('NAME', $ledger['name']);
                $xml->writeAttribute('ACTION', 'Create');
                $xml->startElement('NAME.LIST');
                $xml->writeAttribute('TYPE', 'String');
                $this->text($xml, 'NAME', $ledger['name']);
                $xml->endElement();
                $this->text($xml, 'PARENT', $ledger['parent']);
                $this->text($xml, 'ISBILLWISEON', $ledger['bill_wise'] ? 'Yes' : 'No');

                if ($ledger['gst_head']) {
                    $this->text($xml, 'TAXTYPE', 'GST');
                    $this->text($xml, 'GSTDUTYHEAD', $ledger['gst_head']);
                }

                if ($ledger['gstin']) {
                    $this->text($xml, 'PARTYGSTIN', $ledger['gstin']);
                }

                if ($ledger['pan']) {
                    $this->text($xml, 'INCOMETAXNUMBER', $ledger['pan']);
                }

                if ($ledger['address']) {
                    $xml->startElement('ADDRESS.LIST');
                    $xml->writeAttribute('TYPE', 'String');
                    $this->text($xml, 'ADDRESS', $ledger['address']);
                    $xml->endElement();
                }

                $xml->endElement();
                $xml->endElement();
            }
        });
    }

    private function clientBillVoucher(array $row, ?GstInvoice $invoice, string $date, array $names, Organization $organization): array
    {
        $bill = $row['bill'];
        $number = $invoice?->invoice_no ?? $bill->number;
        $party = $names[$bill->party_id] ?? 'Unnamed Client';
        $parts = $invoice
            ? ['cgst' => $invoice->cgst_amount, 'sgst' => $invoice->sgst_amount, 'igst' => $invoice->igst_amount]
            : Gst::split($bill->gst_amount, $this->isInterState($organization, $bill->project));

        $entries = [
            $this->party($party, $bill->party_id, $row['receivable'], $number, 'New Ref'),
            $this->line(self::RETENTION_RECEIVABLE, $this->deduction($bill, 'retention')),
            $this->line(self::CLIENT_ADVANCE, $this->deduction($bill, 'advance')),
            $this->line(self::CLIENT_MATERIAL, $this->deduction($bill, 'material')),
            $this->line(self::REVENUE, $this->negate($bill->gross_amount)),
            $this->line('Output CGST', $this->negate($parts['cgst'])),
            $this->line('Output SGST', $this->negate($parts['sgst'])),
            $this->line('Output IGST', $this->negate($parts['igst'])),
        ];

        return $this->voucher('Sales', $date, $number, $bill->number, $party, 'RA bill '.$bill->number.' for project '.$bill->project?->code, $entries);
    }

    private function vendorBillVoucher(VendorBill $bill, string $date, array $names): array
    {
        $party = $names[$bill->party_id] ?? 'Unnamed Vendor';

        $entries = [
            $this->party($party, $bill->party_id, $this->negate($bill->net_payable), $bill->bill_no, 'New Ref'),
            $this->line($bill->cost_head, $bill->taxable_amount),
            $this->line('Input CGST', $bill->cgst_amount),
            $this->line('Input SGST', $bill->sgst_amount),
            $this->line('Input IGST', $bill->igst_amount),
            $this->line($this->tdsPayable($bill->tds_section), $this->negate($bill->tds_amount)),
        ];

        return $this->voucher($bill->reversal_of_id ? 'Journal' : 'Purchase', $date, $bill->bill_no, $bill->bill_no, $party, 'Vendor bill '.$bill->bill_no.' for project '.$bill->project?->code, $entries);
    }

    private function subBillVoucher(RaBill $bill, string $date, array $names, Organization $organization): array
    {
        $party = $names[$bill->party_id] ?? 'Unnamed Subcontractor';
        $parts = Gst::split($bill->gst_amount, $this->isInterState($organization, $bill->project));

        $entries = [
            $this->party($party, $bill->party_id, $this->negate($bill->net_payable), $bill->number, 'New Ref'),
            $this->line(self::SUB_CHARGES, $bill->gross_amount),
            $this->line('Input CGST', $parts['cgst']),
            $this->line('Input SGST', $parts['sgst']),
            $this->line('Input IGST', $parts['igst']),
            $this->line(self::RETENTION_PAYABLE, $this->negate($this->deduction($bill, 'retention'))),
            $this->line(self::SUB_ADVANCE, $this->negate($this->deduction($bill, 'advance'))),
            $this->line(self::SUB_MATERIAL, $this->negate($this->deduction($bill, 'material'))),
            $this->line($this->tdsPayable('194C'), $this->negate($this->deduction($bill, 'tds'))),
        ];

        return $this->voucher('Purchase', $date, $bill->number, $bill->number, $party, 'Subcontractor RA bill '.$bill->number.' for project '.$bill->project?->code, $entries);
    }

    private function receiptVoucher(ClientReceipt $receipt, array $names, string $bank, Collection $invoices): array
    {
        $party = $names[$receipt->party_id] ?? 'Unnamed Client';
        $reference = $invoices->get($receipt->ra_bill_id)?->invoice_no ?? (string) $receipt->raBill?->number;

        $entries = [
            $this->line($this->moneyLedger($receipt->mode, $bank), $receipt->amount),
            $this->line(self::TDS_RECEIVABLE, $receipt->tds_amount),
            $this->party($party, $receipt->party_id, $this->negate($receipt->settled()), $reference, 'Agst Ref'),
        ];

        return $this->voucher($receipt->isReversal() ? 'Payment' : 'Receipt', $receipt->received_on->toDateString(), $receipt->voucherNo(), (string) $receipt->reference, $party, 'Receipt against '.$reference.' for project '.$receipt->project?->code, $entries);
    }

    private function paymentVoucher(VendorPayment $payment, array $names, string $bank): array
    {
        $party = $names[$payment->party_id] ?? 'Unnamed Vendor';
        $reference = $payment->documentNo();

        $entries = [
            $this->party($party, $payment->party_id, $payment->amount, $reference, 'Agst Ref'),
            $this->line($this->moneyLedger($payment->mode, $bank), $this->negate($payment->amount)),
        ];

        return $this->voucher($payment->isReversal() ? 'Receipt' : 'Payment', $payment->paid_on->toDateString(), $payment->voucherNo(), (string) $payment->reference, $party, 'Payment against '.$reference.' for project '.$payment->project?->code, $entries);
    }

    private function pettyVoucher(PettyCashEntry $entry, string $bank, string $code): array
    {
        $number = 'PC-'.$entry->id;

        if ($entry->type === 'topup') {
            return $this->voucher('Contra', $entry->entry_date->toDateString(), $number, $number, null, 'Cash to site wallet for project '.$code, [
                $this->line(self::PETTY_CASH, $entry->amount),
                $this->line($bank, $this->negate($entry->amount)),
            ]);
        }

        return $this->voucher('Payment', $entry->entry_date->toDateString(), $number, $number, null, trim('Site expense for project '.$code.' '.$entry->description), [
            $this->line('Site Expenses - '.($entry->category ?? 'Other'), $entry->amount),
            $this->line(self::PETTY_CASH, $this->negate($entry->amount)),
        ]);
    }

    private function voucher(string $type, string $date, string $number, string $reference, ?string $party, string $narration, array $entries): array
    {
        return [
            'type' => $type,
            'date' => $date,
            'number' => $number,
            'reference' => $reference,
            'party' => $party,
            'narration' => $narration,
            'entries' => array_values(array_filter($entries, fn (array $entry) => ! Dec::of($entry['amount'])->isZero())),
        ];
    }

    private function line(string $ledger, mixed $amount): array
    {
        return ['ledger' => $ledger, 'amount' => Dec::money($amount), 'party_id' => null, 'bill' => null];
    }

    private function party(string $ledger, ?int $partyId, mixed $amount, string $billName, string $billType): array
    {
        return ['ledger' => $ledger, 'amount' => Dec::money($amount), 'party_id' => $partyId, 'bill' => ['name' => $billName, 'type' => $billType]];
    }

    private function negate(mixed $amount): string
    {
        return Dec::money(BigDecimal::zero()->minus(Dec::of($amount)));
    }

    private function deduction(RaBill $bill, string $type): string
    {
        return Dec::money($bill->deductions->where('type', $type)->reduce(fn (BigDecimal $sum, $row) => $sum->plus(Dec::of($row->amount)), BigDecimal::zero()));
    }

    private function tdsPayable(?string $section): string
    {
        return 'TDS Payable - '.($section ?? 'Other');
    }

    private function moneyLedger(string $mode, string $bank): string
    {
        return $mode === 'cash' ? self::CASH : $bank;
    }

    private function inRange(string $date, string $from, string $to): bool
    {
        return $date >= $from && $date <= $to;
    }

    private function isInterState(Organization $organization, ?Project $project): bool
    {
        $supplier = Gst::codeFromGstin($organization->gstin) ?? Gst::stateCode($organization->state);
        $place = Gst::stateCode($project?->state);

        return $supplier !== null && $place !== null && $supplier !== $place;
    }

    private function dated($query, string $column, int $organizationId, string $from, string $to, ?int $projectId): Collection
    {
        return $query->where('organization_id', $organizationId)
            ->whereDate($column, '>=', $from)
            ->whereDate($column, '<=', $to)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->orderBy('id')
            ->get();
    }

    private function pettyEntries(int $organizationId, string $from, string $to, ?int $projectId): Collection
    {
        return PettyCashEntry::withoutGlobalScopes()
            ->with('wallet')
            ->where('organization_id', $organizationId)
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->when($projectId, fn ($q) => $q->whereHas('wallet', fn ($wallet) => $wallet->where('project_id', $projectId)))
            ->orderBy('id')
            ->get();
    }

    /** @return array<int, string> Two parties with one name get their id added, or Tally would merge them. */
    private function partyNames(int $organizationId): array
    {
        $parties = Party::withoutGlobalScopes()->withTrashed()->where('organization_id', $organizationId)->get(['id', 'name']);
        $counts = $parties->countBy('name');

        return $parties->mapWithKeys(fn (Party $party) => [
            $party->id => $counts[$party->name] > 1 ? $party->name.' ('.$party->id.')' : $party->name,
        ])->all();
    }

    private function groupFor(string $name, string $bank): string
    {
        return match (true) {
            $name === $bank => 'Bank Accounts',
            isset(self::GROUPS[$name]) => self::GROUPS[$name],
            str_starts_with($name, 'TDS Payable'), $this->gstHead($name) !== null => 'Duties & Taxes',
            default => 'Direct Expenses',
        };
    }

    private function gstHead(string $name): ?string
    {
        return match (substr($name, -4)) {
            'CGST' => 'Central Tax',
            'SGST' => 'State Tax',
            'IGST' => 'Integrated Tax',
            default => null,
        };
    }

    private function ledgerEntry(XMLWriter $xml, array $entry): void
    {
        $amount = Dec::of($entry['amount']);
        $isDebit = $amount->isPositive();
        $written = $this->negate($entry['amount']);

        $xml->startElement('ALLLEDGERENTRIES.LIST');
        $this->text($xml, 'LEDGERNAME', $entry['ledger']);
        $this->text($xml, 'ISDEEMEDPOSITIVE', $isDebit ? 'Yes' : 'No');

        if ($entry['bill']) {
            $this->text($xml, 'ISPARTYLEDGER', 'Yes');
        }

        $this->text($xml, 'AMOUNT', $written);

        if ($entry['bill']) {
            $xml->startElement('BILLALLOCATIONS.LIST');
            $this->text($xml, 'NAME', $entry['bill']['name']);
            $this->text($xml, 'BILLTYPE', $entry['bill']['type']);
            $this->text($xml, 'AMOUNT', $written);
            $xml->endElement();
        }

        $xml->endElement();
    }

    private function text(XMLWriter $xml, string $element, string $value): void
    {
        $xml->writeElement($element, preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value));
    }

    private function envelope(string $report, callable $body): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('ENVELOPE');
        $xml->startElement('HEADER');
        $this->text($xml, 'TALLYREQUEST', 'Import Data');
        $xml->endElement();
        $xml->startElement('BODY');
        $xml->startElement('IMPORTDATA');
        $xml->startElement('REQUESTDESC');
        $this->text($xml, 'REPORTNAME', $report);
        $xml->endElement();
        $xml->startElement('REQUESTDATA');
        $body($xml);
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
