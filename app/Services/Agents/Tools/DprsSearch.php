<?php

namespace App\Services\Agents\Tools;

use App\Models\Dpr;

class DprsSearch extends Tool
{
    public function name(): string
    {
        return 'dprs_search';
    }

    public function description(): string
    {
        return 'Daily progress reports for a project, newest first, with manpower, work done and hindrances.';
    }

    public function schema(): array
    {
        return $this->objectSchema([
            'project_id' => $this->projectId(),
            'from' => $this->date('First report date to include.'),
            'to' => $this->date('Last report date to include.'),
            'status' => ['type' => 'string', 'enum' => ['DRAFT', 'IN_REVIEW', 'RETURNED', 'APPROVED', 'REJECTED']],
            'limit' => $this->limit(),
        ], ['project_id']);
    }

    public function permission(): string
    {
        return 'dpr.view';
    }

    public function feature(): ?string
    {
        return 'site';
    }

    public function run(array $input, ToolContext $context): ToolResult
    {
        $project = $context->project($input['project_id']);
        $limit = (int) ($input['limit'] ?? 20);

        $dprs = Dpr::with('site', 'manpower', 'machinery', 'workItems.boqItem')
            ->where('project_id', $project->id)
            ->when($input['from'] ?? null, fn ($q, $date) => $q->whereDate('report_date', '>=', $date))
            ->when($input['to'] ?? null, fn ($q, $date) => $q->whereDate('report_date', '<=', $date))
            ->when($input['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('report_date')
            ->latest('id')
            ->limit($limit + 1)
            ->get();

        $shown = $dprs->take($limit);

        return new ToolResult(
            [
                'reports' => $shown->map(fn (Dpr $dpr) => [
                    'id' => $dpr->id,
                    'date' => $dpr->report_date->toDateString(),
                    'site' => $dpr->site?->name,
                    'status' => $dpr->status,
                    'weather' => $dpr->weather,
                    'summary' => $dpr->summary,
                    'hindrances' => $dpr->hindrances,
                    'manpower' => $dpr->manpower->map(fn ($row) => ['trade' => $row->trade, 'count' => (int) $row->count])->all(),
                    'machinery' => $dpr->machinery->map(fn ($row) => ['name' => $row->name, 'hours' => $row->hours])->all(),
                    'work' => $dpr->workItems->map(fn ($row) => [
                        'boq_code' => $row->boqItem?->code,
                        'description' => $row->description ?? $row->boqItem?->description,
                        'qty' => $row->qty,
                        'unit' => $row->boqItem?->unit,
                    ])->all(),
                ])->all(),
                'more_exist' => $dprs->count() > $limit,
            ],
            $shown->map(fn (Dpr $dpr) => ToolResult::source('dpr', $dpr->id, 'DPR '.$dpr->report_date->format('d M Y'), route('dprs.show', $dpr, false)))->all(),
        );
    }
}
