<?php

namespace App\Services\BillCheck\Checks;

use App\Services\BillCheck\BillFacts;
use App\Services\BillCheck\Flag;
use App\Support\Dec;

/**
 * Compares the bill with the other bills of its contract and with the contract as it stands today.
 * A draft bill is a snapshot; these are the things that can move under it after it was made.
 */
final class EarlierBills extends Check
{
    /** Statuses of an earlier bill that is neither approved nor cancelled. Its quantities still count as previous. */
    private const UNSETTLED = ['DRAFT', 'IN_REVIEW', 'RETURNED', 'REJECTED'];

    /** Key in the saved terms => the deduction it belongs to. */
    private const PERCENTAGES = [
        'retention_pct' => 'retention',
        'tds_pct' => 'tds',
        'gst_pct' => 'gst',
        'advance_recovery_pct' => 'advance_recovery',
    ];

    public function run(BillFacts $facts): array
    {
        return [
            ...$this->previousFigures($facts),
            ...$this->advanceBalance($facts),
            ...$this->unsettledAndOverlapping($facts),
            ...$this->termsToday($facts),
            ...$this->ratesToday($facts),
        ];
    }

    /** B1: "previous" on each line against the earlier bills as they stand now. @return list<Flag> */
    private function previousFigures(BillFacts $facts): array
    {
        $flags = [];
        $earlier = $facts->earlierLines()->groupBy('boq_item_id');

        foreach ($facts->lines as $line) {
            $rows = $earlier->get($line->boq_item_id, collect());
            $qty = BillFacts::sum($rows->pluck('current_qty'));
            $amount = BillFacts::sum($rows->pluck('current_amount'));

            if (! $this->sameQty($qty, $line->prev_qty) || ! $this->sameMoney($amount, $line->prev_amount)) {
                $flags[] = $this->flag('B1', "B1:{$line->id}", [
                    'item' => $line->code,
                    'stored_qty' => Dec::qty($line->prev_qty), 'now_qty' => Dec::qty($qty),
                    'stored_amount' => Dec::money($line->prev_amount), 'now_amount' => Dec::money($amount),
                ]);
            }
        }

        return $flags;
    }

    /** B2: the advance still to be recovered, from the advance saved on the bill less what earlier bills recover now. @return list<Flag> */
    private function advanceBalance(BillFacts $facts): array
    {
        if (! array_key_exists('advance_balance', $facts->terms)) {
            return [];
        }

        $recovered = BillFacts::sum($facts->earlierDeductions()->where('type', 'advance')->pluck('amount'));
        $now = Dec::money(Dec::of($facts->terms['advance_amount'] ?? 0)->minus($recovered));
        $stored = Dec::money($facts->terms['advance_balance']);

        return $now === $stored ? [] : [$this->flag('B2', 'B2', ['stored' => $stored, 'now' => $now])];
    }

    /** B3 and B4. @return list<Flag> */
    private function unsettledAndOverlapping(BillFacts $facts): array
    {
        $flags = [];

        foreach ($facts->earlier->whereIn('status', self::UNSETTLED) as $earlier) {
            $flags[] = $this->flag('B3', "B3:{$earlier->id}", ['number' => $earlier->number]);
        }

        $overlapping = $facts->contractBills()
            ->where('id', '!=', $facts->bill->id)
            ->where('status', '!=', 'CANCELLED')
            ->whereDate('period_from', '<=', $facts->bill->period_to->toDateString())
            ->whereDate('period_to', '>=', $facts->bill->period_from->toDateString())
            ->orderBy('id')
            ->get();

        foreach ($overlapping as $other) {
            $flags[] = $this->flag('B4', "B4:{$other->id}", [
                'number' => $other->number, 'from' => $other->period_from->toDateString(), 'to' => $other->period_to->toDateString(),
            ]);
        }

        return $flags;
    }

    /**
     * B5: the terms saved on the bill against the contract form today. A figure the bill sets aside on
     * purpose is left out: a final bill holds no retention and recovers the whole advance, and GST that
     * is on one and not on the other is a finding of its own (F4).
     *
     * @return list<Flag>
     */
    private function termsToday(BillFacts $facts): array
    {
        if ($facts->contractMissing()) {
            return [];
        }

        $flags = [];
        $saved = $facts->terms;
        $today = $facts->termsToday();

        foreach (self::PERCENTAGES as $key => $head) {
            if (! array_key_exists($key, $saved) || ($facts->bill->is_final && $key !== 'tds_pct' && $key !== 'gst_pct')) {
                continue;
            }

            $bill = Dec::of($saved[$key]);
            $now = Dec::of($today[$key] ?? 0);

            if ($key === 'gst_pct' && ($bill->isZero() || $now->isZero())) {
                continue;
            }

            if (! $bill->isEqualTo($now)) {
                $flags[] = $this->flag('B5.pct', "B5:{$key}", ['head' => $head, 'bill' => BillFacts::pct($bill), 'now' => BillFacts::pct($now)]);
            }
        }

        if (array_key_exists('advance_amount', $saved) && ! $this->sameMoney($saved['advance_amount'], $today['advance_amount'] ?? 0)) {
            $flags[] = $this->flag('B5.amount', 'B5:advance_amount', ['bill' => Dec::money($saved['advance_amount']), 'now' => Dec::money($today['advance_amount'] ?? 0)]);
        }

        if (isset($saved['order']) && is_array($saved['order']) && array_values($saved['order']) !== array_values($today['order'] ?? [])) {
            $flags[] = $this->flag('B5.order', 'B5:order');
        }

        if (isset($saved['basis']) && $saved['basis'] !== ($today['basis'] ?? null)) {
            $flags[] = $this->flag('B5.basis', 'B5:basis');
        }

        return $flags;
    }

    /** B6: each line's rate against the BOQ rate, or the work order rate, today. @return list<Flag> */
    private function ratesToday(BillFacts $facts): array
    {
        if ($facts->contractMissing()) {
            return [];
        }

        $flags = [];

        foreach ($facts->lines as $line) {
            $source = $facts->isClient() ? $facts->boqItem((int) $line->boq_item_id) : $facts->orderLine((int) $line->boq_item_id);

            if ($source === null) {
                if (! $facts->isClient()) {
                    $flags[] = $this->flag('B6.gone', "B6:{$line->id}", ['item' => $line->code]);
                }

                continue;
            }

            if (! $this->sameMoney($source->rate, $line->rate)) {
                $flags[] = $this->flag('B6', "B6:{$line->id}", ['item' => $line->code, 'bill' => Dec::money($line->rate), 'now' => Dec::money($source->rate)]);
            }
        }

        return $flags;
    }
}
