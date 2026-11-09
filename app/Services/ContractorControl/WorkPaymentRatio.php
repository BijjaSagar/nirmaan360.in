<?php

namespace App\Services\ContractorControl;

use App\Models\ContractorPayment;
use App\Models\Project;
use App\Models\WorkOrder;
use App\Services\SiteControl\WorkProgress;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Work % against Paid %, the control the whole product is built around.
 *
 * Paid % = approved payments ÷ contract value × 100. Gap = Paid % − Work %.
 * Green when payment is at or below work, red the moment it is ahead. The project's
 * payment_gap_alert_pct decides when a red contract is also over the limit and raises the alert.
 * Every row here carries money: anything shown to site staff goes through ContractorControlPresenter.
 */
class WorkPaymentRatio
{
    public function __construct(private WorkProgress $progress) {}

    /**
     * The formulas from section 13, from figures already known.
     *
     * @param  string|null  $earned  Exact value of the work done, where known. Balance work then
     *                               comes from it rather than from a percent rounded to two places.
     */
    public function compare(mixed $contractValue, mixed $paid, string $workPct, mixed $limitPct, ?string $earned = null): array
    {
        $contract = Dec::of($contractValue);
        $work = Dec::of($workPct);
        $paidPct = $this->progress->percent($paid, $contract);
        $gap = Dec::of($paidPct)->minus($work);
        $status = $contract->isZero() ? 'none' : ($gap->isPositive() ? 'red' : 'green');

        $workLeft = BigDecimal::of(100)->minus($work);
        $workLeft = $workLeft->isNegative() ? BigDecimal::zero() : $workLeft;
        $workLeftValue = $earned !== null ? Dec::money($contract->minus(Dec::of($earned))) : Dec::percentOf($contract, $workLeft);
        $balanceMoney = $contract->minus(Dec::of($paid));
        $shortBy = Dec::of($workLeftValue)->minus($balanceMoney);

        return [
            'contract_value' => Dec::money($contract),
            'paid' => Dec::money($paid),
            'work_pct' => Dec::money($work),
            'paid_pct' => $paidPct,
            'gap_pct' => Dec::money($gap),
            'status' => $status,
            'over_limit' => $status === 'red' && $gap->isGreaterThan(Dec::of($limitPct)),
            'limit_pct' => Dec::money($limitPct),
            'label' => $this->label($paidPct, $workPct),
            'balance_work_pct' => Dec::money($workLeft),
            'balance_work_value' => $workLeftValue,
            'balance_money' => Dec::money($balanceMoney),
            'short_by' => Dec::money($shortBy->isPositive() ? $shortBy : BigDecimal::zero()),
        ];
    }

    /** "Paid 90%, Work 70%". */
    public function label(mixed $paidPct, mixed $workPct): string
    {
        return __('Paid :paid%, Work :work%', ['paid' => Figures::pct($paidPct), 'work' => Figures::pct($workPct)]);
    }

    /** One row per contract on a project, worst first. */
    public function forProject(Project $project): Collection
    {
        return $this->forWorkOrders(WorkOrder::where('project_id', $project->id)->get())
            ->sortBy(fn (array $row) => [$this->rank($row), -(float) $row['gap_pct']])
            ->values();
    }

    /**
     * @param  Collection<int, WorkOrder>  $workOrders
     * @return Collection<int, array<string, mixed>>
     */
    public function forWorkOrders(Collection $workOrders): Collection
    {
        $workOrders = (new EloquentCollection($workOrders->all()))->loadMissing('items', 'party', 'project');
        $payments = $this->countedByContract($workOrders->modelKeys());

        return $workOrders->toBase()->map(fn (WorkOrder $workOrder) => $this->row($workOrder, $payments->get($workOrder->id, collect())));
    }

    public function forContract(WorkOrder $workOrder): array
    {
        return $this->forWorkOrders(collect([$workOrder]))->first();
    }

