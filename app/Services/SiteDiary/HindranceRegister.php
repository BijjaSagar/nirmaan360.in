<?php

namespace App\Services\SiteDiary;

use App\Models\Dpr;
use App\Models\Hindrance;
use App\Models\HindranceSource;
use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Reads the hindrance register: every entry with the figures the app works out for it, the total
 * of days with overlaps counted once, and the daily reports an entry can be made from.
 *
 * Every figure is a count of calendar days or of reports, worked out from the dates on the
 * entries and the reports. Nothing here is money, and nothing is a reading of the agreement:
 * a notice date is whatever NoticeDeadlines works out from the terms a person confirmed.
 */
class HindranceRegister
{
    public function __construct(private NoticeDeadlines $deadlines) {}

    /**
     * Every entry of the project, oldest start first, each with its figures. See figures().
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function entries(Project $project, CarbonInterface $today): Collection
    {
        $all = Hindrance::query()
            ->with(['site', 'confirmedBy', 'sources.dpr', 'supersedes'])
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->orderBy('started_on')->orderBy('id')
            ->get();

        // The correction that stands for each corrected entry.
        $correctionOf = $all->filter(fn (Hindrance $entry) => $entry->supersedes_id !== null && $entry->status !== Hindrance::DISMISSED)
            ->keyBy(fn (Hindrance $entry) => (int) $entry->supersedes_id);

        $counts = $all->filter(fn (Hindrance $entry) => $entry->isConfirmed() && ! $correctionOf->has($entry->id))->keyBy('id');
        $deadlines = $this->deadlines->forMany($project, $counts);
        $reportDays = $this->reportDays($project);

        return $all->map(fn (Hindrance $entry) => [
            ...$this->figures($entry, $today, $reportDays),
            'hindrance' => $entry,
            'counts' => $counts->has($entry->id),
            'corrected_by' => $correctionOf->get($entry->id),
            // A letter cites the entry itself, or the entry it corrects.
            'cited' => $entry->isLocked() || $entry->supersedes_id !== null,
            'deadlines' => $deadlines[$entry->id] ?? [],
        ]);
    }

    /**
     * The figures of one entry.
     *
     * days: calendar days from the start to the end, both counted, or up to today while it is still going on.
     * counted_to: the last day counted, null when it is still going on and has not started.
     * days_with_cited_report: days of the period that have a cited report which is still there.
     * days_without_report: days of the period on which the project has no daily report at all.
     * reports: how many reports are cited, how many of those are approved today, how many can still be
     *   edited, how many say something else now than when they were cited, how many are removed, and how
     *   many are dated outside the period.
     * sources: each cited report with its status today.
     *
     * @param  array<string, true>|null  $reportDays  Every day the project has a daily report, when the caller already holds it.
     * @return array<string, mixed>
     */
    public function figures(Hindrance $entry, CarbonInterface $today, ?array $reportDays = null): array
    {
        $reportDays ??= $this->reportDays(Project::withoutGlobalScopes()->where('organization_id', $entry->organization_id)->findOrFail($entry->project_id));
        $start = HindranceMath::day($entry->started_on);
        $to = HindranceMath::countedTo($entry->started_on, $entry->ended_on, $today);
        $from = $start->toDateString();
        $until = $to?->toDateString();

        $sources = $entry->sources->map(function (HindranceSource $source) {
            $report = $source->dpr;
            $gone = $report === null || $report->trashed();
            $status = $gone ? (string) $source->dpr_status : (string) $report->status;

            return [
                'source' => $source,
                'status' => $status,
                'gone' => $gone,
                'changed' => ! $gone && trim((string) $report->hindrances) !== (string) $source->excerpt,
                'approved' => ! $gone && $status === 'APPROVED',
                'editable' => ! $gone && $report->isEditable(),
            ];
        })->values();

        $citedDays = $sources->map(fn (array $row) => $row['source']->report_date->toDateString())->all();
        // A removed report is no longer a report of its day, though the entry keeps the copy of what it said.
        $standingDays = $sources->where('gone', false)->map(fn (array $row) => $row['source']->report_date->toDateString())->all();
        $inside = fn (string $day) => $until !== null && $day >= $from && $day <= $until;

        return [
            'days' => $to === null ? 0 : HindranceMath::days($start, $to),
            'counted_to' => $to,
            'ongoing' => $entry->isOngoing(),
            'days_with_cited_report' => count(array_flip(array_filter($standingDays, $inside))),
            'days_without_report' => $to === null ? 0 : HindranceMath::daysWithout($start, $to, array_keys($reportDays)),
            'reports' => [
                'cited' => $sources->count(),
                'approved' => $sources->where('approved', true)->count(),
                'editable' => $sources->where('editable', true)->count(),
                'changed' => $sources->where('changed', true)->count(),
                'gone' => $sources->where('gone', true)->count(),
                'outside' => count(array_filter($citedDays, fn (string $day) => ! $inside($day))),
                'first' => $citedDays === [] ? null : min($citedDays),
                'last' => $citedDays === [] ? null : max($citedDays),
            ],
            'sources' => $sources->all(),
        ];
    }

