<?php

namespace App\Services\RateHelper\Ranking;

use App\Models\AiTextCall;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\RateSuggestionLine;
use App\Models\RateSuggestionRanking;
use App\Models\RateSuggestionRun;
use App\Models\User;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\NothingToSend;
use App\Services\DocumentReading\ReadRefused;
use App\Services\DocumentReading\SpendRefused;
use App\Services\DocumentReading\TextCalls;
use App\Services\RateHelper\RateReview;
use App\Support\ClientUuidRepeat;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Rank with AI" on a run of rate suggestions.
 *
 * The app's own order (the code score) always stands without it. Ranking sends the lines that are
 * still to decide, in parts of one text call each, with their candidates' descriptions and units as
 * the person who pressed may see them. The model only puts each line's candidates in order and says
 * whether each is the same work, with a short note. It never sees a rate and nothing it writes
 * reaches a rate field: every rate is read by the app from the record, at display and at accept.
 *
 * Looking at a run and its ranking never depends on the AI service. Only pressing the button makes
 * a model call, and only that route carries the AI gates.
 */
class RateRanking
{
    public const RANKING = 'ranking';

    public const DONE = 'done';

    public const NONE = 'none';

    /** A part that never got its call this long after it was made was stopped half way, and is closed. */
    private const NOT_STARTED_MINUTES = 2;

    private const LOCALES = ['en', 'hi', 'mr'];

    public function __construct(
        private RateReview $review,
        private RankingParts $parts,
        private TextCalls $calls,
        private AiSpend $spend,
    ) {}

    /** The language the notes are written in: the language of the person who presses, if the app writes it. */
    public static function locale(): string
    {
        $locale = app()->getLocale();

        return in_array($locale, self::LOCALES, true) ? $locale : 'en';
    }

    /**
     * Has the model rank every line of the run that is still to decide and not ranked yet. Returns the
     * parts of this press, whether calls were made now, and why later parts could not start (the first
     * part's refusal is thrown instead). While a ranking of the run is under way, or for a repeated
     * client_uuid, nothing new is asked and nothing is charged.
     *
     * @return array{0: list<RateSuggestionRanking>, 1: bool, 2: SpendRefused|ReadRefused|null}
     *
     * @throws ValidationException Nothing is waiting to be ranked, or a client_uuid of another run.
     * @throws AuthorizationException
     * @throws ReadRefused
     * @throws SpendRefused
     */
    public function ask(RateSuggestionRun $run, User $by, ?string $clientUuid = null): array
    {
        if ($clientUuid !== null && ($earlier = $this->byClientUuid($run, $clientUuid)) !== []) {
            return [$earlier, false, null];
        }

        $locale = self::locale();

        $made = DB::transaction(function () use ($run, $by, $clientUuid, $locale) {
            // One press at a time per run: a second press waits here and then finds the first one's parts.
            RateSuggestionRun::withoutGlobalScopes()->where('organization_id', $run->organization_id)->lockForUpdate()->find($run->id);

            // The same press sent twice at once: the second finds the first one's parts once it has the lock.
            if ($clientUuid !== null && ($earlier = $this->byClientUuid($run, $clientUuid)) !== []) {
                return [$earlier, false];
            }

            $this->settle($run, false);
            $open = $this->rankings($run)->where('status', RateSuggestionRanking::ASKED)->values()->all();

            if ($open !== []) {
                return [$open, false];
            }

            $parts = $this->parts->split($this->review->for($run, $by)['lines'], $locale);

            if ($parts === []) {
                return [[], false];
            }

            $rows = [];

            foreach ($parts as $number => $lines) {
                $rows[] = RateSuggestionRanking::create([
                    'organization_id' => $run->organization_id,
                    'project_id' => $run->project_id,
                    'run_id' => $run->id,
                    'requested_by' => $by->id,
                    'client_uuid' => $clientUuid,
                    'part' => $number + 1,
                    'locale' => $locale,
                    'lines' => count($lines),
                    'material' => $lines,
                    'status' => RateSuggestionRanking::ASKED,
                ]);
            }

            return [$rows, true];
        });

        [$rows, $new] = $made;

        if ($rows === []) {
            if ($this->rankedLines($run) > 0) {
                return [[], false, null];
            }

            throw ValidationException::withMessages(['run' => __('No line here is waiting to be ranked. Lines that are decided, lump sums and lines with nothing found are not sent.')]);
        }

        if (! $new) {
            return [$rows, false, null];
        }

        return $this->start($run, $rows, $by);
    }

