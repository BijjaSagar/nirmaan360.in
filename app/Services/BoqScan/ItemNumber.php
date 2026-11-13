<?php

namespace App\Services\BoqScan;

use App\Services\DocumentReading\PrintedDigits;

/**
 * A printed item number as the BOQ writes its code. Pure code, no database.
 *
 * "७.", " 7 )" and "7" are code 7. A single letter, or a roman numeral in brackets, printed under a
 * numbered line is a part of that line: "a" under 3 is "3a", the form the Excel import gives
 * lettered rows. Nothing else is changed: a code is never invented, and a blank stays blank.
 */
final class ItemNumber
{
    /** The printed number with every digit as 0 to 9, without spaces, and without a trailing "." or ")". */
    public static function plain(string $raw): string
    {
        $text = PrintedDigits::plain($raw)['text'] ?? '';
        $text = (string) preg_replace('/[\s\p{Z}]+/u', '', $text);

        return (string) preg_replace('/[.)]+$/u', '', $text);
    }

    /**
     * The letters of a lettered row ("a", "(b)", "c.", "(ii)", "iv)"), in lower case, or null when the
     * number is not one.
     */
    public static function letters(string $raw): ?string
    {
        $text = (string) preg_replace('/[\s\p{Z}]+/u', '', PrintedDigits::plain($raw)['text'] ?? '');

        if (preg_match('/^\(?(\p{L}\p{M}*)[.)]*$/u', $text, $match) === 1) {
            return mb_strtolower($match[1]);
        }

        if (preg_match('/^\(([ivxlcdm]+)\)\.?$/iu', $text, $match) === 1 || preg_match('/^([ivxlcdm]+)\)\.?$/iu', $text, $match) === 1) {
            return mb_strtolower($match[1]);
        }

        return null;
    }

    /**
     * The code for a row.
     *
     * @param  ?string  $parent  The code of the numbered line above, when there is one.
     * @return string Empty when nothing is printed. It may be longer than a BOQ code may be; the caller checks.
     */
    public static function code(string $raw, ?string $parent): string
    {
        $letters = self::letters($raw);

        if ($letters !== null) {
            return filled($parent) ? $parent.$letters : $letters;
        }

        return self::plain($raw);
    }

    /** A code a lettered row may hang from: one that starts with a digit. */
    public static function isNumbered(string $code): bool
    {
        return preg_match('/^\d/', $code) === 1;
    }

    /** For pairing the two readings of a page: the same number in either reading compares equal. */
    public static function key(string $raw): string
    {
        return mb_strtolower(self::plain($raw));
    }
}
