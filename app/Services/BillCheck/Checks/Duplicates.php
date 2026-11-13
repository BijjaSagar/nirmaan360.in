<?php

namespace App\Services\BillCheck\Checks;

use App\Models\MeasurementEntry;
use App\Services\BillCheck\BillFacts;
use App\Services\BillCheck\Flag;
use App\Support\Dec;

/**
 * Looks for the same work entered twice, and for a measurement billed together with its own reversal.
 */
final class Duplicates extends Check
{
    public function run(BillFacts $facts): array
    {
        return [...$this->identical($facts), ...$this->reversedOnTheSameBill($facts)];
    }

    /**
     * E2: approved measurements with the same item, date, location, numbers, lengths and deduction
     * flag, where at least one is on this bill and the others are on this bill or an earlier one of
     * the contract. Reversal entries, and entries that an approved reversal has already cancelled,
     * are left out: those were put right.
     *
     * @return list<Flag>
     */
    private function identical(BillFacts $facts): array
    {
        $onBill = $facts->entriesOnBill()->whereNull('reversal_of_id');

        if ($onBill->isEmpty()) {
            return [];
        }

        $bills = [$facts->bill->id, ...$facts->liveEarlier()->pluck('id')->all()];

        $pool = $facts->contractEntries()
            ->where('status', 'APPROVED')
            ->whereNull('reversal_of_id')
            ->whereIn('boq_item_id', $onBill->pluck('boq_item_id')->unique())
            ->whereIn($facts->billedColumn(), $bills)
            ->orderBy('id')
            ->get();

        $reversed = $facts->projectEntries()
            ->where('status', 'APPROVED')
            ->whereIn('reversal_of_id', $pool->pluck('id'))
            ->pluck('reversal_of_id')
            ->all();

        $flags = [];
        $column = $facts->billedColumn();

        foreach ($pool->whereNotIn('id', $reversed)->groupBy(fn (MeasurementEntry $entry) => $this->signature($entry)) as $twins) {
            if ($twins->count() < 2 || $twins->where($column, $facts->bill->id)->isEmpty()) {
                continue;
            }

            $first = $twins->first();

            $flags[] = $this->flag('E2', "E2:{$first->id}", [
                'item' => $facts->itemCode((int) $first->boq_item_id), 'unit' => $facts->itemUnit((int) $first->boq_item_id),
                'date' => $first->measured_on->toDateString(), 'qty' => Dec::qty($first->qty), 'count' => $twins->count(),
            ]);
        }

        return $flags;
    }

    /** E3: an entry and its reversal on this one bill add up to nothing, but both are on it. @return list<Flag> */
    private function reversedOnTheSameBill(BillFacts $facts): array
    {
        $onBill = $facts->entriesOnBill()->keyBy('id');
        $flags = [];

        foreach ($onBill->whereNotNull('reversal_of_id') as $reversal) {
            $original = $onBill->get($reversal->reversal_of_id);

            if ($original !== null) {
                $flags[] = $this->flag('E3', "E3:{$original->id}", [
                    'item' => $facts->itemCode((int) $original->boq_item_id), 'unit' => $facts->itemUnit((int) $original->boq_item_id),
                    'qty' => Dec::qty(Dec::of($original->qty)->abs()),
                ]);
            }
        }

        return $flags;
    }

    /** Two entries with the same signature describe the same work in the same place on the same day. */
    private function signature(MeasurementEntry $entry): string
    {
        $number = fn (mixed $value) => $value === null || $value === '' ? '' : Dec::qty($value);

        return implode('|', [
            $entry->boq_item_id,
            $entry->measured_on->toDateString(),
            mb_strtolower(trim((string) $entry->location)),
            $number($entry->nos), $number($entry->length), $number($entry->breadth), $number($entry->depth),
            $entry->is_deduction ? 'less' : 'add',
        ]);
    }
}
