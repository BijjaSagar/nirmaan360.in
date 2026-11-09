<?php

namespace App\Services\SiteAlerts\Cards;

use App\Models\Project;
use App\Models\User;
use App\Services\SiteAlerts\Card;
use App\Services\SiteAlerts\Contracts\DashboardCard;
use App\Services\SiteAlerts\Sources\ScheduleSource;
use App\Services\SiteControl\WorkProgress;
use App\Support\Dec;
use App\Support\Figure;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Time used against work done: 90 of 180 days is 50% of the time, work 35%, so 15% behind. */
class TimeCard implements DashboardCard
{
    public function __construct(private WorkProgress $progress, private ScheduleSource $schedule) {}

    public function cards(Project $project, User $viewer, CarbonInterface $today): iterable
    {
        $today = Carbon::instance($today)->startOfDay();
        $work = $this->progress->projectPercent($project);
        $timeUsed = $this->progress->timeUsedPercent($project, $today);

        if ($timeUsed === null) {
            yield new Card(
                key: 'time',
                title: __('Time against work'),
                order: 20,
                value: '—',
                note: __('Set the start date and planned days on the project to track time.'),
                meters: [['label' => __('Work done'), 'percent' => $work, 'tone' => Card::OK]],
                href: $viewer->can('project.manage') ? route('projects.edit', $project) : null,
                action: __('Edit project'),
            );

            return;
        }

        $elapsed = max(0, (int) Carbon::parse($project->start_date)->startOfDay()->diffInDays($today, false));
        $lag = Dec::of($timeUsed)->minus(Dec::of($work));
        $limit = Dec::of(config('modules.site_alerts.schedule_lag_alert_pct', 10));

        [$note, $tone] = match (true) {
            $lag->isGreaterThanOrEqualTo($limit) => [__(':lag% behind schedule', ['lag' => Figure::trim(Dec::money($lag))]), Card::BAD],
            $lag->isPositive() => [__(':lag% behind schedule', ['lag' => Figure::trim(Dec::money($lag))]), Card::WARN],
            $lag->isNegative() => [__(':lead% ahead of schedule', ['lead' => Figure::trim(Dec::money($lag->abs()))]), Card::OK],
            default => [__('On schedule'), Card::OK],
        };

        $rows = [];

        if ($deadline = $this->schedule->deadline($project)) {
            $daysLeft = (int) $today->diffInDays($deadline, false);
            $rows[] = [
                'label' => __('Deadline'),
                'value' => $deadline->format('d M Y'),
                'sub' => $daysLeft >= 0 ? __(':days days left', ['days' => $daysLeft]) : __('Passed :days days ago', ['days' => abs($daysLeft)]),
                'tone' => $daysLeft < 0 ? Card::BAD : ($daysLeft <= (int) config('modules.site_alerts.deadline_warning_days', 30) ? Card::WARN : null),
            ];
        }

        yield new Card(
            key: 'time',
            title: __('Time against work'),
            order: 20,
            value: __(':used of :planned days', ['used' => $elapsed, 'planned' => (int) $project->planned_days]),
            note: $note,
            tone: $tone,
            meters: [
                ['label' => __('Time used'), 'percent' => $timeUsed, 'tone' => 'neutral'],
                ['label' => __('Work done'), 'percent' => $work, 'tone' => Card::OK],
            ],
            rows: $rows,
        );
    }
}