    /**
     * The days of the entries that count, with a day two of them share counted once.
     * An entry that is still going on is counted up to today.
     *
     * @param  iterable<Hindrance>  $hindrances
     * @return array{days: int, periods: list<array{from: string, to: string, days: int}>, entries: int, ongoing: int}
     */
    public function total(iterable $hindrances, CarbonInterface $today): array
    {
        $periods = [];
        $entries = 0;
        $ongoing = 0;

        foreach ($hindrances as $entry) {
            $entries++;
            $ongoing += $entry->isOngoing() ? 1 : 0;
            $to = HindranceMath::countedTo($entry->started_on, $entry->ended_on, $today);

            if ($to !== null) {
                $periods[] = [$entry->started_on, $to];
            }
        }

        return [...HindranceMath::union($periods), 'entries' => $entries, 'ongoing' => $ongoing];
    }

    /**
     * Daily reports of the project that say something under hindrances, newest first: what an
     * entry can be made from. Each comes with the entries that already cite it.
     *
     * @param  Hindrance|null  $with  An entry being changed or corrected: the reports it cites are always listed.
     * @return array{reports: Collection<int, Dpr>, used: array<int, list<int>>, more: bool}
     */
    public function candidates(Project $project, ?string $from = null, ?string $to = null, ?Hindrance $with = null): array
    {
        $limit = max(1, (int) config('modules.site_diary.candidates_shown', 200));

        $query = fn () => Dpr::query()
            ->with('site')
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id);

        $reports = $query()
            ->whereNotNull('hindrances')
            ->where('hindrances', '!=', '')
            ->when($from !== null, fn ($dprs) => $dprs->whereDate('report_date', '>=', $from))
            ->when($to !== null, fn ($dprs) => $dprs->whereDate('report_date', '<=', $to))
            ->orderByDesc('report_date')->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $more = $reports->count() > $limit;
        $reports = $reports->take($limit)->filter(fn (Dpr $report) => Hindrances::saysSomething($report->hindrances));

        if ($with !== null) {
            $cited = $with->sources->pluck('dpr_id')->map(fn ($id) => (int) $id)->all();
            $missing = array_diff($cited, $reports->pluck('id')->all());

            if ($missing !== []) {
                $reports = $reports->concat($query()->whereIn('id', $missing)->get()->filter(fn (Dpr $report) => Hindrances::saysSomething($report->hindrances)));
            }
        }

        $reports = $reports->sortBy([['report_date', 'desc'], ['id', 'desc']])->values();

        return ['reports' => $reports, 'used' => $this->usedIn($project, $reports->pluck('id')->all()), 'more' => $more];
    }

    /**
     * @param  list<int>  $reportIds
     * @return array<int, list<int>> Report id => the entries, dismissed ones left out, that cite it.
     */
    private function usedIn(Project $project, array $reportIds): array
    {
        if ($reportIds === []) {
            return [];
        }

        $live = Hindrance::query()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->where('status', '!=', Hindrance::DISMISSED)
            ->pluck('id');

        $used = [];

        HindranceSource::query()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->whereIn('dpr_id', $reportIds)
            ->whereIn('hindrance_id', $live)
            ->orderBy('hindrance_id')
            ->get(['dpr_id', 'hindrance_id'])
            ->each(function (HindranceSource $source) use (&$used) {
                $used[(int) $source->dpr_id][] = (int) $source->hindrance_id;
            });

        return $used;
    }

    /** @return array<string, true> Every day on which the project has a daily report, as year-month-day. */
    private function reportDays(Project $project): array
    {
        $days = [];

        Dpr::query()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->toBase()
            ->distinct()
            ->pluck('report_date')
            ->each(function ($date) use (&$days) {
                $days[substr((string) $date, 0, 10)] = true;
            });

        return $days;
    }
}
