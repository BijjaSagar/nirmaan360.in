<?php

namespace App\Services\TenderReading;

use App\Services\DocumentReading\PrintedDate;
use App\Services\DocumentReading\PrintedDigits;
use App\Services\DocumentReading\PrintedNumber;
use Carbon\CarbonImmutable;
use Normalizer;

/**
 * What a printed tender value means, worked out by code from the text exactly as the reader copied
 * it: an amount, a percentage, a date, a date and time, a period, a link. The reader never converts
 * anything; Devanagari digits are read here.
 *
 * A value that is not one clear figure of its kind is left unread (null). "Rs. 5,00,000/- (Rupees
 * five lakh only)" is read; "5 lakh" and a line with two amounts in it are not, because the code
 * cannot tell which figure the field means. Such a value is still shown as printed.
 */
final class TenderValue
{
    /** Words that change what the number before them means. A figure followed by one is not read. */
    private const SCALE_WORDS = '(?:lakhs?|lacs?|crores?|cr\b|thousands?|lakh|हजार|हज़ार|लाख|कोटी|करोड़|करोड|कोटि)';

    private const MONEY_MARK = '(?:₹|rs\.?|inr|रु\.?|रू\.?)';

    private const PERCENT_WORD = '(?:%|percent|per\s*cent|टक्के|टक्का|प्रतिशत)';

    /** The value in a canonical form, by kind, or null when it is not one clear value of that kind. */
    public static function normalise(string $kind, string $raw): ?string
    {
        $value = match ($kind) {
            TenderFields::AMOUNT => self::amount($raw),
            TenderFields::PERCENT => self::percent($raw),
            TenderFields::DATE => self::date($raw),
            TenderFields::DATETIME => self::dateTime($raw),
            TenderFields::PERIOD => self::period($raw),
            TenderFields::URL => self::url($raw),
            default => self::textKey($raw),
        };

        return $value === null || $value === '' ? null : mb_substr($value, 0, 255);
    }

    /**
     * What two values are compared by: the value worked out by code when there is one, otherwise the
     * printed text in one spelling (compatibility form, lower case, single spaces).
     */
    public static function compareKey(string $kind, string $raw, ?string $norm): string
    {
        return $norm ?? (self::textKey($raw) ?? '');
    }

