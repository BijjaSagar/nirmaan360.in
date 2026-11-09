<?php

namespace App\Services\ContractorControl;

use App\Models\Project;
use App\Models\WorkOrder;
use App\Services\SiteControl\WorkProgress;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Work left against days left, so the manager can push a contractor while there is time. */
class DeadlineViewService
{
    public function __construct(private WorkProgress $progress) {}

    /** @return array{overview: array<string, mixed>, contracts: list<array<string, mixed>>} */
    public function forProject(Project $project, ?Carbon $today = null): array
    {
        $today = ($today ?? today())->copy()->startOfDay();

        $workOrders = WorkOrder::with('party')->where('project_id', $project->id)->where('status', 'ACTIVE')->get();
        $figures = $this->progress->workOrderFigures($workOrders);

        $contracts = $workOrders
            ->map(function (WorkOrder $workOrder) use ($project, $today, $figures) {
                $date = $workOrder->end_date ?? $project->deadline_date;

                return [
                    'work_order' => $workOrder,
                    'party' => $workOrder->party,
                    'deadline_label' => $workOrder->end_date ? $workOrder->deadline_label : null,
                    ...$this->measure($figures[$workOrder->id]['percent'], $date, $workOrder->start_date ?? $project->start_date, $today),
                ];
            })
            ->sortBy(fn (array $row) => $row['days_left'] ?? PHP_INT_MAX)
            ->values()
            ->all();

        return [
            'overview' => $this->measure($this->progress->projectPercent($project), $project->deadline_date, $project->start_date, $today),
            'contracts' => $contracts,
        ];
    }

    private function measure(string $workPct, mixed $deadline, mixed $start, CarbonInterface $today): array
    {
        $left = BigDecimal::of(100)->minus(Dec::of($workPct));
        $left = $left->isNegative() ? BigDecimal::zero() : $left;
        $deadline = $deadline ? Carbon::parse($deadline)->startOfDay() : null;
        $start = $start ? Carbon::parse($start)->startOfDay() : null;
        $daysLeft = $deadline ? (int) $today->diffInDays($deadline, false) : null;

        $timeUsed = null;

        if ($deadline && $start && $deadline->greaterThan($start)) {
            $elapsed = max(0, (int) $start->diffInDays($today, false));
            $timeUsed = $this->progress->percent($elapsed, (int) $start->diffInDays($deadline, false));
        }

        return [
            'work_pct' => Dec::money($workPct),
            'work_left_pct' => Dec::money($left),
            'deadline' => $deadline,
            'days_left' => $daysLeft,
            'time_used_pct' => $timeUsed,
            'needed_per_day' => $daysLeft !== null && $daysLeft > 0 ? Dec::money($left->dividedBy($daysLeft, 6, RoundingMode::HalfUp)) : null,
            'state' => $this->state($left, $daysLeft, $timeUsed, $workPct),
        ];
    }

    private function state(BigDecimal $left, ?int $daysLeft, ?string $timeUsed, string $workPct): string
    {
        return match (true) {
            $left->isZero() => 'done',
            $daysLeft === null => 'no_date',
            $daysLeft < 0 => 'overdue',
            $daysLeft === 0 => 'due_today',
            $timeUsed !== null && Dec::of($timeUsed)->isGreaterThan(Dec::of($workPct)) => 'behind',
            default => 'on_track',
        };
    }
}
