<?php

namespace App\Services\RateHelper\Ranking;

use App\Models\AiTextCall;
use App\Models\AuditLog;
use App\Models\RateSuggestionLine;
use App\Models\RateSuggestionRanking;
use App\Models\RateSuggestionRun;
use App\Models\User;
use App\Services\DocumentReading\Contracts\ChecksTextAnswer;
use App\Services\DocumentReading\Contracts\TextCallHandler;
use App\Services\DocumentReading\NothingToSend;

/**
 * The text call that has a model rank the candidates of the lines in one part of a ranking.
 * Registered in config/modules/rate_helper.php under text_calls.rate_match for RateSuggestionRanking.
 *
 * Runs on the queue with nobody signed in, so it reads nothing but the part it is about, and every
 * query names the company. The part holds what was read from the sources as the person who pressed.
 */
class RankCall implements ChecksTextAnswer, TextCallHandler
{
    public function __construct(private RankAnswer $answers) {}

    public function request(AiTextCall $call): array
    {
        $ranking = $this->ranking($call);

        if ($ranking === null || ! $ranking->isAsked()) {
            throw new NothingToSend;
        }

        $material = $ranking->material;

        if (! is_array($material) || $material === []) {
            throw new NothingToSend('nothing_to_rank');
        }

        return RankRequest::body($call, $material, (string) $ranking->locale);
    }

    /** An answer that names no line and candidate that were sent is refused once, and not charged. */
    public function refused(AiTextCall $call, array $output): array
    {
        $ranking = $this->ranking($call);

        // The part is gone or done with: done() finds the same and keeps nothing.
        if ($ranking === null || ! is_array($ranking->material)) {
            return [];
        }

        return $this->answers->picks($output, $ranking->material, (string) $ranking->locale) === []
            ? ['matches' => [RankRequest::NOTHING_KNOWN]]
            : [];
    }

    public function done(AiTextCall $call, array $output): void
    {
        $ranking = $this->ranking($call);

        if ($ranking === null || ! $ranking->isAsked() || ! is_array($ranking->material)) {
            throw new NothingToSend;
        }

        $picks = $this->answers->picks($output, $ranking->material, (string) $ranking->locale);
        $sent = [];

        foreach ($ranking->material as $line) {
            $sent[(int) $line['line_id']] = array_map(fn (array $candidate) => (string) $candidate['ref'], (array) $line['candidates']);
        }

        $kept = 0;

        foreach ($picks as $lineId => $linePicks) {
            $line = RateSuggestionLine::withoutGlobalScopes()
                ->where('organization_id', $ranking->organization_id)
                ->where('run_id', $ranking->run_id)
                ->find($lineId);

            if ($line === null || ! isset($sent[$lineId])) {
                continue;
            }

            // A query, not a model save: the line's audit trail would otherwise carry the model's notes.
            RateSuggestionLine::withoutGlobalScopes()->whereKey($line->id)->where('organization_id', $ranking->organization_id)->update([
                'candidates' => json_encode(self::ranked((array) $line->candidates, $sent[$lineId], $linePicks), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            $kept++;
        }

        $ranking->forceFill(['status' => RateSuggestionRanking::RANKED, 'reason' => null, 'material' => null, 'ranked_at' => now()])->save();

        $run = RateSuggestionRun::withoutGlobalScopes()->where('organization_id', $ranking->organization_id)->find($ranking->run_id);

        if ($run !== null) {
            $by = $ranking->requested_by === null ? null : User::find($ranking->requested_by);

            AuditLog::record('rate_suggestions.ranked', $run, null, [
                'ranking_id' => $ranking->id, 'part' => $ranking->part, 'lines' => $kept, 'ai_text_call_id' => $call->id,
            ], $by, (int) $ranking->organization_id);
        }
    }

    public function failed(AiTextCall $call, string $reason): void
    {
        $ranking = $this->ranking($call);

        // A part that was ranked stays ranked: a later call that failed changes nothing on it.
        if ($ranking === null || ! $ranking->isAsked()) {
            return;
        }

        $ranking->forceFill(['status' => RateSuggestionRanking::FAILED, 'reason' => mb_substr($reason, 0, 24), 'material' => null])->save();
    }

    /**
     * The shortlist of a line with the model's verdicts on it, in the new order: the same work first,
     * then similar work, then candidates the answer said nothing about, then other work; within each,
     * the model's order, and the app's own order after that. Reference figures stay last, as they were.
     * Refs, sources, rows and scores are never changed, so accepting works exactly as before.
     *
     * @param  list<array<string, mixed>>  $candidates  The stored shortlist.
     * @param  list<string>  $sentRefs  The refs that were sent for this line.
     * @param  list<array{ref: string, same_work: string, note: ?string, note_locale?: string, rank: int}>  $picks
     * @return list<array<string, mixed>>
     */
    public static function ranked(array $candidates, array $sentRefs, array $picks): array
    {
        $byRef = [];

        foreach ($picks as $pick) {
            $byRef[$pick['ref']] = $pick;
        }

        $order = ['yes' => 0, 'similar' => 1, 'no' => 3];
        $keyed = [];

        foreach (array_values($candidates) as $position => $candidate) {
            $ref = (string) ($candidate['ref'] ?? '');

            if (in_array($ref, $sentRefs, true)) {
                $pick = $byRef[$ref] ?? null;
                $candidate = [
                    ...$candidate,
                    'same_work' => $pick['same_work'] ?? null,
                    'note' => $pick['note'] ?? null,
                    'note_locale' => isset($pick['note']) ? ($pick['note_locale'] ?? null) : null,
                    'ranked' => true,
                    'rank' => $pick['rank'] ?? null,
                ];
            }

            $reference = ($candidate['score'] ?? null) === null;
            $group = $reference ? 9 : (isset($candidate['same_work']) && $candidate['same_work'] !== null ? $order[$candidate['same_work']] ?? 2 : 2);
            $keyed[] = [[$group, $candidate['rank'] ?? PHP_INT_MAX, $position], $candidate];
        }

        usort($keyed, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return array_column($keyed, 1);
    }

    private function ranking(AiTextCall $call): ?RateSuggestionRanking
    {
        if ($call->subject_id === null) {
            return null;
        }

        return RateSuggestionRanking::withoutGlobalScopes()->where('organization_id', $call->organization_id)->find($call->subject_id);
    }
}