    /** The printed text in one spelling: compatibility form, digits as 0 to 9, lower case, single spaces. */
    public static function textKey(string $raw): ?string
    {
        $digits = PrintedDigits::plain($raw);

        if ($digits === null) {
            return null;
        }

        $text = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $digits['text']));

        return $text === '' ? null : mb_strtolower($text);
    }

    public static function amount(string $raw): ?string
    {
        $whole = PrintedNumber::parse($raw, 'amount');

        if ($whole->ok) {
            return $whole->value;
        }

        $text = self::withoutBrackets($raw);

        if ($text === null || preg_match('/\d'.'[\d,.\s]*'.self::SCALE_WORDS.'/iu', $text) === 1) {
            return null;
        }

        $found = self::only('/'.self::MONEY_MARK.'?[\s\p{Z}]*\d[\d,.]*(?:[\s\p{Z}]*\/[\s\p{Z}]*[-=])?/iu', $text);

        if ($found === null) {
            return null;
        }

        $parsed = PrintedNumber::parse(rtrim($found, '.'), 'amount');

        return $parsed->ok ? $parsed->value : null;
    }

    public static function percent(string $raw): ?string
    {
        $whole = PrintedNumber::parse($raw, 'percent');

        if ($whole->ok) {
            return $whole->value;
        }

        $text = self::withoutBrackets($raw);
        $found = $text === null ? null : self::only('/\d[\d.,]*[\s\p{Z}]*'.self::PERCENT_WORD.'/iu', $text);

        if ($found === null) {
            return null;
        }

        $number = (string) preg_replace('/[\s\p{Z}]*'.self::PERCENT_WORD.'$/iu', '', $found);
        $parsed = PrintedNumber::parse($number, 'percent');

        return $parsed->ok ? $parsed->value : null;
    }

    public static function date(string $raw): ?string
    {
        $whole = PrintedDate::parse($raw);

        if ($whole !== null) {
            return $whole;
        }

        $found = self::dateIn($raw);

        return $found === null ? null : PrintedDate::parse($found);
    }

    /** "Y-m-d H:i" when a date and a time are printed, "Y-m-d" when only the date is clear. */
    public static function dateTime(string $raw): ?string
    {
        $digits = PrintedDigits::plain($raw);

        if ($digits === null || $digits['script'] === PrintedDigits::MIXED) {
            return null;
        }

        $text = $digits['text'];
        $datePart = PrintedDate::parse($text) !== null ? trim($text) : self::dateIn($text);
        $date = $datePart === null ? null : PrintedDate::parse($datePart);

        if ($date === null) {
            return null;
        }

        $rest = str_replace($datePart, ' ', $text);
        $times = preg_match_all('/(?<![\d.:])(\d{1,2})[:.](\d{2})(?![\d.:])[\s\p{Z}]*(a\.?\s?m\.?|p\.?\s?m\.?|hrs?\.?|hours)?/iu', $rest, $matches, PREG_SET_ORDER);

        if ($times !== 1) {
            return $date;
        }

        [$all, $hour, $minute] = $matches[0];
        $hour = (int) $hour;
        $minute = (int) $minute;
        $half = strtolower(str_replace(['.', ' '], '', $matches[0][3] ?? ''));

        if ($minute > 59 || $hour > 23 || (in_array($half, ['am', 'pm'], true) && ($hour < 1 || $hour > 12))) {
            return $date;
        }

        if ($half === 'pm' && $hour < 12) {
            $hour += 12;
        } elseif ($half === 'am' && $hour === 12) {
            $hour = 0;
        }

        return sprintf('%s %02d:%02d', $date, $hour, $minute);
    }

    /** The number and the word after it, as printed: "18 months", "९० दिवस" becomes "90 दिवस". */
    public static function period(string $raw): ?string
    {
        $text = self::withoutBrackets($raw);

        if ($text === null || preg_match('/(\d+(?:\.\d+)?)[\s\p{Z}]*([\p{L}\p{M}]+)/u', $text, $found) !== 1) {
            return null;
        }

        return $found[1].' '.mb_strtolower($found[2]);
    }

    public static function url(string $raw): ?string
    {
        $url = trim($raw);

        return preg_match('/^https?:\/\/\S+$/iD', $url) === 1 ? $url : null;
    }

    /** How the worked-out value is shown beside the printed one. Null when there is nothing to add. */
    public static function display(string $kind, ?string $norm): ?string
    {
        if ($norm === null) {
            return null;
        }

        return match ($kind) {
            TenderFields::AMOUNT => inr($norm),
            TenderFields::PERCENT => (str_contains($norm, '.') ? rtrim(rtrim($norm, '0'), '.') : $norm).'%',
            TenderFields::DATE => CarbonImmutable::createFromFormat('!Y-m-d', $norm)?->format('d M Y'),
            TenderFields::DATETIME => strlen($norm) > 10
                ? CarbonImmutable::createFromFormat('Y-m-d H:i', $norm)?->format('d M Y, h:i A')
                : CarbonImmutable::createFromFormat('!Y-m-d', $norm)?->format('d M Y'),
            default => null,
        };
    }

    /** The day a date or date-and-time value falls on, for putting dates in order. */
    public static function day(string $kind, ?string $norm): ?string
    {
        return $norm !== null && in_array($kind, [TenderFields::DATE, TenderFields::DATETIME], true) ? substr($norm, 0, 10) : null;
    }

    /**
     * The one date printed in a longer text, such as "up to 15.12.2026" or "on 3 March 2027 at the
     * office". Null when there is none or more than one.
     */
    private static function dateIn(string $raw): ?string
    {
        $digits = PrintedDigits::plain($raw);

        if ($digits === null || $digits['script'] === PrintedDigits::MIXED) {
            return null;
        }

        $text = $digits['text'];
        $numeric = '(?<!\d)\d{1,2}[\s\p{Z}]*([-\/.])[\s\p{Z}]*\d{1,2}[\s\p{Z}]*\1[\s\p{Z}]*(?:\d{4}|\d{2})(?!\d)';
        $named = '(?<!\d)\d{1,2}[\s\p{Z}.,\-\/]*[\p{L}\p{M}]+[\s\p{Z}.,\-\/]*(?:\d{4}|\d{2})(?!\d)';
        $found = [];

        foreach ([$numeric, $named] as $pattern) {
            if (preg_match_all('/'.$pattern.'/u', $text, $matches) > 0) {
                foreach ($matches[0] as $candidate) {
                    if (PrintedDate::parse($candidate) !== null) {
                        $found[PrintedDate::parse($candidate)] = $candidate;
                    }
                }
            }
        }

        return count($found) === 1 ? array_values($found)[0] : null;
    }

    /** The one match of a pattern, or null when there is none or more than one. */
    private static function only(string $pattern, string $text): ?string
    {
        $count = preg_match_all($pattern, $text, $matches);

        return $count === 1 ? trim($matches[0][0]) : null;
    }

    /** The text in compatibility form with plain digits, without anything in brackets, such as an amount in words. */
    private static function withoutBrackets(string $raw): ?string
    {
        $digits = PrintedDigits::plain($raw);

        if ($digits === null || $digits['script'] === PrintedDigits::MIXED || $digits['made_digits']) {
            return null;
        }

        return (string) preg_replace('/\([^()]*\)|\[[^\[\]]*\]/u', ' ', Normalizer::normalize($digits['text'], Normalizer::FORM_KC) ?: $digits['text']);
    }
}
