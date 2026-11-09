<?php

namespace App\Services\SiteAlerts\Sources;

use App\Models\Alert;
use App\Models\Project;
use App\Services\SiteAlerts\Contracts\AlertSource;
use App\Services\SiteAlerts\Finding;
use App\Services\SiteControl\WorkProgress;
use App\Support\Dec;
use App\Support\Figure;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Project behind schedule, and deadline approaching with work remaining. The engineer's example:
 * 90 of 180 days gone is 50% of the time, work is 35% done, so the project is 15% behind.
 * Time and quantities only, so everyone on the site may see these alerts.
 */
class ScheduleSource implements AlertSource
{
    public const BEHIND = 'schedule.behind';

    public const DEADLINE = 'schedule.deadline';

    private const CRITICAL_WITHIN_DAYS = 7;

    public function __construct(private WorkProgress $progress) {}

    public function types(): array
    {
        return [self::BEHIND, self::DEADLINE];
    }

    public function findings(Project $project, CarbonInterface $today): iterable
    {
        $today = Carbon::instance($today)->startOfDay();
        $work = $this->progress->projectPercent($project);
        $timeUsed = $this->progress->timeUsedPercent($project, $today);
        $remaining = Dec::of(100)->minus(Dec::of($work));

        if ($timeUsed !== null && $remaining->isPositive()) {
            $lag = Dec::of($timeUsed)->minus(Dec::of($work));
            $limit = Dec::of(config('modules.site_alerts.schedule_lag_alert_pct', 10));

            if ($lag->isGreaterThanOrEqualTo($limit)) {
                $elapsed = max(0, (int) Carbon::parse($project->start_date)->startOfDay()->diffInDays($today, false));

                yield new Finding(
                    type: self::BEHIND,
                    title: 'Behind schedule',
                    message: ':time% of the time is used but :work% of the work is done, :lag% behind.',
                    params: ['time' => Figure::trim($timeUsed), 'work' => Figure::trim($work), 'lag' => Figure::trim(Dec::money($lag)), 'days' => $elapsed, 'planned' => (int) $project->planned_days],
                    severity: $lag->isGreaterThanOrEqualTo($limit->multipliedBy(2)) ? Alert::CRITICAL : Alert::WARNING,
                    data: ['time_used_pct' => $timeUsed, 'work_pct' => $work, 'lag_pct' => Dec::money($lag), 'days_elapsed' => $elapsed, 'planned_days' => (int) $project->planned_days],
                    url: route('site-dashboard.show', $project),
                );
            }
        }

        $deadline = $this->deadline($project);

        if ($deadline === null || ! $remaining->isPositive()) {
            return;
        }

        $daysLeft = (int) $today->diffInDays($deadline, false);

        if ($daysLeft > (int) config('modules.site_alerts.deadline_warning_days', 30)) {
            return;
        }

        $data = ['deadline' => $deadline->toDateString(), 'days_left' => $daysLeft, 'work_pct' => $work, 'remaining_pct' => Dec::money($remaining)];
        $params = ['date' => $deadline->format('d M Y'), 'remaining' => Figure::trim(Dec::money($remaining))];

        if ($daysLeft >= 0) {
            yield new Finding(
                type: self::DEADLINE,
                title: 'Deadline approaching',
                message: ':days days to the deadline on :date with :remaining% of the work remaining.',
                params: [...$params, 'days' => $daysLeft],
                severity: $daysLeft <= self::CRITICAL_WITHIN_DAYS ? Alert::CRITICAL : Alert::WARNING,
                data: $data,
                url: route('site-dashboard.show', $project),
            );

            return;
        }

        yield new Finding(
            type: self::DEADLINE,
            title: 'Deadline passed',
            message: 'The deadline on :date passed :days days ago with :remaining% of the work remaining.',
            params: [...$params, 'days' => abs($daysLeft)],
            severity: Alert::CRITICAL,
            data: $data,
            url: route('site-dashboard.show', $project),
        );
    }

    /** The fixed deadline when one is set, otherwise the start date plus the planned days. */
    public function deadline(Project $project): ?Carbon
    {
        if ($project->deadline_date) {
            return Carbon::parse($project->deadline_date)->startOfDay();
        }

        if ($project->start_date && $project->planned_days) {
            return Carbon::parse($project->start_date)->startOfDay()->addDays((int) $project->planned_days);
        }

        return null;
    }
}
