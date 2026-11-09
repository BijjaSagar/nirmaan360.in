<?php

namespace App\Services\SiteAlerts\Spend;

use App\Models\ContractorPayment;
use App\Models\Project;
use App\Models\RaBill;
use App\Services\SiteAlerts\Contracts\SpendSource;
use App\Support\Dec;
use Brick\Math\BigDecimal;

/**
 * What each contractor has cost so far. One contract can be paid on account and billed
 * as well, for the same work, so each work order counts once at the larger of the two.
 */
class SubcontractorBillSpend implements SpendSource
{
    public function key(): string
    {
        return 'subcontractor_bills';
    }

    public function label(): string
    {
        return 'Paid or billed to contractors';
    }

    public function spent(Project $project): BigDecimal
    {
        $billed = RaBill::where('project_id', $project->id)
            ->where('type', 'subcontractor')
            ->whereIn('status', ['APPROVED', 'PAID'])
            ->selectRaw('work_order_id, SUM(gross_amount) as total')
            ->groupBy('work_order_id')
            ->pluck('total', 'work_order_id');

        $paid = ContractorPayment::where('project_id', $project->id)
            ->counted()
            ->selectRaw('work_order_id, SUM(amount) as total')
            ->groupBy('work_order_id')
            ->pluck('total', 'work_order_id');

        $spent = BigDecimal::zero();

        foreach ($billed->keys()->merge($paid->keys())->unique() as $workOrderId) {
            $bills = Dec::of(Dec::money($billed[$workOrderId] ?? 0));
            $payments = Dec::of(Dec::money($paid[$workOrderId] ?? 0));
            $spent = $spent->plus($bills->isGreaterThan($payments) ? $bills : $payments);
        }

        return $spent;
    }
}
