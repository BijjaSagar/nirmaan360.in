<?php

namespace App\Services\SiteAlerts\Spend;

use App\Models\Project;
use App\Models\RaBill;
use App\Services\SiteAlerts\Contracts\SpendSource;
use App\Support\Dec;
use Brick\Math\BigDecimal;

/** Approved and paid RA bills from subcontractors, at their gross value. */
class SubcontractorBillSpend implements SpendSource
{
    public function key(): string
    {
        return 'subcontractor_bills';
    }

    public function label(): string
    {
        return 'Contractor bills';
    }

    public function spent(Project $project): BigDecimal
    {
        $sum = RaBill::where('project_id', $project->id)
            ->where('type', 'subcontractor')
            ->whereIn('status', ['APPROVED', 'PAID'])
            ->sum('gross_amount');

        return Dec::of(Dec::money($sum));
    }
}
