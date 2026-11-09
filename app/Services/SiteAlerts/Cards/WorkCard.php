<?php

namespace App\Services\SiteAlerts\Cards;

use App\Models\BoqItem;
use App\Models\ProgressEntry;
use App\Models\Project;
use App\Models\User;
use App\Services\SiteAlerts\Card;
use App\Services\SiteAlerts\Contracts\DashboardCard;
use App\Services\SiteControl\WorkProgress;
use App\Support\Dec;
use App\Support\Figure;
use Carbon\CarbonInterface;

/** Overall work done, weighted by BOQ value, with how many lines are finished and started. */
class WorkCard implements DashboardCard
{
    public function __construct(private WorkProgress $progress) {}

    public function cards(Project $project, User $viewer, CarbonInterface $today): iterable
    {
        $percent = $this->progress->projectPercent($project);
        $items = BoqItem::where('project_id', $project->id)->get(['id', 'qty']);

        $done = ProgressEntry::where('project_id', $project->id)
            ->selectRaw('boq_item_id, SUM(qty_done) as done')
            ->groupBy('boq_item_id')
            ->pluck('done', 'boq_item_id')
            ->map(fn ($qty) => Dec::of(Dec::qty($qty)));

        $complete = $items->filter(fn (BoqItem $item) => Dec::of($item->qty)->isPositive() && ($done[$item->id] ?? Dec::of(0))->isGreaterThanOrEqualTo(Dec::of($item->qty)))->count();
        $started = $items->filter(fn (BoqItem $item) => ($done[$item->id] ?? Dec::of(0))->isPositive())->count();

        yield new Card(
            key: 'work',
            title: __('Work done'),
            order: 10,
            value: $items->isEmpty() ? '—' : Figure::trim($percent).'%',
            note: $items->isEmpty()
                ? __('Add the BOQ to start tracking work.')
                : __(':complete of :total BOQ lines complete, :started started', ['complete' => $complete, 'total' => $items->count(), 'started' => $started]),
            tone: $items->isEmpty() ? null : Card::OK,
            meters: $items->isEmpty() ? [] : [['label' => __('Work done'), 'percent' => $percent, 'tone' => Card::OK]],
            href: route('boq.index', $project),
            action: __('BOQ'),
        );
    }
}
