<?php

namespace App\Services\SiteDiary;

use App\Models\ContractTerm;
use App\Models\Hindrance;
use App\Models\Project;
use App\Services\ContractTerms\ContractTerms;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The date a notice falls on for a hindrance.
 *
 * The app holds no notice period. A date comes from the notice periods of the client contract
 * that a person typed and confirmed under Contract terms, and from nothing else: the number of
 * days entered is added to the day the person said the count starts on. With no such term there
 * is no date. The terms used are the ones in force on the day the hindrance started.
 *
 * Asks ContractTerms::effective(), which filters by company and project itself, so the answer is
 * the same from the scheduler, where nobody is signed in.
 */
class NoticeDeadlines
{
    public function __construct(private ContractTerms $terms) {}

    /**
     * @return list<NoticeDeadline> One for each notice that has a confirmed period in force on the day the hindrance started. Empty when there is none.
     */
    public function for(Hindrance $hindrance, ?Project $project = null): array
    {
        $project ??= Project::withoutGlobalScopes()->where('organization_id', $hindrance->organization_id)->findOrFail($hindrance->project_id);

        return $this->forMany($project, [$hindrance])[$hindrance->id];
    }

    /**
     * The same for several entries of one project, reading the terms once for each starting day.
     *
     * @param  iterable<Hindrance>  $hindrances
     * @return array<int, list<NoticeDeadline>> Hindrance id => its notice dates.
     */
    public function forMany(Project $project, iterable $hindrances): array
    {
        $inForce = [];
        $found = [];

        foreach ($hindrances as $hindrance) {
            if ((int) $hindrance->project_id !== (int) $project->id) {
                throw new InvalidArgumentException('The hindrance belongs to another project.');
            }

            $day = $hindrance->started_on->toDateString();
            $inForce[$day] ??= $this->terms->effective($project, null, $hindrance->started_on);
            $found[$hindrance->id] = $this->deadlines($hindrance, $inForce[$day]);
        }

        return $found;
    }

    /**
     * How the client contract of a project stands on notice periods, whatever the day:
     * how many are confirmed and how many are typed but confirmed by nobody, so they do not count.
     *
     * @return array{confirmed: int, unconfirmed: int}
     */
    public function entered(Project $project): array
    {
        $terms = ContractTerm::query()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->whereNull('work_order_id')
            ->where('kind', 'notice')
            ->get(['id', 'confirmed_at']);

        $confirmed = $terms->filter(fn (ContractTerm $term) => $term->isConfirmed())->count();

        return ['confirmed' => $confirmed, 'unconfirmed' => $terms->count() - $confirmed];
    }

    /**
     * @param  Collection<int, ContractTerm>  $terms  The confirmed terms of the client contract in force on the day the hindrance started.
     * @return list<NoticeDeadline>
     */
    private function deadlines(Hindrance $hindrance, Collection $terms): array
    {
        $completion = $terms->filter(fn (ContractTerm $term) => $term->kind === 'reference' && $term->code === 'stipulated_completion' && $term->value_date !== null)->values();
        // Two confirmed completion dates for one day would leave the app to choose, so neither is used.
        $completionDate = $completion->count() === 1 ? HindranceMath::day($completion->first()->value_date) : null;

        $byCode = $terms->filter(fn (ContractTerm $term) => $term->kind === 'notice')->groupBy('code');
        $known = array_keys((array) config('modules.contract_terms.codes.notice', []));
        $codes = [...array_intersect($known, $byCode->keys()->all()), ...array_diff($byCode->keys()->all(), $known)];

        return array_map(fn (string $code) => $this->deadline($code, $byCode->get($code)->values(), $hindrance, $completionDate), array_values($codes));
    }

    /** @param  Collection<int, ContractTerm>  $rows  The confirmed periods of one notice. */
    private function deadline(string $code, Collection $rows, Hindrance $hindrance, ?CarbonImmutable $completionDate): NoticeDeadline
    {
        $parts = [];
        $wellFormed = true;

        foreach ($rows as $term) {
            $part = $this->part($term, $hindrance, $completionDate);

            if ($part === null) {
                $wellFormed = false;
            } else {
                $parts[] = $part;
            }
        }

        $together = $rows->count() > 1;
        $combine = $together ? $rows->first()->combine : null;

        // Several periods for one notice only make sense as a group the person tied together with one choice.
        $tied = ! $together || (
            in_array($combine, ContractTerm::COMBINE, true)
            && filled($rows->first()->combine_group)
            && $rows->every(fn (ContractTerm $term) => $term->combine_group === $rows->first()->combine_group && $term->combine === $combine)
        );

        if (! $wellFormed || ! $tied) {
            return new NoticeDeadline($code, NoticeDeadline::UNCLEAR, null, null, $parts);
        }

        $dates = array_values(array_filter(array_map(fn (NoticePart $part) => $part->date, $parts)));
        $all = count($dates) === count($parts);

        if (! $together) {
            return new NoticeDeadline($code, $all ? NoticeDeadline::KNOWN : NoticeDeadline::OPEN, $dates[0] ?? null, null, $parts);
        }

        if ($all) {
            return new NoticeDeadline($code, NoticeDeadline::KNOWN, $this->pick($dates, $combine), $combine, $parts);
        }

        // Whichever comes first can only be the date already known or an earlier one. Whichever comes later cannot be told yet.
        if ($combine === 'earliest' && $dates !== []) {
            return new NoticeDeadline($code, NoticeDeadline::NOT_LATER_THAN, $this->pick($dates, 'earliest'), $combine, $parts);
        }

        return new NoticeDeadline($code, NoticeDeadline::OPEN, null, $combine, $parts);
    }

    /** One period on one hindrance, or null when the row does not hold what a notice period needs. */
    private function part(ContractTerm $term, Hindrance $hindrance, ?CarbonImmutable $completionDate): ?NoticePart
    {
        $days = $term->days;
        $start = $term->counted_from;

        if (! is_int($days) || $days < 1 || ! in_array($start, ['hindrance_start', 'hindrance_end', 'stipulated_completion'], true)) {
            return null;
        }

        [$from, $missing] = match ($start) {
            'hindrance_start' => [HindranceMath::day($hindrance->started_on), null],
            'hindrance_end' => $hindrance->ended_on === null ? [null, NoticePart::NO_END] : [HindranceMath::day($hindrance->ended_on), null],
            'stipulated_completion' => $completionDate === null ? [null, NoticePart::NO_COMPLETION_DATE] : [$completionDate, null],
        };

        return new NoticePart($term, $days, $start, $from, $from === null ? null : HindranceMath::plusDays($from, $days), $missing);
    }

    /**
     * @param  non-empty-list<CarbonImmutable>  $dates
     */
    private function pick(array $dates, string $combine): CarbonImmutable
    {
        usort($dates, fn (CarbonInterface $a, CarbonInterface $b) => $a->toDateString() <=> $b->toDateString());

        return $combine === 'earliest' ? $dates[0] : $dates[count($dates) - 1];
    }
}
