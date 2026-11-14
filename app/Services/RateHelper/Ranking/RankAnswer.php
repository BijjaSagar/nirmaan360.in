<?php

namespace App\Services\RateHelper\Ranking;

use App\Services\Wording\SlotGuard;

/**
 * What of a model's ranking may be kept. Model text is untrusted: a pick is kept only when its line
 * and its candidate are refs that were sent for that line, the first time each is named; a note is
 * kept only when it passes Guard A (no digit, currency, percent, month, figure word or token), and
 * is cut to a short length. Nothing in an answer can add a candidate, a rate or a figure.
 */
class RankAnswer
{
    /** What the model may say about a candidate: the same work, the same kind of work with a difference, or other work. */
    public const VERDICTS = ['yes', 'similar', 'no'];

    public function __construct(private SlotGuard $guard) {}

    /**
     * The usable picks of an answer, by the id of the run's line they are about, in the model's order.
     * A line the answer says nothing usable about is left out.
     *
     * @param  array<string, mixed>  $output  The answer, already in the shape of RankRequest::SCHEMA.
     * @param  list<array<string, mixed>>  $material  The lines that were sent, as RankingParts builds them.
     * @return array<int, list<array{ref: string, same_work: string, note: ?string, note_locale: string, rank: int}>>
     */
    public function picks(array $output, array $material, string $locale): array
    {
        $sent = [];

        foreach ($material as $line) {
            $refs = array_map(fn (array $candidate) => (string) $candidate['ref'], (array) ($line['candidates'] ?? []));
            $sent[self::ref($line['ref'] ?? '')] = ['line_id' => (int) $line['line_id'], 'refs' => array_flip($refs)];
        }

        $kept = [];

        foreach ((array) ($output['matches'] ?? []) as $match) {
            $line = $sent[self::ref($match['line'] ?? '')] ?? null;

            // A line named twice is taken the first time.
            if ($line === null || isset($kept[$line['line_id']])) {
                continue;
            }

            $picks = [];

            foreach ((array) ($match['picks'] ?? []) as $pick) {
                $ref = self::ref($pick['candidate'] ?? '');
                $verdict = strtolower((string) ($pick['same_work'] ?? ''));

                if (! isset($line['refs'][$ref]) || isset($picks[$ref]) || ! in_array($verdict, self::VERDICTS, true)) {
                    continue;
                }

                $picks[$ref] = ['ref' => $ref, 'same_work' => $verdict, 'note' => $this->note($pick['note'] ?? null, $locale), 'note_locale' => $locale, 'rank' => count($picks) + 1];
            }

            if ($picks !== []) {
                $kept[$line['line_id']] = array_values($picks);
            }
        }

        return $kept;
    }

    /** A note that may be shown, or null: one line of plain words that passed Guard A, cut to the configured length. */
    public function note(mixed $text, string $locale): ?string
    {
        if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8')) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '' || $this->guard->check($text, $locale) !== []) {
            return null;
        }

        $most = max(40, (int) config('modules.rate_helper.rank.note_chars', 240));

        if (mb_strlen($text) <= $most) {
            return $text;
        }

        $cut = mb_substr($text, 0, $most - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space === false || $space < $most / 2 ? $cut : mb_substr($cut, 0, $space), ' ,;:.-').'…';
    }

    /** A ref as the app writes it: L3, C1. */
    private static function ref(mixed $ref): string
    {
        return is_string($ref) ? strtoupper(trim($ref)) : '';
    }
}
