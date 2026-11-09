<?php

namespace App\Services\SiteAlerts\Cards;

use App\Models\Alert;
use App\Models\Project;
use App\Models\User;
use App\Services\SiteAlerts\Card;
use App\Services\SiteAlerts\Contracts\DashboardCard;
use Carbon\CarbonInterface;

/** Open alerts this person may see, worst first. */
class AlertsCard implements DashboardCard
{
    private const SHOWN = 4;

    public function cards(Project $project, User $viewer, CarbonInterface $today): iterable
    {
        if (! $viewer->can('alerts.view')) {
            return;
        }

        $alerts = Alert::where('project_id', $project->id)
            ->open()
            ->visibleTo($viewer)
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->latest('first_seen_at')
            ->get();

        $critical = $alerts->where('severity', Alert::CRITICAL)->count();

        $rows = $alerts->take(self::SHOWN)->map(fn (Alert $alert) => [
            'label' => $alert->titleIn(),
            'sub' => $alert->messageIn(),
            'value' => $alert->first_seen_at->format('d M'),
            'href' => $alert->url,
            'tone' => $alert->severity === Alert::CRITICAL ? Card::BAD : ($alert->severity === Alert::WARNING ? Card::WARN : null),
        ])->all();

        yield new Card(
            key: 'alerts',
            title: __('Open alerts'),
            order: 50,
            value: (string) $alerts->count(),
            note: match (true) {
                $alerts->isEmpty() => __('Nothing needs attention.'),
                $critical > 0 => __(':count critical', ['count' => $critical]),
                default => __('Worth a look today'),
            },
            tone: match (true) {
                $critical > 0 => Card::BAD,
                $alerts->isNotEmpty() => Card::WARN,
                default => Card::OK,
            },
            rows: $rows,
            href: route('alerts.index', $project),
            action: __('All alerts'),
            wide: $rows !== [],
        );
    }
}
