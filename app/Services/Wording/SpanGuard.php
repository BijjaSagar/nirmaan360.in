<?php

namespace App\Services\Wording;

/**
 * Guard C. After a slot is put together, every numeral in it must lie inside a stretch the code
 * filled from a fact. A numeral anywhere else did not come from a record.
 */
final class SpanGuard
{
    /**
     * @param  list<Span>  $spans
     * @return list<array{found: string, offset: int}> Runs of numerals with at least one character outside every span.
     */
    public static function outside(string $text, array $spans): array
    {
        if (! mb_check_encoding($text, 'UTF-8') || preg_match_all('/\p{N}+/u', $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [['found' => '', 'offset' => 0]];
        }

        $outside = [];

        foreach ($matches[0] as [$found, $byte]) {
            $start = mb_strlen(substr($text, 0, $byte));
            $end = $start + mb_strlen($found);

            foreach ($spans as $span) {
                if ($start >= $span->offset && $end <= $span->offset + $span->length) {
                    continue 2;
                }
            }

            $outside[] = ['found' => $found, 'offset' => $start];
        }

        return $outside;
    }

    /**
     * @param  list<Span>  $spans
     *
     * @throws GuardViolation
     */
    public static function assertNone(string $slotId, string $text, array $spans): void
    {
        $outside = self::outside($text, $spans);

        if ($outside !== []) {
            throw new GuardViolation(Violation::DIGIT, $slotId, $outside[0]['found']);
        }
    }
}
