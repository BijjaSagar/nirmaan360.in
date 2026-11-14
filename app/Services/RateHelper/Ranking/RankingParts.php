<?php

namespace App\Services\RateHelper\Ranking;

use App\Models\AiTextCall;
use App\Services\DocumentReading\ReadQuote;
use App\Services\DocumentReading\TextRequest;
use App\Services\RateHelper\RateFigure;
use App\Support\Dec;
use Brick\Math\RoundingMode;

/**
 * Which lines of a run can be ranked, what is sent for each of them, and how they are split into
 * parts of one text call each.
 *
 * It works from the review screen's own entries (RateReview::for), which were read from the source
 * rows as the signed-in person: a candidate that person cannot reach is not on them, so it is never
 * sent. What is sent for a candidate is its ref, the kind of source, its description and its unit.
 * Never a rate, never the project or tender it sits in.
 */
class RankingParts
{
    /** Room left in every request for the sentence that tells the model what was wrong with a refused answer. */
    private const CORRECTION_CHARS = 800;

    /** Room for personal details that become "[redacted]" on the way out, which can be longer than what they replace. */
    private const SLACK_CHARS = 400;

    public function __construct(private ReadQuote $quote) {}

    /**
     * The review entries of lines a ranking would be about: still to decide, still in the BOQ, not a
     * lump sum, with at least one candidate this person may accept, and not ranked yet.
     *
     * @param  list<array<string, mixed>>  $entries  RateReview::for(...)['lines']
     * @return list<array<string, mixed>>
     */
    public function waiting(array $entries): array
    {
        return array_values(array_filter($entries, fn (array $entry) => $entry['line']->isOpen()
            && $entry['item'] !== null
            && ! $entry['lump_sum']
            && $entry['candidates'] !== []
            && ! $entry['line']->isRanked()));
    }

    /**
     * The waiting lines in parts: at most lines_per_call lines in a part, and never more than a text
     * call may carry. Line refs start again at L1 in every part.
     *
     * @param  list<array<string, mixed>>  $entries  RateReview::for(...)['lines']
     * @return list<list<array<string, mixed>>> Per part, the material of its lines.
     */
    public function split(array $entries, string $locale): array
    {
        $most = max(1, (int) config('modules.rate_helper.rank.lines_per_call', 20));
        $room = TextRequest::longest() - self::CORRECTION_CHARS - self::SLACK_CHARS;
        $parts = [];
        $part = [];

        foreach ($this->waiting($entries) as $entry) {
            $next = [...$part, $this->line($entry, count($part) + 1)];

            if ($part !== [] && (count($next) > $most || $this->chars($next, $locale) > $room)) {
                $parts[] = $part;
                $next = [$this->line($entry, 1)];
            }

            $part = $next;
        }

        if ($part !== []) {
            $parts[] = $part;
        }

        return $parts;
    }

    /**
     * For the button's "Uses up to :count AI credits": what every part could cost at most, added up
     * and rounded up to a whole credit. Each call holds its own maximum when it is made, from the same
     * request, so this is what the wallet is asked for.
     *
     * @param  list<list<array<string, mixed>>>  $parts
     */
    public function credits(array $parts, string $locale): string
    {
        $figures = TextRequest::figures(RankRequest::PURPOSE);
        $total = Dec::of('0');

        foreach ($parts as $part) {
            $tokens = TextRequest::tokens($this->chars($part, $locale) + self::CORRECTION_CHARS);
            $total = $total->plus(Dec::of($this->quote->worst((string) $figures['model'], $tokens, (int) $figures['max_tokens'])));
        }

        return (string) $total->toScale(0, RoundingMode::Ceiling)->toScale(4);
    }

    /**
     * What one line sends, with the id of the run's line kept beside it to find it again.
     *
     * @param  array<string, mixed>  $entry  One RateReview line entry.
     * @return array{ref: string, line_id: int, description: string, unit: string, candidates: list<array{ref: string, source: string, description: string, unit: string}>}
     */
    public function line(array $entry, int $number): array
    {
        $lineChars = (int) config('modules.rate_helper.rank.line_chars', 600);
        $candidateChars = (int) config('modules.rate_helper.rank.candidate_chars', 400);

        return [
            'ref' => 'L'.$number,
            'line_id' => (int) $entry['line']->id,
            'description' => self::text((string) $entry['item']->description, $lineChars),
            'unit' => self::text((string) $entry['item']->unit, 16),
            'candidates' => array_values(array_map(function (array $candidate) use ($candidateChars) {
                /** @var RateFigure $figure */
                $figure = $candidate['figure'];

                return [
                    'ref' => (string) $candidate['ref'],
                    'source' => (string) $candidate['source'],
                    'description' => self::text($figure->description, $candidateChars),
                    'unit' => self::text($figure->unit, 16),
                ];
            }, $entry['candidates'])),
        ];
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function chars(array $lines, string $locale): int
    {
        $call = new AiTextCall(['purpose' => RankRequest::PURPOSE]);

        return TextRequest::chars(RankRequest::body($call, $lines, $locale))['all'];
    }

    /** Text on one line, cut to a length. */
    private static function text(string $text, int $most): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', mb_scrub($text, 'UTF-8')));

        return mb_strlen($text) > $most ? rtrim(mb_substr($text, 0, $most - 1)).'…' : $text;
    }
}
