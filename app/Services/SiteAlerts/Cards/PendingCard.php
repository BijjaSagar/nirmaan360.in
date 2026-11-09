<?php

namespace App\Services\SiteAlerts\Cards;

use App\Models\Issue;
use App\Models\ProgressEntry;
use App\Models\Project;
use App\Models\User;
use App\Services\SiteAlerts\Card;
use App\Services\SiteAlerts\Contracts\DashboardCard;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/** Work left incomplete with a supervisor's remark, and open issues, so nothing is covered up before it is finished. */
class PendingCard implements DashboardCard
{
    private const SHOWN = 5;

    public function cards(Project $project, User $viewer, CarbonInterface $today): iterable
    {
        $pending = ProgressEntry::with('boqItem', 'floor')
            ->where('project_id', $project->id)
            ->where('is_pending', true)
            ->latest('work_date')
            ->latest('id')
            ->get();

        $issues = $viewer->can('issue.view')
            ? Issue::where('project_id', $project->id)->whereIn('status', ['open', 'in_progress'])->count()
            : 0;

        $rows = $pending->take(self::SHOWN)->map(fn (ProgressEntry $entry) => [
            'label' => Str::limit((string) ($entry->boqItem?->description ?? $entry->boqItem?->code), 60),
            'sub' => $entry->remark ? Str::limit($entry->remark, 90) : null,
            'value' => $entry->floor?->name ?? $entry->work_date?->format('d M'),
            'tone' => Card::WARN,
        ])->all();

        if ($issues > 0) {
            $rows[] = ['label' => __('Open issues'), 'value' => (string) $issues, 'href' => route('issues.index', $project), 'tone' => Card::WARN];
        }

        $count = $pending->count() + $issues;

        yield new Card(
            key: 'pending',
            title: __('Pending items'),
            order: 40,
            value: (string) $count,
            note: $count === 0
                ? __('Nothing is waiting to be finished.')
                : __(':pending pending work remarks, :issues open issues', ['pending' => $pending->count(), 'issues' => $issues]),
            tone: $count === 0 ? Card::OK : Card::WARN,
            rows: $rows,
            wide: $rows !== [],
        );
    }
}
