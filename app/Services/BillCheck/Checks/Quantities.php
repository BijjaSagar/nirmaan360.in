<?php

namespace App\Services\BillCheck\Checks;

use App\Models\ContractTerm;
use App\Models\RaBillItem;
use App\Services\BillCheck\BillFacts;
use App\Services\BillCheck\Flag;
use App\Services\ContractTerms\ContractTerms;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Compares what has been billed so far on each line with the contract quantity, with the approved
 * measurements and, where a person has confirmed one, with the agreement's limit on extra quantity.
 */
final class Quantities extends Check
{
    public function __construct(private ContractTerms $terms) {}

    public function run(BillFacts $facts): array
    {
        return [
            ...$this->aboveContractQuantity($facts),
            ...$this->deviations($facts),
            ...$this->aboveMeasured($facts),
            ...$this->aboveAgreedExcess($facts),
        ];
    }

    /**
     * The quantity the contract gives a line: the BOQ quantity on a client bill, the work order
     * quantity on a subcontractor bill. Null when the line can no longer be found there.
     */
    private function contractQty(BillFacts $facts, RaBillItem $line): ?BigDecimal
    {
        $source = $facts->isClient() ? $facts->boqItem((int) $line->boq_item_id) : $facts->orderLine((int) $line->boq_item_id);

        return $source === null ? null : Dec::of($source->qty);
    }

    /**
     * C1: a client line billed above its BOQ quantity. C2: a subcontractor line that is paid by
     * quantity billed above its work order quantity. A lump sum line is left out, because its
     * amount already stops at the line's fixed value.
     *
     * @return list<Flag>
     */
    private function aboveContractQuantity(BillFacts $facts): array
    {
        if ($facts->contractMissing() || (! $facts->isClient() && $facts->isLumpSum())) {
            return [];
        }

        $flags = [];
        $code = $facts->isClient() ? 'C1' : 'C2';

        foreach ($facts->lines as $line) {
            $limit = $this->contractQty($facts, $line);
            $cumulative = Dec::of($line->cumulative_qty);

            if ($limit === null) {
                continue;
            }

            if (! $limit->isPositive()) {
                if ($facts->isClient() && $cumulative->isPositive()) {
                    $flags[] = $this->flag('C1.zero', "C1:{$line->id}", ['item' => $line->code, 'unit' => $line->unit, 'cumulative' => Dec::qty($cumulative)]);
                } elseif (! $facts->isClient()) {
                    $flags[] = $this->flag('C2.zero', "C2:{$line->id}", ['item' => $line->code]);
                }

                continue;
            }

            if ($cumulative->isGreaterThan($limit)) {
                $excess = $cumulative->minus($limit);

                $flags[] = $this->flag($code, "{$code}:{$line->id}", [
                    'item' => $line->code, 'unit' => $line->unit,
                    'cumulative' => Dec::qty($cumulative), 'limit' => Dec::qty($limit), 'excess' => Dec::qty($excess),
                    'over' => BillFacts::pct($excess->multipliedBy(100)->dividedBy($limit, 2, RoundingMode::HalfUp)),
                ]);
            }
        }

        return $flags;
    }

    /** C3: measurements on this bill that were marked as a deviation when they were sent for approval. @return list<Flag> */
    private function deviations(BillFacts $facts): array
    {
        $flags = [];

        foreach ($facts->entriesOnBill()->where('deviation', true)->groupBy('boq_item_id') as $itemId => $entries) {
            $flags[] = $this->flag('C3', "C3:{$itemId}", [
                'item' => $facts->itemCode((int) $itemId), 'unit' => $facts->itemUnit((int) $itemId),
                'count' => $entries->count(), 'qty' => Dec::qty(BillFacts::sum($entries->pluck('qty'))),
            ]);
        }

        return $flags;
    }

    /** C4: billed so far above what approved measurements of the contract add up to today. @return list<Flag> */
    private function aboveMeasured(BillFacts $facts): array
    {
        $measured = $facts->contractEntries()
            ->where('status', 'APPROVED')
            ->whereIn('boq_item_id', $facts->lines->pluck('boq_item_id'))
            ->get(['boq_item_id', 'qty'])
            ->groupBy('boq_item_id');

        $flags = [];

        foreach ($facts->lines as $line) {
            $approved = BillFacts::sum($measured->get($line->boq_item_id, collect())->pluck('qty'));

            if (Dec::of($line->cumulative_qty)->isGreaterThan($approved)) {
                $flags[] = $this->flag('C4', "C4:{$line->id}", [
                    'item' => $line->code, 'unit' => $line->unit,
                    'cumulative' => Dec::qty($line->cumulative_qty), 'measured' => Dec::qty($approved),
                ]);
            }
        }

        return $flags;
    }

    /**
     * C5: only when a person has confirmed a term on extra quantity that is in force at the end of
     * the bill period. The percentage is the one they typed; the app has none of its own.
     *
     * @return list<Flag>
     */
    private function aboveAgreedExcess(BillFacts $facts): array
    {
        if ($facts->contractMissing()) {
            return [];
        }

        $flags = [];
        $terms = $this->terms->effective($facts->project, $facts->workOrder, $facts->bill->period_to, 'excess_quantity');

        foreach ($terms as $term) {
            /** @var ContractTerm $term */
            foreach ($facts->lines as $line) {
                $base = $this->contractQty($facts, $line);

                if ($base === null || ! $base->isPositive()) {
                    continue;
                }

                $limit = Dec::of(Dec::qty($base->plus($base->multipliedBy(Dec::of($term->pct))->dividedBy(100, 6, RoundingMode::HalfUp))));

                if (Dec::of($line->cumulative_qty)->isGreaterThan($limit)) {
                    $flags[] = $this->flag('C5', "C5:{$line->id}:{$term->id}", [
                        'item' => $line->code, 'unit' => $line->unit,
                        'cumulative' => Dec::qty($line->cumulative_qty), 'limit' => Dec::qty($limit),
                        'allowed' => BillFacts::pct($term->pct), 'source' => (string) $term->source_ref,
                        'from' => $term->effective_from->toDateString(),
                    ]);
                }
            }
        }

        return $flags;
    }
}
