<?php

namespace App\Services\Finance;

use App\Models\GstInvoice;
use App\Models\Organization;
use App\Models\RaBill;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * GSTR-1 data from issued tax invoices, for a CA to check and file.
 * Columns follow the GST offline tool's Excel template and JSON field names.
 * Credit notes, exports, advances and nil-rated supplies are not recorded here, so those tables are absent.
 */
class Gstr1Service
{
    private const INVOICE_LABEL = 'Invoices for outward supply';

    /** @return array<string, mixed> */
    public function report(int $organizationId, string $month): array
    {
        $start = Carbon::createFromFormat('!Y-m', $month);
        $organization = Organization::findOrFail($organizationId);

        $all = GstInvoice::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereDate('invoice_date', '>=', $start->toDateString())
            ->whereDate('invoice_date', '<=', $start->copy()->endOfMonth()->toDateString())
            ->orderBy('serial')
            ->get();

        $issued = $all->where('status', 'ISSUED')->values();
        $limit = Dec::of(config('modules.finance.b2cl_limit'));

        [$b2b, $others] = $issued->partition(fn (GstInvoice $invoice) => filled($invoice->buyer_gstin));
        [$b2cl, $b2csInvoices] = $others->partition(fn (GstInvoice $invoice) => $invoice->isInterState() && Dec::of($invoice->invoice_value)->isGreaterThan($limit));

        $b2cs = $this->aggregateB2cs($b2csInvoices);
        $hsnB2b = $this->hsnRows($b2b);
        $hsnB2c = $this->hsnRows($b2cl->concat($b2csInvoices));

        $summary = [
            'b2b' => $this->totals($b2b),
            'b2cl' => $this->totals($b2cl),
            'b2cs' => $this->totals($b2csInvoices),
        ];
        $summary['total'] = $this->totals($issued);

        $hsnTotal = $this->addHsn($hsnB2b, $hsnB2c);

        return [
            'month' => $month,
            'label' => $start->format('F Y'),
            'fp' => $start->format('mY'),
            'gstin' => $issued->first()?->supplier_gstin ?? $organization->gstin,
            'b2b' => $b2b->values(),
            'b2cl' => $b2cl->values(),
            'b2cs' => $b2cs,
            'hsn_b2b' => $hsnB2b,
            'hsn_b2c' => $hsnB2c,
            'docs' => $this->documents($all),
            'summary' => $summary,
            'hsn_total' => $hsnTotal,
            'reconciled' => $this->reconciles($summary, $hsnTotal),
            'invoices' => $all,
            'uninvoiced_bills' => $this->uninvoicedBills($organizationId),
        ];
    }

