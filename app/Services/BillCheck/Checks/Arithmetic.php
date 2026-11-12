<?php

namespace App\Services\BillCheck\Checks;

use App\Models\RaBillItem;
use App\Services\BillCheck\BillFacts;
use App\Services\BillCheck\Flag;
use App\Services\DeductionEngine;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;

/**
 * Adds the bill up again from its own lines and its own saved terms, the way the bill generator and
 * the deduction engine do, and compares each stored figure with the result.
 */
final class Arithmetic extends Check
{
    public function __construct(private DeductionEngine $engine) {}

    public function run(BillFacts $facts): array
    {
        $flags = [];
        $lines = BigDecimal::zero();

        foreach ($facts->lines as $line) {
            $lines = $lines->plus(Dec::of($line->current_amount));
            array_push($flags, ...$this->line($facts, $line));
        }

        // A2: gross value = the total of the lines.
        if (! $this->sameMoney($lines, $facts->bill->gross_amount)) {
            $flags[] = $this->flag('A2', 'A2', ['stored' => Dec::money($facts->bill->gross_amount), 'expected' => Dec::money($lines)]);
        }

        array_push($flags, ...$this->deductions($facts));

        // A4
        if (Dec::of($facts->bill->net_payable)->isNegative()) {
            $flags[] = $this->flag('A4', 'A4', ['net' => Dec::money($facts->bill->net_payable)]);
        }

        return $flags;
    }

    /**
     * A1: a line's amount is quantity × rate, or for a lump sum what is earned up to the line's fixed
     * value less what was paid before, and its cumulative figures are previous + this bill.
     *
     * @return list<Flag>
     */
    private function line(BillFacts $facts, RaBillItem $line): array
    {
        $flags = [];
        $item = $line->code;
        $rate = Dec::of($line->rate);
        $prevQty = Dec::of($line->prev_qty);
        $current = Dec::of($line->current_qty);
        $prevAmount = Dec::of($line->prev_amount);
        $stored = Dec::money($line->current_amount);

        if ($facts->isLumpSum()) {
            $orderLine = $facts->orderLine((int) $line->boq_item_id);

            if ($orderLine === null) {
                $flags[] = $this->flag('A1.lump_gone', "A1:{$line->id}:amount", ['item' => $item]);
            } else {
                $value = Dec::of(Dec::money(Dec::of($orderLine->qty)->multipliedBy($rate)));
                $earned = Dec::of(Dec::money($prevQty->plus($current)->multipliedBy($rate)));
                $expected = Dec::money(($earned->isGreaterThan($value) ? $value : $earned)->minus($prevAmount));

                if ($expected !== $stored) {
                    $flags[] = $this->flag('A1.lump', "A1:{$line->id}:amount", ['item' => $item, 'value' => Dec::money($value), 'stored' => $stored, 'expected' => $expected]);
                }
            }
        } else {
            $expected = Dec::money($current->multipliedBy($rate));

            if ($expected !== $stored) {
                $flags[] = $this->flag('A1.amount', "A1:{$line->id}:amount", ['item' => $item, 'qty' => Dec::qty($current), 'rate' => Dec::money($rate), 'stored' => $stored, 'expected' => $expected]);
            }
        }

        if (! $this->sameQty($prevQty->plus($current), $line->cumulative_qty)) {
            $flags[] = $this->flag('A1.cum_qty', "A1:{$line->id}:qty", [
                'item' => $item, 'prev' => Dec::qty($prevQty), 'current' => Dec::qty($current),
                'stored' => Dec::qty($line->cumulative_qty), 'expected' => Dec::qty($prevQty->plus($current)),
            ]);
        }

        if (! $this->sameMoney($prevAmount->plus(Dec::of($line->current_amount)), $line->cumulative_amount)) {
            $flags[] = $this->flag('A1.cum_amount', "A1:{$line->id}:cumulative", [
                'item' => $item, 'stored' => Dec::money($line->cumulative_amount),
                'expected' => Dec::money($prevAmount->plus(Dec::of($line->current_amount))),
            ]);
        }

        return $flags;
    }

    /**
     * A3: the deduction engine, run again on the stored gross value with the terms saved on the bill,
     * against every deduction row, the deduction total, GST, net payable and the retention released.
     *
     * @return list<Flag>
     */
    private function deductions(BillFacts $facts): array
    {
        $bill = $facts->bill;
        $terms = $facts->terms;

        if (isset($terms['order']) && ! is_array($terms['order'])) {
            return [$this->flag('A3.terms', 'A3:terms')];
        }

        try {
            $result = $this->engine->compute($bill->gross_amount, $terms);
        } catch (InvalidArgumentException|MathException) {
            return [$this->flag('A3.terms', 'A3:terms')];
        }

        $flags = [];
        $expected = collect($result['deductions'])->mapWithKeys(fn (array $row) => [$row['type'] => Dec::money($row['amount'])]);
        $stored = $facts->deductions->groupBy('type')->map(fn ($rows) => Dec::money(BillFacts::sum($rows->pluck('amount'))));

        foreach ($expected->keys()->merge($stored->keys())->unique() as $type) {
            $key = "A3:deduction:{$type}";

            if (! $stored->has($type)) {
                $flags[] = $this->flag('A3.deduction_missing', $key, ['head' => $type, 'expected' => $expected[$type]]);
            } elseif (! $expected->has($type)) {
                $flags[] = $this->flag('A3.deduction_extra', $key, ['head' => $type, 'stored' => $stored[$type]]);
            } elseif ($expected[$type] !== $stored[$type]) {
                $flags[] = $this->flag('A3.deduction', $key, ['head' => $type, 'stored' => $stored[$type], 'expected' => $expected[$type]]);
            }
        }

        if (! $this->sameMoney($result['deduction_total'], $bill->deduction_total)) {
            $flags[] = $this->flag('A3.total', 'A3:total', ['stored' => Dec::money($bill->deduction_total), 'expected' => Dec::money($result['deduction_total'])]);
        }

        if (! $this->sameMoney($result['gst_amount'], $bill->gst_amount)) {
            $flags[] = $this->flag('A3.gst', 'A3:gst', ['stored' => Dec::money($bill->gst_amount), 'expected' => Dec::money($result['gst_amount'])]);
        }

        // Only a final bill releases retention: everything held on the earlier bills, never less than nothing.
        // Which bills are the earlier ones is unknown once the work order is gone, so the bill's own figure stands then.
        $held = BillFacts::sum($facts->earlierDeductions()->where('type', 'retention')->pluck('amount'));
        $released = match (true) {
            $facts->contractMissing() => Dec::money($bill->retention_released),
            $bill->is_final && $held->isPositive() => Dec::money($held),
            default => '0.00',
        };

        if (! $this->sameMoney($released, $bill->retention_released)) {
            $flags[] = $this->flag('A3.released', 'A3:released', ['stored' => Dec::money($bill->retention_released), 'expected' => $released]);
        }

        $net = Dec::of($result['net_payable'])->plus(Dec::of($released));

        if (! $this->sameMoney($net, $bill->net_payable)) {
            $flags[] = $this->flag('A3.net', 'A3:net', ['stored' => Dec::money($bill->net_payable), 'expected' => Dec::money($net)]);
        }

        return $flags;
    }
}
