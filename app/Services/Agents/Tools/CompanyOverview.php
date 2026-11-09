<?php

namespace App\Services\Agents\Tools;

use App\Models\Approval;
use App\Models\Dpr;
use App\Models\Issue;
use App\Models\MeasurementEntry;
use App\Models\Project;
use App\Models\RaBill;
use App\Support\Dec;

/** Everything the owner's brief needs across the company. Roll-ups here are for reading, not billing. */
class CompanyOverview extends Tool
{
    private const ROWS = 15;

    public function name(): string
    {
        return 'company_overview';
    }

    public function description(): string
    {
        return 'The whole company today: money to collect and to pay, work ready to bill, late projects, projects with no recent daily report, urgent issues and what is waiting for approval. This app has no bank balance, so "cash" here means bills approved but not yet paid.';
    }

    public function schema(): array
    {
        return $this->objectSchema([]);
    }

    public function permission(): string
    {
        return 'agents.copilot';
    }

    public function run(array $input, ToolContext $context): ToolResult
    {
        $organizationId = $context->organizationId();
        $today = now()->toDateString();
        $sources = [];

        $active = Project::where('organization_id', $organizationId)->whereIn('status', ['planning', 'active'])->get();

        $unpaid = RaBill::with('project', 'party')
            ->where('organization_id', $organizationId)
            ->where('status', 'APPROVED')
            ->orderBy('approved_at')
            ->get();

        foreach ($unpaid->take(self::ROWS) as $bill) {
            $sources[] = ToolResult::source('ra_bill', $bill->id, $bill->number, route('ra-bills.show', $bill, false));
        }

        $late = $active->filter(fn (Project $project) => $project->end_date !== null && $project->end_date->toDateString() < $today)->values();
        $recentDpr = Dpr::where('organization_id', $organizationId)->whereDate('report_date', '>=', now()->subDays(2)->toDateString())->pluck('project_id')->unique();
        $silent = $active->where('status', 'active')->reject(fn (Project $project) => $recentDpr->contains($project->id))->values();

        foreach ($active->take(self::ROWS) as $project) {
            $sources[] = ToolResult::source('project', $project->id, $project->name, route('projects.show', $project, false));
        }

        $issues = Issue::with('project')
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['open', 'in_progress'])
            ->whereIn('priority', ['critical', 'high'])
            ->orderByRaw("CASE priority WHEN 'critical' THEN 0 ELSE 1 END")
            ->take(self::ROWS)
            ->get();

        foreach ($issues as $issue) {
            $sources[] = ToolResult::source('issue', $issue->id, $issue->title, route('issues.index', $issue->project_id, false));
        }

        return new ToolResult([
            'as_of' => $today,
            'to_collect_from_clients' => $this->billTotals($unpaid->where('type', 'client')),
            'to_pay_subcontractors' => $this->billTotals($unpaid->where('type', 'subcontractor')),
            'oldest_unpaid_bills' => $unpaid->take(self::ROWS)->map(fn (RaBill $bill) => [
                'id' => $bill->id,
                'number' => $bill->number,
                'type' => $bill->type,
                'project' => $bill->project->name,
                'party' => $bill->party?->name,
                'approved_on' => $bill->approved_at?->toDateString(),
                'net_payable' => $bill->net_payable,
            ])->values()->all(),
            'ready_to_bill' => $this->readyToBill($organizationId, $active),
            'late_projects' => $late->take(self::ROWS)->map(fn (Project $project) => ['id' => $project->id, 'name' => $project->name, 'end_date' => $project->end_date->toDateString()])->all(),
            'active_projects_with_no_dpr_in_two_days' => $silent->take(self::ROWS)->map(fn (Project $project) => ['id' => $project->id, 'name' => $project->name])->all(),
            'urgent_issues' => $issues->map(fn (Issue $issue) => ['id' => $issue->id, 'title' => $issue->title, 'priority' => $issue->priority, 'project' => $issue->project?->name, 'due_date' => $issue->due_date?->toDateString()])->all(),
            'waiting_for_approval' => Approval::where('organization_id', $organizationId)->where('status', 'IN_REVIEW')->selectRaw('doc_type, COUNT(*) as total')->groupBy('doc_type')->pluck('total', 'doc_type')->map(fn ($total) => (int) $total)->all(),
        ], $sources);
    }

    private function billTotals($bills): array
    {
        return [
            'count' => $bills->count(),
            'net_payable' => Dec::money($bills->reduce(fn ($sum, RaBill $bill) => $sum->plus(Dec::of($bill->net_payable)), Dec::of(0))),
        ];
    }

    private function readyToBill(int $organizationId, $projects): array
    {
        $names = $projects->pluck('name', 'id');

        return MeasurementEntry::query()
            ->join('boq_items', 'boq_items.id', '=', 'measurement_entries.boq_item_id')
            ->where('measurement_entries.organization_id', $organizationId)
            ->where('measurement_entries.status', 'APPROVED')
            ->whereNull('measurement_entries.billed_client_bill_id')
            ->whereIn('measurement_entries.project_id', $names->keys())
            ->selectRaw('measurement_entries.project_id, COUNT(*) as entries, SUM(measurement_entries.qty * boq_items.rate) as value')
            ->groupBy('measurement_entries.project_id')
            ->get()
            ->map(fn ($row) => [
                'project_id' => (int) $row->project_id,
                'project' => $names[$row->project_id] ?? null,
                'entries' => (int) $row->entries,
                'approx_value' => Dec::money($row->value),
            ])
            ->all();
    }
}
