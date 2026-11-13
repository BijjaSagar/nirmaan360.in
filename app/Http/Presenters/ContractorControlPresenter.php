<?php

namespace App\Http\Presenters;

use App\Models\ContractorBid;
use App\Models\ContractorPayment;
use App\Models\ContractorTender;
use App\Models\ContractorTenderItem;
use App\Models\Party;
use App\Support\Dec;

/**
 * The only way contractor figures leave the service layer for someone without money.view.
 * Each method names what may go out, so a new money field stays private unless added here.
 */
final class ContractorControlPresenter
{
    /** For screens: who the contract is with and how much work is done, and nothing about money. */
    public static function workOnly(array $row): array
    {
        return [
            'work_order' => $row['work_order'],
            'party' => $row['party'],
            'project' => $row['project'] ?? null,
            'work_pct' => $row['work_pct'],
            'balance_work_pct' => $row['balance_work_pct'],
        ];
    }

    public static function workOnlyLine(array $row): array
    {
        return [
            'line' => $row['line'],
            'boq_item' => $row['boq_item'],
            'work_pct' => $row['work_pct'],
            'balance_work_pct' => $row['balance_work_pct'],
        ];
    }

    /** For the API. */
    public static function contract(array $row, bool $canSeeMoney): array
    {
        $base = [
            'work_order_id' => $row['work_order']->id,
            'number' => $row['work_order']->number,
            'title' => $row['work_order']->title,
            'contractor' => $row['party']?->name,
            'contract_status' => $row['work_order']->status,
            'work_pct' => $row['work_pct'],
            'balance_work_pct' => $row['balance_work_pct'],
        ];

        return $canSeeMoney ? [...$base, ...self::moneyFigures($row)] : $base;
    }

    public static function line(array $row, bool $canSeeMoney): array
    {
        $base = [
            'boq_item_id' => $row['boq_item']?->id,
            'code' => $row['boq_item']?->code,
            'description' => $row['boq_item']?->description,
            'unit' => $row['boq_item']?->unit,
            'work_pct' => $row['work_pct'],
            'balance_work_pct' => $row['balance_work_pct'],
        ];

        return $canSeeMoney ? [...$base, ...self::moneyFigures($row)] : $base;
    }

    public static function milestone(array $row, bool $canSeeMoney): array
    {
        $base = [
            'id' => $row['part']->id,
            'title' => $row['part']->title,
            'done' => $row['part']->isDone(),
            'done_on' => $row['part']->done_on?->toDateString(),
        ];

        return $canSeeMoney ? [...$base, 'amount' => $row['amount'], 'paid' => $row['paid'], 'balance' => $row['balance'], 'state' => $row['state']] : $base;
    }

    public static function payment(ContractorPayment $payment, bool $canSeeMoney, ?array $impact = null): array
    {
        $base = [
            'id' => $payment->id,
            'number' => $payment->number,
            'status' => $payment->status,
            'work_order_id' => $payment->work_order_id,
            'work_order_number' => $payment->workOrder?->number,
            'contractor' => $payment->party?->name,
            'boq_item_id' => $payment->boq_item_id,
            'milestone_id' => $payment->contractor_milestone_id,
            'paid_on' => $payment->paid_on?->toDateString(),
            'entered_by' => $payment->enteredBy?->name,
            'client_uuid' => $payment->client_uuid,
        ];

        if (! $canSeeMoney) {
            return $base;
        }

        return [
            ...$base,
            'amount' => $payment->amount,
            'mode' => $payment->mode,
            'paid_to' => $payment->paid_to,
            'reference' => $payment->reference,
            'is_advance' => $payment->is_advance,
            'notes' => $payment->notes,
            'has_bill' => $payment->bill_path !== null,
            'is_reversal' => $payment->isReversal(),
            'approval_id' => $payment->currentApproval?->id,
            'impact' => $impact ? self::impact($impact) : null,
        ];
    }

    /**
     * Beside a line on the BOQ table: how many tenders it is in and how many quotes came in.
     * The lowest rate is money.
     *
     * @param  array{tenders: int, quotes: int, lowest_rate: ?string}  $marker
     */
    public static function tenderMarker(array $marker, bool $canSeeMoney): array
    {
        $base = ['tenders' => $marker['tenders'], 'quotes' => $marker['quotes']];

        return $canSeeMoney ? [...$base, 'lowest_rate' => $marker['lowest_rate']] : $base;
    }

