<?php

namespace App\Services\Agents\Tools;

use App\Models\Attendance;

/** Daily head counts only. Worker names, phones and wages never reach the model. */
class AttendanceSummary extends Tool
{
    public function name(): string
    {
        return 'attendance_summary';
    }

    public function description(): string
    {
        return 'Daily labour head counts for a project between two dates, by attendance status.';
    }

    public function schema(): array
    {
        return $this->objectSchema([
            'project_id' => $this->projectId(),
            'from' => $this->date('First date to include.'),
            'to' => $this->date('Last date to include.'),
        ], ['project_id', 'from', 'to']);
    }

    public function permission(): string
    {
        return 'attendance.view';
    }

    public function feature(): ?string
    {
        return 'labour';
    }

    public function run(array $input, ToolContext $context): ToolResult
    {
        $project = $context->project($input['project_id']);

        $rows = Attendance::where('project_id', $project->id)
            ->whereDate('attendance_date', '>=', $input['from'])
            ->whereDate('attendance_date', '<=', $input['to'])
            ->selectRaw('attendance_date, status, COUNT(*) as people')
            ->groupBy('attendance_date', 'status')
            ->orderBy('attendance_date')
            ->get();

        $days = $rows->groupBy(fn ($row) => substr((string) $row->getRawOriginal('attendance_date'), 0, 10))
            ->map(fn ($group, $date) => ['date' => $date, 'counts' => $group->pluck('people', 'status')->map(fn ($count) => (int) $count)->all()])
            ->values()
            ->all();

        return new ToolResult(
            ['days' => $days],
            [ToolResult::source('attendance', $project->id, 'Attendance · '.$project->name, route('attendance.index', $project, false))],
        );
    }
}
