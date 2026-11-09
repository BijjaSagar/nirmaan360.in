<?php

namespace App\Services\SiteControl;

use App\Models\BoqItem;
use App\Models\ProgressEntry;
use App\Models\Project;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/** The one place "how much work is done" is worked out. Every percentage is a string with two decimals. */
class WorkProgress
{
    public function completedQty(int $boqItemId, ?int $floorId = null): BigDecimal
    {
        $sum = ProgressEntry::where('boq_item_id', $boqItemId)
            ->when($floorId, fn ($query) => $query->where('project_floor_id', $floorId))
            ->sum('qty_done');

        return Dec::of(Dec::qty($sum));
    }

    /** Completed quantity ÷ BOQ quantity. Above 100 means more was done than the BOQ allowed for. */
    public function itemPercent(BoqItem $item): string
    {
        return $this->percent($this->completedQty($item->id), $item->qty);
    }

    /** Weighted by value, so a costly RCC line counts for more than a cheap one. */
    public function projectPercent(Project $project): string
    {
        $done = $this->doneByItem($project);
        $total = BigDecimal::zero();
        $earned = BigDecimal::zero();

        foreach (BoqItem::where('project_id', $project->id)->get(['id', 'qty', 'rate']) as $item) {
            $qty = Dec::of($item->qty);
            $rate = Dec::of($item->rate);
            $total = $total->plus($qty->multipliedBy($rate));
            $earned = $earned->plus($this->capped(Dec::of($done[$item->id] ?? 0), $qty)->multipliedBy($rate));
        }

        return $this->percent($earned, $total);
    }

    /**
     * Value of the work done under a contract as a share of its value. Entries naming the
     * work order always count. Untagged entries count only where this is the sole active
     * work order on that BOQ line, because otherwise nobody can say whose work they were.
     */
    public function workOrderPercent(WorkOrder $workOrder): string
    {
        $items = WorkOrderItem::where('work_order_id', $workOrder->id)->get(['boq_item_id', 'qty', 'rate']);
        $ids = $items->pluck('boq_item_id');

        $contractsOnLine = WorkOrderItem::whereIn('boq_item_id', $ids)
            ->whereHas('workOrder', fn ($query) => $query->where('status', 'ACTIVE'))
            ->get(['boq_item_id', 'work_order_id'])
            ->groupBy('boq_item_id')
            ->map(fn ($rows) => $rows->pluck('work_order_id')->unique()->count());

        $entries = ProgressEntry::whereIn('boq_item_id', $ids)
            ->where(fn ($query) => $query->where('work_order_id', $workOrder->id)->orWhereNull('work_order_id'))
            ->get(['boq_item_id', 'work_order_id', 'qty_done']);

        $total = BigDecimal::zero();
        $earned = BigDecimal::zero();

        foreach ($items as $item) {
            $sole = ($contractsOnLine[$item->boq_item_id] ?? 0) <= 1;
            $done = $entries
                ->where('boq_item_id', $item->boq_item_id)
                ->filter(fn ($entry) => $entry->work_order_id !== null || $sole)
                ->reduce(fn (BigDecimal $sum, $entry) => $sum->plus(Dec::of($entry->qty_done)), BigDecimal::zero());

            $qty = Dec::of($item->qty);
            $rate = Dec::of($item->rate);
            $total = $total->plus($qty->multipliedBy($rate));
            $earned = $earned->plus($this->capped($done, $qty)->multipliedBy($rate));
        }

        return $this->percent($earned, $total);
    }

    /** Days elapsed ÷ planned days, measured from the project's start date. Null when either is missing. */
    public function timeUsedPercent(Project $project, ?Carbon $today = null): ?string
    {
        if (! $project->start_date || ! $project->planned_days) {
            return null;
        }

        $today = ($today ?? now())->copy()->startOfDay();
        $elapsed = max(0, (int) Carbon::parse($project->start_date)->startOfDay()->diffInDays($today, false));

        return $this->percent($elapsed, $project->planned_days);
    }

    /** Positive means time is running ahead of work. Null when the time used is unknown. */
    public function scheduleLag(Project $project, ?Carbon $today = null): ?string
    {
        $timeUsed = $this->timeUsedPercent($project, $today);

        return $timeUsed === null ? null : Dec::money(Dec::of($timeUsed)->minus(Dec::of($this->projectPercent($project))));
    }

    public function percent(mixed $part, mixed $whole): string
    {
        $whole = Dec::of($whole);

        if ($whole->isZero()) {
            return '0.00';
        }

        return Dec::money(Dec::of($part)->multipliedBy(100)->dividedBy($whole, 6, RoundingMode::HalfUp));
    }

    /** @return array<int, string> */
    public function doneByItem(Project $project): array
    {
        return ProgressEntry::where('project_id', $project->id)
            ->selectRaw('boq_item_id, SUM(qty_done) as done')
            ->groupBy('boq_item_id')
            ->pluck('done', 'boq_item_id')
            ->map(fn ($done) => Dec::qty($done))
            ->all();
    }

    private function capped(BigDecimal $done, BigDecimal $qty): BigDecimal
    {
        if ($done->isNegative()) {
            return BigDecimal::zero();
        }

        return $done->isGreaterThan($qty) ? $qty : $done;
    }
}