    /**
     * Every line's marker, keyed by BOQ item id, as BoqLineTenders::markersFor gives them.
     * Null stays null: tenders are not this person's to see.
     *
     * @param  array<int, array{tenders: int, quotes: int, lowest_rate: ?string}>|null  $markers
     * @return array<int, array<string, mixed>>|null
     */
    public static function tenderMarkers(?array $markers, bool $canSeeMoney): ?array
    {
        return $markers === null ? null : array_map(fn (array $marker) => self::tenderMarker($marker, $canSeeMoney), $markers);
    }

    /**
     * A BOQ line's tenders, for the screen and the API alike. Without money.view a reader learns
     * that a tender exists, who quoted and when, and nothing of what was quoted.
     */
    public static function lineTenders(array $data, bool $canSeeMoney): array
    {
        $item = $data['item'];
        $summary = $data['summary'];

        $base = [
            'boq_item_id' => $item->id,
            'code' => $item->code,
            'description' => $item->description,
            'unit' => $item->unit,
            'qty' => $item->qty,
            // Quotes are compared by their totals, so a line with no quantity cannot start a tender.
            'no_quantity' => Dec::of($item->qty)->isZero(),
            'tender_count' => $summary['tenders'],
            'open_tender_count' => $summary['open'],
            'quote_count' => $summary['quotes'],
            'tenders' => array_map(fn (array $row) => self::lineTender($row, $canSeeMoney), $data['tenders']),
        ];

        if (! $canSeeMoney) {
            return $base;
        }

        return [
            ...$base,
            'boq_rate' => $item->rate,
            'boq_amount' => $item->amount(),
            'lowest_rate' => $summary['lowest_rate'],
            'lowest_contractor' => $summary['lowest_contractor'],
        ];
    }

    private static function lineTender(array $row, bool $canSeeMoney): array
    {
        $tender = $row['tender'];
        $quotes = $row['quotes'];

        // Cheapest first would itself say who quoted least, so without money they come in the order received,
        // current ones first, the same as GET /tenders/{tender}.
        if (! $canSeeMoney) {
            usort($quotes, fn (array $a, array $b) => [! $a['current'], $a['bid']->id] <=> [! $b['current'], $b['bid']->id]);
        }

        $base = [
            'id' => $tender->id,
            'number' => $tender->number,
            'title' => $tender->title,
            'status' => $tender->status,
            'due_on' => $tender->due_on?->toDateString(),
            'work_order_id' => $tender->work_order_id,
            'work_order_number' => $tender->workOrder?->number,
            'qty' => $row['qty'],
            'lines' => $tender->items->map(fn (ContractorTenderItem $line) => self::tenderLine($line, false))->all(),
            'quote_count' => count(array_filter($quotes, fn (array $quote) => $quote['current'])),
            'quotes' => array_map(fn (array $quote) => self::lineQuote($quote, $canSeeMoney), $quotes),
        ];

        return $canSeeMoney ? [...$base, 'boq_rate' => $row['boq_rate'], 'boq_amount' => $row['boq_amount'], 'lowest_rate' => $row['lowest_rate']] : $base;
    }

    private static function lineQuote(array $quote, bool $canSeeMoney): array
    {
        $bid = $quote['bid'];

        $base = [
            'bid_id' => $bid->id,
            'contractor' => $quote['contractor'],
            'received_on' => $bid->received_on?->toDateString(),
            'current' => $quote['current'],
            'selected' => $quote['selected'],
            ...self::offer($bid),
        ];

        if (! $canSeeMoney) {
            return $base;
        }

        return [
            ...$base,
            'rate' => $quote['rate'],
            'amount' => $quote['amount'],
            'diff' => $quote['diff'],
            'diff_amount' => $quote['diff_amount'],
            'diff_pct' => $quote['diff_pct'],
            'lowest' => $quote['lowest'],
            ...self::offerText($bid),
        ];
    }