    /** Amounts are written as JSON numbers from their exact decimal text, so no float rounding can touch them. */
    public function json(array $report): string
    {
        $data = [
            'gstin' => $report['gstin'],
            'fp' => $report['fp'],
            'b2b' => $report['b2b']->groupBy('buyer_gstin')->map(fn (Collection $invoices, string $gstin) => [
                'ctin' => $gstin,
                'inv' => $invoices->map(fn (GstInvoice $invoice) => [
                    'inum' => $invoice->invoice_no,
                    'idt' => $invoice->invoice_date->format('d-m-Y'),
                    'val' => $invoice->invoice_value,
                    'pos' => $invoice->place_of_supply_code,
                    'rchrg' => 'N',
                    'inv_typ' => 'R',
                    'itms' => [$this->item($invoice)],
                ])->values()->all(),
            ])->values()->all(),
            'b2cl' => $report['b2cl']->groupBy('place_of_supply_code')->map(fn (Collection $invoices, string $pos) => [
                'pos' => (string) $pos,
                'inv' => $invoices->map(fn (GstInvoice $invoice) => [
                    'inum' => $invoice->invoice_no,
                    'idt' => $invoice->invoice_date->format('d-m-Y'),
                    'val' => $invoice->invoice_value,
                    'itms' => [$this->item($invoice)],
                ])->values()->all(),
            ])->values()->all(),
            'b2cs' => array_map(fn (array $row) => [
                'sply_ty' => $row['inter'] ? 'INTER' : 'INTRA',
                'typ' => 'OE',
                'pos' => $row['pos'],
                'rt' => $row['rate'],
                'txval' => $row['taxable'],
                ...$this->taxFields($row['inter'], $row['igst'], $row['cgst'], $row['sgst']),
                'csamt' => '0.00',
            ], $report['b2cs']),
            'hsn' => [
                'hsn_b2b' => $this->jsonHsn($report['hsn_b2b']),
                'hsn_b2c' => $this->jsonHsn($report['hsn_b2c']),
            ],
            'doc_issue' => [
                'doc_det' => $report['docs'] === [] ? [] : [[
                    'doc_num' => 1,
                    'docs' => array_map(fn (array $row, int $index) => [
                        'num' => $index + 1,
                        'from' => $row['from'],
                        'to' => $row['to'],
                        'totnum' => $row['total'],
                        'cancel' => $row['cancelled'],
                        'net_issue' => $row['total'] - $row['cancelled'],
                    ], $report['docs'], array_keys($report['docs'])),
                ]],
            ],
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return preg_replace('/"(val|rt|txval|iamt|camt|samt|csamt|qty)": "(-?\d+(?:\.\d+)?)"/', '"$1": $2', $json);
    }

    public function spreadsheet(array $report): Spreadsheet
    {
        $b2b = $report['b2b'];
        $s = $report['summary'];

        return Workbook::build([
            [
                'title' => 'b2b,sez,de',
                'top' => [
                    ['Summary For B2B, SEZ, DE (4A, 4B, 6B, 6C)'],
                    ['No. of Recipients', null, 'No. of Invoices', null, 'Total Invoice Value', null, null, null, null, null, null, 'Total Taxable Value', 'Total Cess'],
                    [$b2b->pluck('buyer_gstin')->unique()->count(), null, $b2b->count(), null, $s['b2b']['value'], null, null, null, null, null, null, $s['b2b']['taxable'], '0.00'],
                ],
                'headers' => ['GSTIN/UIN of Recipient', 'Receiver Name', 'Invoice Number', 'Invoice date', 'Invoice Value', 'Place Of Supply', 'Reverse Charge', 'Applicable % of Tax Rate', 'Invoice Type', 'E-Commerce GSTIN', 'Rate', 'Taxable Value', 'Cess Amount'],
                'numeric' => [4, 10, 11, 12],
                'rows' => $b2b->map(fn (GstInvoice $invoice) => [
                    $invoice->buyer_gstin, $invoice->buyer_name, $invoice->invoice_no, $this->excelDate($invoice), $invoice->invoice_value,
                    $this->placeOfSupply($invoice->place_of_supply_code), 'N', null, 'Regular B2B', null, $invoice->gst_pct, $invoice->taxable_amount, '0.00',
                ])->all(),
            ],
            [
                'title' => 'b2cl',
                'top' => [
                    ['Summary For B2CL (5)'],
                    ['No. of Invoices', null, 'Total Invoice Value', null, null, null, 'Total Taxable Value', 'Total Cess'],
                    [$report['b2cl']->count(), null, $s['b2cl']['value'], null, null, null, $s['b2cl']['taxable'], '0.00'],
                ],
                'headers' => ['Invoice Number', 'Invoice date', 'Invoice Value', 'Place Of Supply', 'Applicable % of Tax Rate', 'Rate', 'Taxable Value', 'Cess Amount', 'E-Commerce GSTIN'],
                'numeric' => [2, 5, 6, 7],
                'rows' => $report['b2cl']->map(fn (GstInvoice $invoice) => [
                    $invoice->invoice_no, $this->excelDate($invoice), $invoice->invoice_value, $this->placeOfSupply($invoice->place_of_supply_code),
                    null, $invoice->gst_pct, $invoice->taxable_amount, '0.00', null,
                ])->all(),
            ],
            [
                'title' => 'b2cs',
                'top' => [
                    ['Summary For B2CS (7)'],
                    [null, null, null, null, 'Total Taxable Value', 'Total Cess'],
                    [null, null, null, null, $s['b2cs']['taxable'], '0.00'],
                ],
                'headers' => ['Type', 'Place Of Supply', 'Applicable % of Tax Rate', 'Rate', 'Taxable Value', 'Cess Amount', 'E-Commerce GSTIN'],
                'numeric' => [3, 4, 5],
                'rows' => array_map(fn (array $row) => ['OE', $this->placeOfSupply($row['pos']), null, $row['rate'], $row['taxable'], '0.00', null], $report['b2cs']),
            ],
            $this->hsnSheet('hsn(b2b)', 'Summary For HSN (12) - B2B', $report['hsn_b2b']),
            $this->hsnSheet('hsn(b2c)', 'Summary For HSN (12) - B2C', $report['hsn_b2c']),
            [
                'title' => 'docs',
                'top' => [
                    ['Summary of documents issued during the tax period (13)'],
                    [null, null, null, 'Total Number', 'Total Cancelled'],
                    [null, null, null, array_sum(array_column($report['docs'], 'total')), array_sum(array_column($report['docs'], 'cancelled'))],
                ],
                'headers' => ['Nature of Document', 'Sr. No. From', 'Sr. No. To', 'Total Number', 'Cancelled'],
                'numeric' => [3, 4],
                'rows' => array_map(fn (array $row) => [self::INVOICE_LABEL, $row['from'], $row['to'], $row['total'], $row['cancelled']], $report['docs']),
            ],
            [
                'title' => 'Read me',
                'headers' => ['Data for your CA to check'],
                'rows' => [
                    ['Prepared by Nirmaan360 for period '.$report['label'].', GSTIN '.$report['gstin'].'.'],
                    ['This is working data for a Chartered Accountant to check. It is not a filing and nothing has been sent to the GST portal.'],
                    ['Only tax invoices issued in Nirmaan360 are included. Check against your books and the current GST offline tool before filing.'],
                ],
            ],
        ]);
    }

    private function hsnSheet(string $title, string $summaryTitle, array $rows): array
    {
        $total = $this->addHsn($rows, []);

        return [
            'title' => $title,
            'top' => [
                [$summaryTitle],
                ['No. of HSN', null, null, null, 'Total Value', null, 'Total Taxable Value', 'Total Integrated Tax', 'Total Central Tax', 'Total State/UT Tax', 'Total Cess'],
                [count($rows), null, null, null, $total['value'], null, $total['taxable'], $total['igst'], $total['cgst'], $total['sgst'], '0.00'],
            ],
            'headers' => ['HSN', 'Description', 'UQC', 'Total Quantity', 'Total Value', 'Rate', 'Taxable Value', 'Integrated Tax Amount', 'Central Tax Amount', 'State/UT Tax Amount', 'Cess Amount'],
            'numeric' => [3, 4, 5, 6, 7, 8, 9, 10],
            'rows' => array_map(fn (array $row) => [$row['sac'], $row['description'], 'NA', 0, $row['value'], $row['rate'], $row['taxable'], $row['igst'], $row['cgst'], $row['sgst'], '0.00'], $rows),
        ];
    }

    private function item(GstInvoice $invoice): array
    {
        return [
            // The offline tool numbers a line by its rate: 18% is 1801.
            'num' => (int) str_replace('.', '', $invoice->gst_pct) + 1,
            'itm_det' => [
                'rt' => $invoice->gst_pct,
                'txval' => $invoice->taxable_amount,
                ...$this->taxFields($invoice->isInterState(), $invoice->igst_amount, $invoice->cgst_amount, $invoice->sgst_amount),
                'csamt' => '0.00',
            ],
        ];
    }

    private function taxFields(bool $inter, string $igst, string $cgst, string $sgst): array
    {
        return $inter ? ['iamt' => $igst] : ['camt' => $cgst, 'samt' => $sgst];
    }

    private function jsonHsn(array $rows): array
    {
        return array_map(fn (array $row, int $index) => [
            'hsn_sc' => $row['sac'],
            'num' => $index + 1,
            'desc' => $row['description'],
            'uqc' => 'NA',
            'qty' => '0',
            'rt' => $row['rate'],
            'txval' => $row['taxable'],
            'iamt' => $row['igst'],
            'camt' => $row['cgst'],
            'samt' => $row['sgst'],
            'csamt' => '0.00',
        ], $rows, array_keys($rows));
    }

    /** @return list<array{pos:string, rate:string, inter:bool, taxable:string, igst:string, cgst:string, sgst:string}> */
    private function aggregateB2cs(Collection $invoices): array
    {
        return $invoices
            ->groupBy(fn (GstInvoice $invoice) => $invoice->place_of_supply_code.'|'.$invoice->gst_pct.'|'.$invoice->supply_type)
            ->map(function (Collection $group) {
                $first = $group->first();
                $totals = $this->totals($group);

                return [
                    'pos' => $first->place_of_supply_code,
                    'rate' => $first->gst_pct,
                    'inter' => $first->isInterState(),
                    'taxable' => $totals['taxable'],
                    'igst' => $totals['igst'],
                    'cgst' => $totals['cgst'],
                    'sgst' => $totals['sgst'],
                ];
            })->values()->all();
    }

    private function hsnRows(Collection $invoices): array
    {
        return $invoices
            ->groupBy(fn (GstInvoice $invoice) => $invoice->sac.'|'.$invoice->gst_pct)
            ->map(function (Collection $group) {
                $first = $group->first();

                return ['sac' => $first->sac, 'description' => $first->description, 'rate' => $first->gst_pct, ...$this->totals($group)];
            })->values()->all();
    }

    /** @return array{count:int, taxable:string, igst:string, cgst:string, sgst:string, value:string} */
    private function totals(Collection $invoices): array
    {
        $add = fn (string $column) => Dec::money($invoices->reduce(fn (BigDecimal $sum, GstInvoice $invoice) => $sum->plus(Dec::of($invoice->{$column})), BigDecimal::zero()));

        return [
            'count' => $invoices->count(),
            'taxable' => $add('taxable_amount'),
            'igst' => $add('igst_amount'),
            'cgst' => $add('cgst_amount'),
            'sgst' => $add('sgst_amount'),
            'value' => $add('invoice_value'),
        ];
    }

    private function addHsn(array ...$lists): array
    {
        $rows = array_merge(...$lists);
        $add = fn (string $key) => Dec::money(array_reduce($rows, fn (BigDecimal $sum, array $row) => $sum->plus(Dec::of($row[$key])), BigDecimal::zero()));

        return ['taxable' => $add('taxable'), 'igst' => $add('igst'), 'cgst' => $add('cgst'), 'sgst' => $add('sgst'), 'value' => $add('value')];
    }

    /** The sections and the HSN summary must each add up to the invoices. */
    private function reconciles(array $summary, array $hsnTotal): bool
    {
        foreach (['taxable', 'igst', 'cgst', 'sgst', 'value'] as $key) {
            $sections = Dec::of($summary['b2b'][$key])->plus(Dec::of($summary['b2cl'][$key]))->plus(Dec::of($summary['b2cs'][$key]));

            if (! $sections->isEqualTo(Dec::of($summary['total'][$key])) || ! Dec::of($hsnTotal[$key])->isEqualTo(Dec::of($summary['total'][$key]))) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{from:string, to:string, total:int, cancelled:int}> One line per financial year's number series. */
    private function documents(Collection $invoices): array
    {
        return $invoices->groupBy('financial_year')->map(fn (Collection $group) => [
            'from' => $group->sortBy('serial')->first()->invoice_no,
            'to' => $group->sortBy('serial')->last()->invoice_no,
            'total' => $group->count(),
            'cancelled' => $group->where('status', 'CANCELLED')->count(),
        ])->values()->all();
    }

    private function excelDate(GstInvoice $invoice): string
    {
        return $invoice->invoice_date->format('d-M-Y');
    }

    private function placeOfSupply(string $code): string
    {
        return $code.'-'.(Gst::STATES[$code] ?? '');
    }

    /** Approved client bills with GST that have no live tax invoice, so a CA can see what is missing. */
    private function uninvoicedBills(int $organizationId): int
    {
        return RaBill::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('type', 'client')
            ->whereIn('status', SettlementService::BILLED)
            ->where('gst_amount', '>', 0)
            ->whereNotIn('id', GstInvoice::withoutGlobalScopes()->where('organization_id', $organizationId)->where('status', 'ISSUED')->select('ra_bill_id'))
            ->count();
    }
}