    /**
     * Where the ranking of a run stands, for the screen's poll and the API. Looks after the calls that
     * are still open each time it is read, so a ranking someone is waiting for comes to an end even
     * when the scheduler is not running.
     *
     * @return array{run_id: int, status: string, ranked_lines: int, parts: list<array{id: int, part: int, lines: int, status: string, reason: ?string, call_id: ?int}>}
     */
    public function status(RateSuggestionRun $run, bool $tend = true): array
    {
        $this->settle($run, $tend);
        $rankings = $this->rankings($run);

        return [
            'run_id' => (int) $run->id,
            'status' => $this->state($rankings),
            'ranked_lines' => $this->rankedLines($run),
            'parts' => $rankings->map(fn (RateSuggestionRanking $ranking) => [
                'id' => (int) $ranking->id,
                'part' => (int) $ranking->part,
                'lines' => (int) $ranking->lines,
                'status' => (string) $ranking->status,
                'reason' => $ranking->reason,
                'call_id' => $ranking->ai_text_call_id === null ? null : (int) $ranking->ai_text_call_id,
            ])->values()->all(),
        ];
    }

    /**
     * What the review screen shows about ranking. Never calls the AI service.
     *
     * @param  list<array<string, mixed>>  $lines  RateReview::for(...)['lines'], already read as this person.
     * @return array{status: string, ranked_lines: int, waiting: int, can_rank: bool, credits: ?string, plan_stops: bool, failed_lines: int, offered: bool}
     */
    public function forPage(RateSuggestionRun $run, User $viewer, array $lines): array
    {
        $this->settle($run, false);
        $rankings = $this->rankings($run);
        $status = $this->state($rankings);
        $waiting = count($this->parts->waiting($lines));
        $mayRank = $this->mayRank($viewer);
        $canRank = $mayRank && $status !== self::RANKING && $waiting > 0;

        // Lines of the parts that failed since the last part that was ranked, while lines still wait.
        $lastRanked = (int) ($rankings->where('status', RateSuggestionRanking::RANKED)->max('id') ?? 0);
        $failed = (int) $rankings
            ->filter(fn (RateSuggestionRanking $ranking) => $ranking->id > $lastRanked && $ranking->status === RateSuggestionRanking::FAILED)
            ->sum('lines');

        return [
            'status' => $status,
            'ranked_lines' => $this->rankedLines($run),
            'waiting' => $waiting,
            'can_rank' => $canRank,
            'credits' => $canRank ? $this->parts->credits($this->parts->split($lines, self::locale()), self::locale()) : null,
            'plan_stops' => $this->planStops($viewer),
            'failed_lines' => min($failed, $waiting),
            // The card is shown while ranking can be asked for, has been, or is held back only by the plan.
            'offered' => $mayRank || $rankings->isNotEmpty() || $this->planStops($viewer),
        ];
    }

    /** Whether this person may press "Rank with AI" now: the AI is on for the company, and they may use it. */
    public function mayRank(?User $viewer): bool
    {
        if ($viewer === null || ! $viewer->can('agents.use')) {
            return false;
        }

        $organization = Organization::find($viewer->organization_id);

        return $organization !== null && $this->spend->aiOn($organization);
    }

    /** Whether the AI is on for the platform but this person's company's plan stops it, for the line that says which plans have it. */
    public function planStops(?User $viewer): bool
    {
        $organization = $viewer === null ? null : Organization::find($viewer->organization_id);

        return $organization !== null && $this->spend->aiOn() && ! $organization->allowsFeature('ai_agents');
    }

    /**
     * Makes the call for each new part, in order. When a part cannot start, it and every later part
     * end as failed with the reason, and keep nothing. A refusal of the first part is thrown.
     *
     * @param  list<RateSuggestionRanking>  $rows
     * @return array{0: list<RateSuggestionRanking>, 1: bool, 2: SpendRefused|ReadRefused|null}
     */
    private function start(RateSuggestionRun $run, array $rows, User $by): array
    {
        $calls = [];
        $refusal = null;

        foreach ($rows as $index => $row) {
            try {
                $call = $this->calls->start(RankRequest::PURPOSE, $row, $by);
            } catch (SpendRefused|ReadRefused|AuthorizationException|NothingToSend $refused) {
                $reason = match (true) {
                    $refused instanceof SpendRefused, $refused instanceof ReadRefused => $refused->reason,
                    $refused instanceof NothingToSend => $refused->reason,
                    default => 'not_allowed',
                };

                foreach (array_slice($rows, $index) as $left) {
                    $this->close($left, $reason);
                }

                if ($index === 0) {
                    throw $refused;
                }

                $refusal = $refused instanceof SpendRefused || $refused instanceof ReadRefused ? $refused : null;

                break;
            }

            RateSuggestionRanking::withoutGlobalScopes()->whereKey($row->id)->where('organization_id', $row->organization_id)->update(['ai_text_call_id' => $call->id]);
            $calls[] = (int) $call->id;
        }

        AuditLog::record('rate_suggestions.ranking_asked', $run, null, [
            'parts' => count($calls), 'lines' => array_sum(array_map(fn (RateSuggestionRanking $row) => (int) $row->lines, array_slice($rows, 0, count($calls)))), 'ai_text_call_ids' => $calls,
        ], $by);

        return [$this->rankings($run)->whereIn('id', array_map(fn (RateSuggestionRanking $row) => $row->id, $rows))->values()->all(), true, $refusal];
    }

