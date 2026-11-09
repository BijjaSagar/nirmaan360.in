<?php

namespace App\Services\SiteAlerts\Spend;

use App\Models\Project;
use App\Models\VendorBill;
use App\Services\SiteAlerts\Contracts\SpendSource;
use App\Support\Dec;
use Brick\Math\BigDecimal;

/** Supplier bills booked in finance. A reversal carries negative amounts, so a reversed bill nets to nothing. */
class VendorBillSpend implements SpendSource
{
    public function key(): string
    {
        return 'vendor_bills';
    }

    public function label(): string
    {
        return 'Supplier bills';
    }

    public function spent(Project $project): BigDecimal
    {
        $sum = VendorBill::where('project_id', $project->id)
            ->whereNotIn('status', ['DRAFT', 'CANCELLED', 'REJECTED'])
            ->sum('total_amount');

        return Dec::of(Dec::money($sum));
    }
}
