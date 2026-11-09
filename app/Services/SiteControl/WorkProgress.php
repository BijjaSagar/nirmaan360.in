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

        foreach (BoqItem::where('project_id', $project->id)->siteWork()->get(['id', 'qty', 'rate']) as $item) {
            $qty = Dec::of($item->qty);
            $rate = Dec::of($item->rate);
            $total = $total->plus($qty->multipliedBy($rate));
            $earned = $earned->plus($this->capped(Dec::of($done[$item->id] ?? 0), $qty)->multipliedBy($rate));
        }

        return $this->percent($earned, $total);
    }

    /**
     * Value of the work done under a contract as a share of its value. Entries naming the
     * work order always count. Untagged entries count only while no other active work order
     * is on that BOQ line, because otherwise nobody can say whose work they were. A closed
     * contract whose line has since gone to a new one therefore gives the line's untagged
     * work up to it, so the same work is never counted on both.
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
     * Earned value, contract value and work percent for several contracts at once, in the same
     * few queries however many there are. The board and the company ledger read this.
     *
     * @param  Collection<int, WorkOrder>  $workOrders
     * @return array<int, array{earned: string, total: string, percent: string}> Work order id => figures
     */
    public function workOrderFigures(Collection $workOrders): array
    {
        return array_map(
            fn (array $values) => ['earned' => Dec::money($values[0]), 'total' => Dec::money($values[1]), 'percent' => $this->percent($values[0], $values[1])],
            $this->valuesFor($workOrders),
        );
    }

    /**
     * Work done on each line of a contract as a share of that line's own quantity,
     * attributed the same way as workOrderPercent.
     *
     * @return array<int, string> Work order item id => percent
     */
    public function workOrderLinePercents(WorkOrder $workOrder): array
    {
        return array_map(fn (array $figures) => $figures['percent'], $this->workOrderLineFigures($workOrder));
    }

    /**
     * Quantity done on each line of a contract, capped at the line's own quantity and attributed
     * the same way as workOrderPercent, together with that line's percent.
     *
     * @return array<int, array{done: string, percent: string}> Work order item id => figures
     */
    public function workOrderLineFigures(WorkOrder $workOrder): array
    {
        $items = WorkOrderItem::where('work_order_id', $workOrder->id)->get(['id', 'boq_item_id', 'qty']);
        $done = $this->doneOnContracts(collect([$workOrder]), collect([$workOrder->id => $items]))[$workOrder->id];

        return $items
            ->mapWithKeys(function ($item) use ($done) {
                $capped = $this->capped($done[$item->boq_item_id], Dec::of($item->qty));

                return [$item->id => ['done' => Dec::qty($capped), 'percent' => $this->percent($capped, $item->qty)]];
            })
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
        return $this->valuesFor(collect([$workOrder]))[$workOrder->id];
    }

    /**
     * @param  Collection<int, WorkOrder>  $workOrders
     * @return array<int, array{0: BigDecimal, 1: BigDecimal}> Work order id => [value earned so far, total value]
     */
    private function valuesFor(Collection $workOrders): array
    {
        $items = WorkOrderItem::whereIn('work_order_id', $workOrders->pluck('id'))
            ->get(['work_order_id', 'boq_item_id', 'qty', 'rate'])
            ->groupBy('work_order_id');
        $done = $this->doneOnContracts($workOrders, $items);
        $values = [];

        foreach ($workOrders as $workOrder) {
            $total = BigDecimal::zero();
            $earned = BigDecimal::zero();

            foreach ($items->get($workOrder->id, collect()) as $item) {
                $qty = Dec::of($item->qty);
                $rate = Dec::of($item->rate);
                $total = $total->plus($qty->multipliedBy($rate));
                $earned = $earned->plus($this->capped($done[$workOrder->id][$item->boq_item_id], $qty)->multipliedBy($rate));
            }

            $values[$workOrder->id] = [$earned, $total];
        }

        return $values;
    }

    /**
     * Entries naming a work order always count for it. Untagged entries count for a work
     * order only while no other active work order is on that BOQ line.
     *
     * @param  Collection<int, WorkOrder>  $workOrders
     * @param  Collection<int, Collection<int, WorkOrderItem>>  $itemsByOrder  Work order id => its lines
     * @return array<int, array<int, BigDecimal>> Work order id => BOQ item id => quantity done under that contract
     */
    private function doneOnContracts(Collection $workOrders, Collection $itemsByOrder): array
    {
        $lineIds = $itemsByOrder->flatten(1)->pluck('boq_item_id')->unique()->values();

        $activeOnLine = WorkOrderItem::whereIn('boq_item_id', $lineIds)
            ->whereHas('workOrder', fn ($query) => $query->where('status', 'ACTIVE'))
            ->get(['boq_item_id', 'work_order_id'])
            ->groupBy('boq_item_id')
            ->map(fn ($rows) => $rows->pluck('work_order_id')->map(fn ($id) => (int) $id)->unique());

        $entries = ProgressEntry::whereIn('boq_item_id', $lineIds)
            ->where(fn ($query) => $query->whereIn('work_order_id', $workOrders->pluck('id'))->orWhereNull('work_order_id'))
            ->get(['boq_item_id', 'work_order_id', 'qty_done'])
            ->groupBy('boq_item_id');

        $done = [];

        foreach ($workOrders as $workOrder) {
            $done[$workOrder->id] = [];

            foreach ($itemsByOrder->get($workOrder->id, collect()) as $item) {
                $id = $item->boq_item_id;
                $sole = ($activeOnLine[$id] ?? collect())->reject(fn (int $otherId) => $otherId === (int) $workOrder->id)->isEmpty();

                $done[$workOrder->id][$id] = ($entries[$id] ?? collect())
                    ->filter(fn ($entry) => $entry->work_order_id === null ? $sole : (int) $entry->work_order_id === (int) $workOrder->id)
                    ->reduce(fn (BigDecimal $sum, $entry) => $sum->plus(Dec::of($entry->qty_done)), BigDecimal::zero());
            }
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
