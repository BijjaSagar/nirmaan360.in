<?php

namespace App\Services\SiteAlerts\Sources;

use App\Models\Alert;
use App\Models\DeliveryChallan;
use App\Models\Party;
use App\Models\Project;
use App\Services\MaterialControl\SupplierRecordService;
use App\Services\SiteAlerts\Contracts\AlertSource;
use App\Services\SiteAlerts\Finding;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;

/**
 * A supplier to this project who has delivered short more than once, anywhere in the company.
 * The count is material_control's supplier record. That module already tells the supplier-record
 * keepers the moment it happens, so this alert is for the dashboard only and sends nothing.
 */
class ShortDeliverySource implements AlertSource
{
    public const TYPE = 'supplier.repeat_short';

    public function __construct(private SupplierRecordService $suppliers) {}

    public function types(): array
    {
        return [self::TYPE];
    }

    public function findings(Project $project, CarbonInterface $today): iterable
    {
        $partyIds = DeliveryChallan::where('project_id', $project->id)->where('status', 'POSTED')->distinct()->pluck('party_id');

        if ($partyIds->isEmpty()) {
            return;
        }

        $parties = Party::withTrashed()->whereIn('id', $partyIds)->get(['id', 'name', 'organization_id']);

        foreach ($this->suppliers->summaries($parties, $project->organization_id) as $summary) {
            if ($summary['short_deliveries'] < SupplierRecordService::WARN_AT) {
                continue;
            }

            yield new Finding(
                type: self::TYPE,
                title: 'Repeat short delivery',
                message: ':supplier has delivered short :count times.',
                params: ['supplier' => $summary['name'], 'count' => $summary['short_deliveries']],
                reference: 'party:'.$summary['party_id'],
                severity: Alert::WARNING,
                data: Arr::only($summary, ['party_id', 'name', 'short_deliveries', 'rating', 'marked', 'marked_reason']),
                notify: null,
                url: route('material_control.suppliers.show', $summary['party_id']),
            );
        }
    }
}
