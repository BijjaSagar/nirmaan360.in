<?php

namespace App\Services\SiteAlerts\Sources;

use App\Models\Alert;
use App\Models\DeliveryChallan;
use App\Models\Project;
use App\Services\SiteAlerts\Contracts\AlertSource;
use App\Services\SiteAlerts\Finding;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Delivery challans entered on site whose bill has still not come, for the accountant. One alert
 * per project, so the count and the oldest date move with it and it closes when every bill is in.
 * Counts and dates only: no amount is ever named.
 */
class BillsPendingSource implements AlertSource
{
    public const TYPE = 'material.bills_pending';

    private const WARNING_FROM = 5;

    public function types(): array
    {
        return [self::TYPE];
    }

    public function findings(Project $project, CarbonInterface $today): iterable
    {
        $days = (int) config('modules.site_alerts.challan_bill_days', 7);
        $cutoff = Carbon::instance($today)->startOfDay()->subDays($days);

        $challans = DeliveryChallan::where('project_id', $project->id)
            ->where('status', 'POSTED')
            ->where('bill_status', 'pending')
            ->whereDate('challan_date', '<=', $cutoff->toDateString())
            ->orderBy('challan_date')
            ->orderBy('id')
            ->get(['id', 'number', 'challan_date']);

        if ($challans->isEmpty()) {
            return;
        }

        yield new Finding(
            type: self::TYPE,
            title: 'Challans without bills',
            message: ':count delivery challans have had no bill for more than :days days; the oldest is from :date.',
            params: ['count' => $challans->count(), 'days' => $days, 'date' => $challans->first()->challan_date->format('d M Y')],
            severity: $challans->count() >= self::WARNING_FROM ? Alert::WARNING : Alert::INFO,
            data: ['count' => $challans->count(), 'days' => $days, 'oldest' => $challans->first()->challan_date->toDateString(), 'challans' => $challans->pluck('number')->all()],
            notify: 'material_control.bills',
            url: route('material_control.bills.index', $project),
        );
    }
}
