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
use Illuminate\Support\Collection;

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
        [$earned, $total] = $this->contractValues($workOrder);

        return $this->percent($earned, $total);
    }

    /** Rupee value of the work done under a contract, by the same attribution as workOrderPercent. */
    public function workOrderEarned(WorkOrder $workOrder): string
    {
        return Dec::money($this->contractValues($workOrder)[0]);
    }

    /**
     * Work done on each line of a contract as a share of that line's own quantity,
     * attributed the same way as workOrderPercent.
     *
     * @return array<int, string> Work order item id => percent
     */
    public function workOrderLinePercents(WorkOrder $workOrder): array
    {
        $items = WorkOrderItem::where('work_order_id', $workOrder->id)->get(['id', 'boq_item_id', 'qty']);
        $done = $this->doneOnContract($workOrder, $items);

        return $items
            ->mapWithKeys(fn ($item) => [$item->id => $this->percent($this->capped($done[$item->boq_item_id], Dec::of($item->qty)), $item->qty)])
            ->all();
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

    /** @return array{0: BigDecimal, 1: BigDecimal} Value earned so far, and the contract's total value. */
    private function contractValues(WorkOrder $workOrder): array
    {
        $items = WorkOrderItem::where('work_order_id', $workOrder->id)->get(['boq_item_id', 'qty', 'rate']);
        $done = $this->doneOnContract($workOrder, $items);

        $total = BigDecimal::zero();
        $earned = BigDecimal::zero();

        foreach ($items as $item) {
            $qty = Dec::of($item->qty);
            $rate = Dec::of($item->rate);
            $total = $total->plus($qty->multipliedBy($rate));
            $earned = $earned->plus($this->capped($done[$item->boq_item_id], $qty)->multipliedBy($rate));
        }

        return [$earned, $total];
    }

    /**
     * Entries naming the work order always count. Untagged entries count only where this
     * is the sole active work order on that BOQ line.
     *
     * @param  Collection<int, WorkOrderItem>  $items
     * @return array<int, BigDecimal> BOQ item id => quantity done under this contract
     */
    private function doneOnContract(WorkOrder $workOrder, Collection $items): array
    {
        $ids = $items->pluck('boq_item_id');

        $contractsOnLine = WorkOrderItem::whereIn('boq_item_id', $ids)
            ->whereHas('workOrder', fn ($query) => $query->where('status', 'ACTIVE'))
            ->get(['boq_item_id', 'work_order_id'])
            ->groupBy('boq_item_id')
            ->map(fn ($rows) => $rows->pluck('work_order_id')->unique()->count());

        $entries = ProgressEntry::whereIn('boq_item_id', $ids)
            ->where(fn ($query) => $query->where('work_order_id', $workOrder->id)->orWhereNull('work_order_id'))
            ->get(['boq_item_id', 'work_order_id', 'qty_done']);

        $done = [];

        foreach ($ids as $id) {
            $sole = ($contractsOnLine[$id] ?? 0) <= 1;

            $done[$id] = $entries
                ->where('boq_item_id', $id)
                ->filter(fn ($entry) => $entry->work_order_id !== null || $sole)
                ->reduce(fn (BigDecimal $sum, $entry) => $sum->plus(Dec::of($entry->qty_done)), BigDecimal::zero());
        }

        return $done;
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
