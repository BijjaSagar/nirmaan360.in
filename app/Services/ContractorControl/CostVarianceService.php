<?php

namespace App\Services\ContractorControl;

use App\Models\BoqItem;
use App\Models\ContractorPayment;
use App\Models\Project;
use App\Models\WorkOrderItem;
use App\Services\SiteControl\WorkProgress;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Cost variance per item = actual amount − BOQ amount. Actual is what has been approved
 * and paid against that item in this register. An item nobody has paid against yet has
 * no variance, because showing the whole BOQ amount as a saving would be untrue.
 */
class CostVarianceService
{
    public function __construct(private WorkProgress $progress) {}

    /** @return array{rows: list<array<string, mixed>>, boq: string, actual: string, variance: string, unallocated: string} */
    public function forProject(Project $project): array
    {
        $payments = ContractorPayment::counted()->where('project_id', $project->id)->get(['boq_item_id', 'amount']);
        $tagged = $payments->whereNotNull('boq_item_id')->groupBy('boq_item_id');

        $contracts = WorkOrderItem::whereHas('workOrder', fn ($query) => $query->where('project_id', $project->id))
            ->get(['boq_item_id', 'qty', 'rate'])
            ->groupBy('boq_item_id');

        $ids = $tagged->keys()->merge($contracts->keys())->unique();
        $items = BoqItem::withTrashed()->where('project_id', $project->id)->whereIn('id', $ids)->orderBy('sort')->orderBy('id')->get();

        $boqTotal = BigDecimal::zero();
        $actualTotal = BigDecimal::zero();

        $rows = $items->map(function (BoqItem $item) use ($tagged, $contracts, &$boqTotal, &$actualTotal) {
            $row = $this->row($item, $tagged->get($item->id, collect()), $contracts->get($item->id, collect()));

            if ($row['variance'] !== null) {
                $boqTotal = $boqTotal->plus(Dec::of($row['boq_amount']));
                $actualTotal = $actualTotal->plus(Dec::of($row['actual']));
            }

            return $row;
        });

        return [
            'rows' => $rows->sortByDesc(fn (array $row) => $row['variance'] === null ? -PHP_FLOAT_MAX : (float) $row['variance'])->values()->all(),
            'boq' => Dec::money($boqTotal),
            'actual' => Dec::money($actualTotal),
            'variance' => Dec::money($actualTotal->minus($boqTotal)),
            'unallocated' => $this->total($payments->whereNull('boq_item_id')),
        ];
    }

    private function row(BoqItem $item, Collection $payments, Collection $contracts): array
    {
        $boq = $item->amount();
        $actual = $this->total($payments);
        $completed = (string) $this->progress->completedQty($item->id);
        $paidAny = $payments->isNotEmpty();

        return [
            'item' => $item,
            'boq_amount' => $boq,
            'contract_amount' => Dec::money($contracts->reduce(fn (BigDecimal $sum, $line) => $sum->plus(Dec::of($line->qty)->multipliedBy(Dec::of($line->rate))), BigDecimal::zero())),
            'actual' => $actual,
            'variance' => $paidAny ? Dec::money(Dec::of($actual)->minus(Dec::of($boq))) : null,
            'variance_pct' => $paidAny && ! Dec::of($boq)->isZero() ? $this->progress->percent(Dec::of($actual)->minus(Dec::of($boq)), $boq) : null,
            'completed_qty' => $completed,
            'work_pct' => $this->progress->percent($completed, $item->qty),
        ];
    }

    private function total(Collection $payments): string
    {
        return Dec::money($payments->reduce(fn (BigDecimal $sum, $payment) => $sum->plus(Dec::of($payment->amount)), BigDecimal::zero()));
    }
}