    /**
     * Brings the parts that are still "asked" in line with their calls: a call that ended without its
     * part being told (its handler failed) closes the part; a part whose call was never made is
     * closed after a while. With $tend, open calls are looked after first, as a status request does.
     */
    private function settle(RateSuggestionRun $run, bool $tend): void
    {
        foreach ($this->rankings($run)->where('status', RateSuggestionRanking::ASKED) as $ranking) {
            if ($ranking->ai_text_call_id === null) {
                if ($ranking->created_at !== null && $ranking->created_at->lt(now()->subMinutes(self::NOT_STARTED_MINUTES))) {
                    $this->close($ranking, 'not_started');
                }

                continue;
            }

            $call = AiTextCall::withoutGlobalScopes()->where('organization_id', $ranking->organization_id)->find($ranking->ai_text_call_id);

            if ($call !== null && $call->isOpen() && $tend) {
                $call = $this->calls->watch($call);
            }

            if ($call === null || ! $call->isOpen()) {
                $this->close($ranking, (string) ($call?->fail_reason ?? 'internal_error'));
            }
        }
    }

    /** Ends a part that is still "asked" as failed. Nothing is kept of what it was to send. */
    private function close(RateSuggestionRanking $ranking, string $reason): void
    {
        RateSuggestionRanking::withoutGlobalScopes()
            ->whereKey($ranking->id)
            ->where('organization_id', $ranking->organization_id)
            ->where('status', RateSuggestionRanking::ASKED)
            ->update(['status' => RateSuggestionRanking::FAILED, 'reason' => mb_substr($reason, 0, 24), 'material' => null, 'updated_at' => now()]);
    }

    /** @param  Collection<int, RateSuggestionRanking>  $rankings */
    private function state(Collection $rankings): string
    {
        return match (true) {
            $rankings->contains(fn (RateSuggestionRanking $ranking) => $ranking->status === RateSuggestionRanking::ASKED) => self::RANKING,
            $rankings->isNotEmpty() => self::DONE,
            default => self::NONE,
        };
    }

    /** @return Collection<int, RateSuggestionRanking> Every part of every press on the run, oldest first. */
    private function rankings(RateSuggestionRun $run): Collection
    {
        return RateSuggestionRanking::withoutGlobalScopes()
            ->where('organization_id', $run->organization_id)
            ->where('run_id', $run->id)
            ->orderBy('id')
            ->get();
    }

    private function rankedLines(RateSuggestionRun $run): int
    {
        return RateSuggestionLine::withoutGlobalScopes()
            ->where('organization_id', $run->organization_id)
            ->where('run_id', $run->id)
            ->get(['id', 'candidates'])
            ->filter(fn (RateSuggestionLine $line) => $line->isRanked())
            ->count();
    }

    /**
     * The parts a press with this client_uuid made, when it was a press on this run.
     *
     * @return list<RateSuggestionRanking>
     *
     * @throws ValidationException When the client_uuid belongs to a press on another run, or one this person cannot reach.
     */
    private function byClientUuid(RateSuggestionRun $run, string $clientUuid): array
    {
        $first = ClientUuidRepeat::reachable(
            RateSuggestionRanking::withoutGlobalScopes()->where('organization_id', $run->organization_id)->where('client_uuid', $clientUuid)->orderBy('id')->first()
        );

        if ($first === null) {
            return [];
        }

        if ((int) $first->run_id !== (int) $run->id) {
            throw ValidationException::withMessages(['client_uuid' => [__('Record not found on the server.')]]);
        }

        return $this->rankings($run)->where('client_uuid', $clientUuid)->values()->all();
    }
}
