<?php

namespace App\Services\Wording;

use App\Support\Dec;
use InvalidArgumentException;

/**
 * How a fact is printed. Every figure comes out of Dec, so its digits are ASCII in every language;
 * only the month name of a date changes with the language.
 */
final class FactFormat
{
    public const LOCALES = ['en', 'hi', 'mr'];

    public static function display(string $kind, string $value, string $locale): string
    {
        self::locale($locale);

        return match ($kind) {
            Fact::MONEY => inr($value),
            Fact::QTY => fmt_qty($value),
            Fact::PERCENT => $value.'%',
            Fact::COUNT, Fact::DAYS => Dec::indian($value, 0),
            Fact::DATE => self::date($value, $locale),
            Fact::REFERENCE, Fact::TEXT => $value,
            default => throw new InvalidArgumentException("'{$kind}' is not a kind of fact."),
        };
    }

    /** The language a document is written in. Anything but English, Hindi or Marathi is a fault in the caller. */
    public static function locale(string $locale): string
    {
        if (! in_array($locale, self::LOCALES, true)) {
            throw new InvalidArgumentException("Documents are written in English, Hindi or Marathi, not '{$locale}'.");
        }

        return $locale;
    }

    /** 2026-11-05 becomes "5 November 2026", with the month in the document's language. */
    private static function date(string $value, string $locale): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            throw new InvalidArgumentException('A date fact must be written as year-month-day.');
        }

        $month = config("wording.months.{$locale}.".(int) $parts[2]);

        if (! is_string($month) || $month === '') {
            throw new WordingException("No month names are set for '{$locale}'.");
        }

        return (int) $parts[3].' '.$month.' '.$parts[1];
    }
}