    /**
     * One tender with its quotes side by side, for the app. Without money.view a reader learns the
     * lines, who quoted, when, and what each offers besides the price, and nothing of what was quoted.
     *
     * @param  array{boq_amount: string, lowest: ?string, bids: list<array<string, mixed>>}  $comparison  From ContractorTenderService::comparison
     * @param  array{quote: bool, award: bool}  $can  From BoqLineTenders::abilities
     */
    public static function tender(ContractorTender $tender, array $comparison, bool $canSeeMoney, array $can): array
    {
        $rows = $comparison['bids'];

        // Cheapest first would itself say who quoted least, so without money the current ones come in the order received.
        if (! $canSeeMoney) {
            usort($rows, fn (array $a, array $b) => [! $a['current'], $a['bid']->id] <=> [! $b['current'], $b['bid']->id]);
        }

        $base = [
            'id' => $tender->id,
            'number' => $tender->number,
            'title' => $tender->title,
            'status' => $tender->status,
            'due_on' => $tender->due_on?->toDateString(),
            'project_id' => $tender->project_id,
            'work_order_id' => $tender->work_order_id,
            'work_order_number' => $tender->workOrder?->number,
            'awarded_at' => $tender->awarded_at?->toIso8601String(),
            'lines' => $tender->items->map(fn (ContractorTenderItem $line) => self::tenderLine($line, $canSeeMoney))->all(),
            'quote_count' => count(array_filter($rows, fn (array $row) => $row['current'])),
            'can' => $can,
            'quotes' => array_map(fn (array $row) => self::tenderQuote($row, $canSeeMoney), $rows),
        ];

        if (! $canSeeMoney) {
            return $base;
        }

        // The scope and the award note are free text, which may quote a price.
        return [
            ...$base,
            'scope' => $tender->scope,
            'award_note' => $tender->award_note,
            'boq_amount' => $comparison['boq_amount'],
            'lowest_amount' => $comparison['lowest'],
        ];
    }

    /** A subcontractor in the quote form's picker. */
    public static function contractorOption(Party $party): array
    {
        return ['id' => $party->id, 'name' => $party->name, 'phone' => $party->phone];
    }

    /** A line of a tender, with the quantity and BOQ rate copied when the tender was made. */
    private static function tenderLine(ContractorTenderItem $line, bool $canSeeMoney): array
    {
        $base = [
            'id' => $line->id,
            'boq_item_id' => $line->boq_item_id,
            'code' => $line->boqItem?->code,
            'description' => $line->boqItem?->description,
            'unit' => $line->boqItem?->unit,
            'qty' => $line->qty,
        ];

        return $canSeeMoney ? [...$base, 'boq_rate' => $line->boq_rate, 'boq_amount' => $line->amount()] : $base;
    }

    /** One quote on the tender's comparison: its total against the BOQ, and its rate for each line. */
    private static function tenderQuote(array $row, bool $canSeeMoney): array
    {
        $bid = $row['bid'];

        $base = [
            'bid_id' => $bid->id,
            'party_id' => $bid->party_id,
            'contractor' => $row['contractor'],
            'received_on' => $bid->received_on?->toDateString(),
            'current' => $row['current'],
            'replaced_by' => $bid->superseded_by_id,
            'selected' => (bool) $row['selected'],
            ...self::offer($bid),
        ];

        if (! $canSeeMoney) {
            return $base;
        }

        return [
            ...$base,
            'amount' => $row['amount'],
            'diff' => $row['diff'],
            'diff_pct' => $row['diff_pct'],
            'lowest' => $row['lowest'],
            'rates' => $row['lines'],
            ...self::offerText($bid),
        ];
    }

    /** What a contractor offers besides the price. None of it is money. */
    private static function offer(ContractorBid $bid): array
    {
        return [
            'valid_until' => $bid->valid_until?->toDateString(),
            'includes_material' => $bid->includes_material,
            'completion_days' => $bid->completion_days,
            'entered_by' => $bid->enteredBy?->name,
        ];
    }

    /** Payment terms and remarks are free text, which may quote a price, so they travel with the figures, as does the quotation. */
    private static function offerText(ContractorBid $bid): array
    {
        return [
            'payment_terms' => $bid->payment_terms,
            'remarks' => $bid->remarks,
            'has_quotation' => $bid->quotation_path !== null,
            'quotation_name' => $bid->quotation_name,
        ];
    }

    /** What approving a payment would do to its contract, as WorkPaymentRatio::impact gives it. Money only. */
    public static function impact(array $impact): array
    {
        return [
            'now' => self::moneyFigures($impact['now']) + ['work_pct' => $impact['now']['work_pct']],
            'after' => $impact['after'] ? self::moneyFigures($impact['after']) + ['work_pct' => $impact['after']['work_pct']] : null,
            'item' => $impact['item'] ? self::moneyFigures($impact['item']) + ['work_pct' => $impact['item']['work_pct']] : null,
        ];
    }

    private static function moneyFigures(array $row): array
    {
        return [
            'contract_value' => $row['contract_value'],
            'paid' => $row['paid'],
            'advance' => $row['advance'] ?? null,
            'paid_pct' => $row['paid_pct'],
            'gap_pct' => $row['gap_pct'],
            'ratio_status' => $row['status'],
            'over_limit' => $row['over_limit'],
            'limit_pct' => $row['limit_pct'],
            'label' => $row['label'],
            'balance_work_value' => $row['balance_work_value'],
            'balance_money' => $row['balance_money'],
            'short_by' => $row['short_by'],
        ];
    }
}
