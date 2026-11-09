<?php

namespace App\Services\Reports;

use App\Models\BoqItem;
use App\Models\Concerns\OrganizationScope;
use App\Models\MeasurementEntry;
use App\Models\Organization;
use App\Models\Party;
use App\Models\Project;
use App\Support\Dec;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The measurement sheet for one project and period: approved entries grouped by BOQ item in the
 * usual measurement book layout, with quantity for the period, before it, and to date.
 *
 * Only approved entries appear, so the sheet can be signed and handed to the client.
 */
class MeasurementSheetService
{
    /** Most entries on one sheet. A longer period should be split. */
    public const MAX_ENTRIES = 600;

    public function pdf(Project $project, CarbonImmutable $from, CarbonImmutable $to): string
    {
        return PdfRenderer::render('modules.reports.pdf.measurement-sheet', $this->build($project, $from, $to), 'landscape');
    }

    public function filename(Project $project, CarbonImmutable $from, CarbonImmutable $to): string
    {
        return 'measurement-sheet-'.$project->code.'-'.$from->format('Ymd').'-'.$to->format('Ymd').'.pdf';
    }

    /**
     * @return array{organization: Organization, project: Project, client: ?string, from: CarbonImmutable, to: CarbonImmutable, items: list<array<string,mixed>>, total_amount: string, entry_count: int}
     */
    public function build(Project $project, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $organizationId = $project->organization_id;

        $entries = MeasurementEntry::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)->where('project_id', $project->id)->where('status', 'APPROVED')
            ->whereDate('measured_on', '>=', $from->toDateString())->whereDate('measured_on', '<=', $to->toDateString())
            ->orderBy('measured_on')->orderBy('id')
            ->limit(self::MAX_ENTRIES + 1)->get();

        if ($entries->count() > self::MAX_ENTRIES) {
            throw ValidationException::withMessages(['to' => __('This period has more than :max measurements. Choose a shorter period.', ['max' => self::MAX_ENTRIES])]);
        }

        $itemIds = $entries->pluck('boq_item_id')->unique();

        $boqItems = BoqItem::withoutGlobalScope(OrganizationScope::class)->withTrashed()
            ->where('organization_id', $organizationId)->whereIn('id', $itemIds)
            ->orderBy('sort')->orderBy('id')->get();

        // Approved quantity before the period, by item. One row each, so nothing is added up in PHP.
        $before = MeasurementEntry::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)->where('project_id', $project->id)->where('status', 'APPROVED')
            ->whereIn('boq_item_id', $itemIds)->whereDate('measured_on', '<', $from->toDateString())
            ->selectRaw('boq_item_id, sum(qty) as total')->groupBy('boq_item_id')->pluck('total', 'boq_item_id');

        $items = [];
        $totalAmount = Dec::of(0);

        foreach ($boqItems as $item) {
            $rows = [];
            $period = Dec::of(0);

            foreach ($entries->where('boq_item_id', $item->id) as $entry) {
                $period = $period->plus(Dec::of($entry->qty));
                $rows[] = [
                    'date' => $entry->measured_on,
                    'description' => collect([$entry->description, $entry->location])->filter()->implode(' · '),
                    'nos' => $entry->nos === null ? null : Dec::qty($entry->nos),
                    'length' => $entry->length,
                    'breadth' => $entry->breadth,
                    'depth' => $entry->depth,
                    'qty' => Dec::qty($entry->qty),
                    'remarks' => $entry->remarks,
                ];
            }

            $previous = Dec::of(Dec::qty($before->get($item->id) ?? 0));
            $amount = Dec::of(Dec::money($period->multipliedBy(Dec::of($item->rate))));
            $totalAmount = $totalAmount->plus($amount);

            $items[] = [
                'code' => $item->code,
                'description' => $item->description,
                'unit' => $item->unit,
                'boq_qty' => Dec::qty($item->qty),
                'rate' => Dec::money($item->rate),
                'rows' => $rows,
                'period_qty' => Dec::qty($period),
                'previous_qty' => Dec::qty($previous),
                'cumulative_qty' => Dec::qty($previous->plus($period)),
                'amount' => Dec::money($amount),
            ];
        }

        return [
            'organization' => Organization::findOrFail($organizationId),
            'project' => $project,
            'client' => $project->client_id
                ? Party::withoutGlobalScope(OrganizationScope::class)->withTrashed()->where('organization_id', $organizationId)->find($project->client_id)?->name
                : null,
            'from' => $from,
            'to' => $to,
            'items' => $items,
            'total_amount' => Dec::money($totalAmount),
            'entry_count' => $entries->count(),
        ];
    }
}
