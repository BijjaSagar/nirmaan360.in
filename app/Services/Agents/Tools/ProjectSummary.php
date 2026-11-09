<?php

namespace App\Services\Agents\Tools;

use App\Models\BoqItem;
use App\Models\Issue;
use App\Models\MeasurementEntry;
use App\Models\RaBill;
use App\Support\Dec;

class ProjectSummary extends Tool
{
    public function name(): string
    {
        return 'project_summary';
    }

    public function description(): string
    {
        return 'One project at a glance: contract value, BOQ value, what has been billed and paid, open issues, the latest daily report, and its sites.';
    }

    public function schema(): array
    {
        return $this->objectSchema(['project_id' => $this->projectId()], ['project_id']);
    }

    public function permission(): string
    {
        return 'project.view';
    }

    public function run(array $input, ToolContext $context): ToolResult
    {
        $project = $context->project($input['project_id']);

        $boqValue = BoqItem::where('project_id', $project->id)->get()
            ->reduce(fn ($sum, BoqItem $item) => $sum->plus(Dec::of($item->amount())), Dec::of(0));

        $bills = RaBill::where('project_id', $project->id)->where('type', 'client')->where('status', '!=', 'CANCELLED');

        return new ToolResult(
            [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'status' => $project->status,
                'location' => $project->location,
                'start_date' => $project->start_date?->toDateString(),
                'end_date' => $project->end_date?->toDateString(),
                'contract_value' => $project->contract_value,
                'boq_value' => Dec::money($boqValue),
                'client_bills' => [
                    'count' => (clone $bills)->count(),
                    'gross_billed' => Dec::money((clone $bills)->sum('gross_amount')),
                    'approved_or_paid_gross' => Dec::money((clone $bills)->whereIn('status', ['APPROVED', 'PAID'])->sum('gross_amount')),
                    'paid_net' => Dec::money((clone $bills)->where('status', 'PAID')->sum('net_payable')),
                ],
                'measurements_in_review' => MeasurementEntry::where('project_id', $project->id)->where('status', 'IN_REVIEW')->count(),
                'open_issues' => Issue::where('project_id', $project->id)->whereIn('status', ['open', 'in_progress'])->count(),
                'latest_dpr_date' => $project->dprs()->max('report_date'),
                'sites' => $project->sites->map(fn ($site) => ['id' => $site->id, 'name' => $site->name])->all(),
            ],
            [ToolResult::source('project', $project->id, $project->name, route('projects.show', $project, false))],
        );
    }
}
