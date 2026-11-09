<?php

namespace App\Services\Agents\Tools;

use App\Models\Issue;

class IssuesSearch extends Tool
{
    public function name(): string
    {
        return 'issues_search';
    }

    public function description(): string
    {
        return 'Site issues and snags for a project, most urgent first.';
    }

    public function schema(): array
    {
        return $this->objectSchema([
            'project_id' => $this->projectId(),
            'status' => ['type' => 'string', 'enum' => config('buildos.issue_statuses')],
            'priority' => ['type' => 'string', 'enum' => config('buildos.issue_priorities')],
            'limit' => $this->limit(),
        ], ['project_id']);
    }

    public function permission(): string
    {
        return 'issue.view';
    }

    public function feature(): ?string
    {
        return 'site';
    }

    public function run(array $input, ToolContext $context): ToolResult
    {
        $project = $context->project($input['project_id']);
        $limit = (int) ($input['limit'] ?? 20);
        $today = now()->toDateString();

        $issues = Issue::where('project_id', $project->id)
            ->when($input['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($input['priority'] ?? null, fn ($q, $priority) => $q->where('priority', $priority))
            ->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->latest('id')
            ->limit($limit + 1)
            ->get();

        $shown = $issues->take($limit);
        $url = route('issues.index', $project, false);

        return new ToolResult(
            [
                'issues' => $shown->map(fn (Issue $issue) => [
                    'id' => $issue->id,
                    'title' => $issue->title,
                    'description' => $issue->description,
                    'priority' => $issue->priority,
                    'status' => $issue->status,
                    'location' => $issue->location_note,
                    'due_date' => $issue->due_date?->toDateString(),
                    'overdue' => $issue->due_date !== null
                        && $issue->due_date->toDateString() < $today
                        && in_array($issue->status, ['open', 'in_progress'], true),
                ])->all(),
                'more_exist' => $issues->count() > $limit,
            ],
            $shown->map(fn (Issue $issue) => ToolResult::source('issue', $issue->id, $issue->title, $url))->all(),
        );
    }
}