    /**
     * Item-wise ratio for one contract. Payments tagged to no item count towards the
     * contract but not towards any line; they are returned as "unallocated".
     *
     * @return array{lines: list<array<string, mixed>>, unallocated: string}
     */
    public function lines(WorkOrder $workOrder): array
    {
        $workOrder->loadMissing('items.boqItem', 'project');
        $percents = $this->progress->workOrderLinePercents($workOrder);
        $payments = $this->countedByContract([$workOrder->id])->get($workOrder->id, collect());
        $byItem = $payments->whereNotNull('boq_item_id')->groupBy('boq_item_id');

        $lines = $workOrder->items->map(function ($line) use ($workOrder, $percents, $byItem) {
            $value = Dec::of($line->qty)->multipliedBy(Dec::of($line->rate));

            return [
                'line' => $line,
                'boq_item' => $line->boqItem,
                'rate' => Dec::money($line->rate),
                ...$this->compare($value, $this->total($byItem->get($line->boq_item_id, collect())), $percents[$line->id], $workOrder->project->payment_gap_alert_pct),
            ];
        });

        return [
            'lines' => $lines->values()->all(),
            'unallocated' => $this->total($payments->whereNull('boq_item_id')),
        ];
    }

    /**
     * Contract totals across rows. The contract's own limit does not apply across projects,
     * so over_limit is true when any one contract is over its own project's limit.
     */
    public function rollup(Collection $rows): array
    {
        $contract = BigDecimal::zero();
        $paid = BigDecimal::zero();
        $advance = BigDecimal::zero();
        $earned = BigDecimal::zero();

        foreach ($rows as $row) {
            $value = Dec::of($row['contract_value']);
            $contract = $contract->plus($value);
            $paid = $paid->plus(Dec::of($row['paid']));
            $advance = $advance->plus(Dec::of($row['advance']));
            $earned = $earned->plus(Dec::of($row['earned']));
        }

        return [
            ...$this->compare($contract, $paid, $this->progress->percent($earned, $contract), '100', Dec::money($earned)),
            'advance' => Dec::money($advance),
            'over_limit' => $rows->contains('over_limit', true),
            'contracts' => $rows->count(),
            'contracts_ahead' => $rows->where('status', 'red')->count(),
        ];
    }

    /** What approving this payment would do to the contract. */
    public function impact(ContractorPayment $payment): array
    {
        $payment->loadMissing('workOrder', 'boqItem', 'milestone');
        $now = $this->forContract($payment->workOrder);

        return [
            'now' => $now,
            'after' => $payment->status === 'IN_REVIEW'
                ? $this->compare($now['contract_value'], Dec::of($now['paid'])->plus(Dec::of($payment->amount)), $now['work_pct'], $now['limit_pct'], $now['earned'])
                : null,
            'item' => $payment->boq_item_id ? collect($this->lines($payment->workOrder)['lines'])->firstWhere('boq_item.id', $payment->boq_item_id) : null,
        ];
    }

    /** Add the money rows at a glance for the top of a board. */
    public function summary(Collection $rows): array
    {
        $contract = BigDecimal::zero();
        $paid = BigDecimal::zero();

        foreach ($rows as $row) {
            $contract = $contract->plus(Dec::of($row['contract_value']));
            $paid = $paid->plus(Dec::of($row['paid']));
        }

        return [
            'contract_value' => Dec::money($contract),
            'paid' => Dec::money($paid),
            'balance_money' => Dec::money($contract->minus($paid)),
            'ahead' => $rows->where('status', 'red')->count(),
            'over_limit' => $rows->where('over_limit', true)->count(),
        ];
    }

    private function row(WorkOrder $workOrder, Collection $payments): array
    {
        $earned = $this->progress->workOrderEarned($workOrder);

        return [
            'work_order' => $workOrder,
            'party' => $workOrder->party,
            'project' => $workOrder->project,
            'advance' => $this->total($payments->where('is_advance', true)),
            'earned' => $earned,
            ...$this->compare($workOrder->totalValue(), $this->total($payments), $this->progress->workOrderPercent($workOrder), $workOrder->project->payment_gap_alert_pct, $earned),
        ];
    }

    /** @return Collection<int, Collection<int, ContractorPayment>> */
    private function countedByContract(array $workOrderIds): Collection
    {
        return ContractorPayment::counted()
            ->whereIn('work_order_id', $workOrderIds)
            ->get(['work_order_id', 'amount', 'is_advance', 'boq_item_id', 'contractor_milestone_id'])
            ->groupBy('work_order_id');
    }

    private function total(Collection $payments): string
    {
        return Dec::money($payments->reduce(fn (BigDecimal $sum, $payment) => $sum->plus(Dec::of($payment->amount)), BigDecimal::zero()));
    }

    private function rank(array $row): int
    {
        return match (true) {
            $row['over_limit'] => 0,
            $row['status'] === 'red' => 1,
            $row['status'] === 'green' => 2,
            default => 3,
        };
    }
}
