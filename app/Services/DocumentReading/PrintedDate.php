<?php

namespace App\Services\DocumentReading;

use Normalizer;

/**
 * Reads one printed date, day first, or refuses.
 *
 * Taken: 12-05-2026, 12/05/2026 and 12.05.2026, the same with a two-digit year (read as 20yy), and
 * a day, a month name and a year ("12 May 2026", "१२ मे २०२६", "12-May-26"). Month names in English,
 * Marathi and Hindi come from config('modules.document_reading.month_words'). The day always comes
 * first: 05/06/2026 is 5 June, and 05/13/2026 is not a date. Anything else is not parsed.
 */
final class PrintedDate
{
    private const FIRST_YEAR = 1900;

    private const LAST_YEAR = 2099;

    private const SPACE = '[\s\p{Z}]';

    /** @return ?string Y-m-d, or null when the text is not one date written in a form above. */
    public static function parse(string $raw): ?string
    {
        $digits = PrintedDigits::plain($raw);

        if ($digits === null || $digits['script'] === PrintedDigits::MIXED || $digits['made_digits']) {
            return null;
        }

        $text = (string) preg_replace('/^'.self::SPACE.'+|'.self::SPACE.'+$/Du', '', $digits['text']);

        // The same separator both times: "12-05/2026" is not taken.
        if (preg_match('/^(\d{1,2})'.self::SPACE.'*([-\/.])'.self::SPACE.'*(\d{1,2})'.self::SPACE.'*\2'.self::SPACE.'*(\d{4}|\d{2})$/Du', $text, $found) === 1) {
            return self::date($found[1], (int) $found[3], $found[4]);
        }

        if (preg_match('/^(\d{1,2})[\s\p{Z}.,\-\/]*([\p{L}\p{M}]+)[\s\p{Z}.,\-\/]*(\d{4}|\d{2})$/Du', $text, $found) === 1) {
            $month = self::months()[self::word($found[2])] ?? null;

            return $month === null ? null : self::date($found[1], $month, $found[3]);
        }

        return null;
    }

    private static function date(string $day, int $month, string $year): ?string
    {
        $year = strlen($year) === 2 ? 2000 + (int) $year : (int) $year;

        if ($year < self::FIRST_YEAR || $year > self::LAST_YEAR || ! checkdate($month, (int) $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, (int) $day);
    }

    /** @return array<string, int> Month name in the form word() gives, to month number. */
    private static function months(): array
    {
        $months = [];

        foreach ((array) config('modules.document_reading.month_words', []) as $month => $names) {
            foreach ((array) $names as $name) {
                $months[self::word((string) $name)] = (int) $month;
            }
        }

        return $months;
    }

    /** One spelling for comparison: compatibility form, lower case. A Hindi nukta letter then matches either way it was typed. */
    private static function word(string $text): string
    {
        return mb_strtolower((string) Normalizer::normalize($text, Normalizer::FORM_KC));
    }
}
