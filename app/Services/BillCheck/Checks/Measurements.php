<?php

namespace App\Services\BillCheck\Checks;

use App\Models\Dpr;
use App\Models\DprWorkItem;
use App\Services\BillCheck\BillFacts;
use App\Services\BillCheck\Flag;
use App\Support\Dec;

/**
 * Looks at the measurements behind the bill and around it: their dates against the bill period, what
 * is still waiting, what the daily reports say, and whether each carries a photo or a sketch.
 */
final class Measurements extends Check
{
    /** A measurement that may still be approved. */
    private const OPEN = ['DRAFT', 'IN_REVIEW', 'RETURNED'];

    public function run(BillFacts $facts): array
    {
        return [
            ...$this->dates($facts),
            ...$this->dailyReports($facts),
            ...$this->attachments($facts),
            ...$this->lines($facts),
        ];
    }

    /** D1, D2 and D3. @return list<Flag> */
    private function dates(BillFacts $facts): array
    {
        $flags = [];
        $from = $facts->bill->period_from->toDateString();
        $to = $facts->bill->period_to->toDateString();

        // D1: the generator takes every approved, unbilled measurement up to the period end, however old.
        $before = $facts->entriesOnBill()->filter(fn ($entry) => $entry->measured_on->toDateString() < $from);

        if ($before->isNotEmpty()) {
            $flags[] = $this->flag('D1', 'D1', ['count' => $before->count(), 'from' => $from, 'earliest' => $before->map(fn ($entry) => $entry->measured_on->toDateString())->min()]);
        }

        // D2: approved work dated after the period. On a running bill it waits for the next one. A final bill has no next one.
        $after = $facts->contractEntries()->where('status', 'APPROVED')->whereNull($facts->billedColumn())->whereDate('measured_on', '>', $to)->count();

        if ($after > 0) {
            $flags[] = $this->flag('D2', 'D2', ['count' => $after], $facts->bill->is_final ? Flag::TICK : Flag::NOTE);
        }

        // D3
        $waiting = $facts->contractEntries()->whereIn('status', self::OPEN)->whereDate('measured_on', '>=', $from)->whereDate('measured_on', '<=', $to)->count();

        if ($waiting > 0) {
            $flags[] = $this->flag('D3', 'D3', ['count' => $waiting]);
        }

        return $flags;
    }

    /**
     * D4: for each item on the bill, approved measurements up to the period end against the quantity
     * in approved daily reports up to the same day. Only items that daily reports give a quantity
     * for are compared, and only approved reports count.
     *
     * @return list<Flag>
     */
    private function dailyReports(BillFacts $facts): array
    {
        $items = $facts->lines->pluck('boq_item_id')->unique()->values();
        $to = $facts->bill->period_to->toDateString();

        if ($items->isEmpty()) {
            return [];
        }

        $reports = Dpr::withoutGlobalScopes()
            ->where('organization_id', $facts->bill->organization_id)
            ->where('project_id', $facts->bill->project_id)
            ->where('status', 'APPROVED')
            ->whereNull('deleted_at')
            ->whereDate('report_date', '<=', $to)
            ->select('id');

        $reported = DprWorkItem::withoutGlobalScopes()
            ->where('organization_id', $facts->bill->organization_id)
            ->whereIn('boq_item_id', $items)
            ->whereIn('dpr_id', $reports)
            ->get(['boq_item_id', 'qty'])
            ->groupBy('boq_item_id');

        if ($reported->isEmpty()) {
            return [];
        }

        $measured = $facts->projectEntries()
            ->where('status', 'APPROVED')
            ->whereIn('boq_item_id', $reported->keys())
            ->whereDate('measured_on', '<=', $to)
            ->get(['boq_item_id', 'qty'])
            ->groupBy('boq_item_id');

        $flags = [];

        foreach ($reported as $itemId => $rows) {
            $inReports = BillFacts::sum($rows->pluck('qty'));
            $inBook = BillFacts::sum($measured->get($itemId, collect())->pluck('qty'));

            if ($inReports->isPositive() && $inBook->isGreaterThan($inReports)) {
                $flags[] = $this->flag('D4', "D4:{$itemId}", [
                    'item' => $facts->itemCode((int) $itemId), 'unit' => $facts->itemUnit((int) $itemId),
                    'measured' => Dec::qty($inBook), 'reported' => Dec::qty($inReports),
                ]);
            }
        }

        return $flags;
    }

    /** D6: measurements on the bill with neither a photo nor a sketch. @return list<Flag> */
    private function attachments(BillFacts $facts): array
    {
        $total = $facts->entriesOnBill()->count();
        $bare = $total - $facts->entriesWithAttachment()->count();

        return $bare > 0 ? [$this->flag('D6', 'D6', ['count' => $bare, 'total' => $total])] : [];
    }

    /** D7: a client line with no rate, and a line whose BOQ item has since been removed. @return list<Flag> */
    private function lines(BillFacts $facts): array
    {
        $flags = [];

        foreach ($facts->lines as $line) {
            if ($facts->isClient() && Dec::of($line->rate)->isZero()) {
                $flags[] = $this->flag('D7.rate', "D7:{$line->id}:rate", ['item' => $line->code]);
            }

            if ($facts->boqItem((int) $line->boq_item_id)?->trashed()) {
                $flags[] = $this->flag('D7.removed', "D7:{$line->id}:removed", ['item' => $line->code]);
            }
        }

        return $flags;
    }
}
