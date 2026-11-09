<?php

namespace App\Services\Finance;

use App\Models\ClientReceipt;
use App\Models\RaBill;
use App\Models\VendorBill;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/** TDS we deducted from vendors and subcontractors, and TDS clients deducted from us, for a CA to check. */
class TdsReportService
{
    /** Subcontractor RA bills do not carry a section. Contract work falls under this one. */
    private const SUBCONTRACTOR_SECTION = '194C';

    public function report(int $organizationId, string $from, string $to): array
    {
        $deducted = collect();

        $vendorBills = VendorBill::withoutGlobalScopes()
            ->with('party', 'project')
            ->where('organization_id', $organizationId)
            ->whereIn('status', VendorBill::POSTED)
            ->whereDate('bill_date', '>=', $from)
            ->whereDate('bill_date', '<=', $to)
            ->get();

        foreach ($vendorBills as $bill) {
            if (! Dec::of($bill->tds_amount)->isZero()) {
                $deducted->push([
                    'date' => $bill->bill_date->toDateString(),
                    'party' => $bill->party?->name,
                    'pan' => $bill->party?->pan,
                    'section' => $bill->tds_section ?? '—',
                    'document' => $bill->bill_no,
                    'project' => $bill->project?->code,
                    'base' => $bill->taxable_amount,
                    'rate' => $bill->tds_pct,
                    'tds' => $bill->tds_amount,
                ]);
            }
        }

        $subBills = RaBill::withoutGlobalScopes()
            ->with('deductions', 'party', 'project')
            ->where('organization_id', $organizationId)
            ->where('type', 'subcontractor')
            ->whereIn('status', SettlementService::BILLED)
            ->whereDate('approved_at', '>=', $from)
            ->whereDate('approved_at', '<=', $to)
            ->get();

        foreach ($subBills as $bill) {
            foreach ($bill->deductions->where('type', 'tds') as $deduction) {
                $deducted->push([
                    'date' => $bill->approved_at->toDateString(),
                    'party' => $bill->party?->name,
                    'pan' => $bill->party?->pan,
                    'section' => self::SUBCONTRACTOR_SECTION,
                    'document' => $bill->number,
                    'project' => $bill->project?->code,
                    'base' => $bill->gross_amount,
                    'rate' => $deduction->pct,
                    'tds' => $deduction->amount,
                ]);
            }
        }

        $deducted = $deducted->sortBy(['date', 'document'])->values();

        $receipts = ClientReceipt::withoutGlobalScopes()
            ->with('party', 'project', 'raBill')
            ->where('organization_id', $organizationId)
            ->whereDate('received_on', '>=', $from)
            ->whereDate('received_on', '<=', $to)
            ->orderBy('received_on')
            ->orderBy('id')
            ->get()
            ->reject(fn (ClientReceipt $receipt) => Dec::of($receipt->tds_amount)->isZero())
            ->map(fn (ClientReceipt $receipt) => [
                'date' => $receipt->received_on->toDateString(),
                'party' => $receipt->party?->name,
                'pan' => $receipt->party?->pan,
                'voucher' => $receipt->voucherNo(),
                'document' => $receipt->raBill?->number,
                'project' => $receipt->project?->code,
                'received' => $receipt->amount,
                'tds' => $receipt->tds_amount,
                'settled' => $receipt->settled(),
            ])->values();

        $bySection = $deducted->groupBy('section')->map(fn ($rows, $section) => [
            'section' => (string) $section,
            'count' => $rows->count(),
            'base' => $this->sum($rows, 'base'),
            'tds' => $this->sum($rows, 'tds'),
        ])->sortKeys()->values()->all();

        return [
            'from' => $from,
            'to' => $to,
            'deducted' => $deducted,
            'deducted_base' => $this->sum($deducted, 'base'),
            'deducted_total' => $this->sum($deducted, 'tds'),
            'by_section' => $bySection,
            'from_clients' => $receipts,
            'client_received' => $this->sum($receipts, 'received'),
            'client_total' => $this->sum($receipts, 'tds'),
            'missing_pan' => $deducted->filter(fn ($row) => blank($row['pan']))->pluck('party')->unique()->count(),
        ];
    }

    public function spreadsheet(array $report): Spreadsheet
    {
        return Workbook::build([
            [
                'title' => 'TDS deducted by us',
                'headers' => ['Date', 'Deductee', 'PAN', 'Section', 'Document', 'Project', 'Amount credited', 'Rate %', 'TDS'],
                'numeric' => [6, 7, 8],
                'rows' => [
                    ...$report['deducted']->map(fn ($row) => [$row['date'], $row['party'], $row['pan'], $row['section'], $row['document'], $row['project'], $row['base'], $row['rate'], $row['tds']])->all(),
                    ['Total', null, null, null, null, null, $report['deducted_base'], null, $report['deducted_total']],
                ],
            ],
            [
                'title' => 'TDS deducted by clients',
                'headers' => ['Date', 'Client', 'PAN', 'Receipt', 'RA bill', 'Project', 'Received in bank', 'TDS'],
                'numeric' => [6, 7],
                'rows' => [
                    ...$report['from_clients']->map(fn ($row) => [$row['date'], $row['party'], $row['pan'], $row['voucher'], $row['document'], $row['project'], $row['received'], $row['tds']])->all(),
                    ['Total', null, null, null, null, null, $report['client_received'], $report['client_total']],
                ],
            ],
            [
                'title' => 'Read me',
                'headers' => ['Data for your CA to check'],
                'rows' => [
                    ['TDS working data for '.$report['from'].' to '.$report['to'].' from Nirmaan360.'],
                    ['This is not a return or a challan. A Chartered Accountant should check it against your books, Form 26AS and TRACES.'],
                ],
            ],
        ]);
    }

    private function sum($rows, string $key): string
    {
        return Dec::money($rows->reduce(fn (BigDecimal $sum, array $row) => $sum->plus(Dec::of($row[$key])), BigDecimal::zero()));
    }
}
