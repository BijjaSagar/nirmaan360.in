<?php

namespace App\Services\SiteAlerts\Cards;

use App\Models\Project;
use App\Models\ProjectFloor;
use App\Models\User;
use App\Services\SiteAlerts\Card;
use App\Services\SiteAlerts\Contracts\DashboardCard;
use App\Services\SiteProgress\ProgressReport;
use Carbon\CarbonInterface;

/** Plumbing and electrical items marked pending, floor by floor, from site_progress's stage tracker. */
class StagesCard implements DashboardCard
{
    public function __construct(private ProgressReport $report) {}

    public function cards(Project $project, User $viewer, CarbonInterface $today): iterable
    {
        if (! $viewer->can('progress.view')) {
            return;
        }

        $pending = $this->report->mepPendingByFloor($project);

        if ($pending === []) {
            return;
        }

        $floors = ProjectFloor::with('parent:id,name')->whereIn('id', array_keys($pending))->orderBy('sort')->orderBy('id')->get();

        yield new Card(
            key: 'stages',
            title: __('Plumbing and electrical'),
            order: 58,
            value: (string) array_sum($pending),
            note: __('Stages marked pending, to finish before plaster, POP or paint covers them'),
            tone: Card::WARN,
            rows: $floors->map(fn (ProjectFloor $floor) => [
                'label' => $this->report->floorLabel($floor),
                'value' => (string) ($pending[$floor->id] ?? 0),
                'href' => route('site-stages.checklist', $floor),
                'tone' => Card::WARN,
            ])->values()->all(),
            href: route('site-stages.index', $project),
            action: __('Stage tracker'),
        );
    }
}
