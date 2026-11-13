<?php

namespace App\Services\Imports;

use Brick\Math\BigDecimal;

/**
 * How the cells of a contractor's sheet are read: numbers written the Indian way or in Devanagari
 * digits, units in their many spellings, and the marks that say a line is priced by rate only.
 *
 * Nothing here guesses a figure. A cell that is not plainly a number reads as no number.
 */
final class CellValues
{
    /** Devanagari digits and the ASCII digits they stand for. */
    public const DIGITS = ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9'];

    /**
     * Units and the spellings that mean them, compared after unit() has lower-cased the text and taken out
     * spaces, dots and slashes. A unit not listed here is kept as it was typed.
     */
    private const UNITS = [
        'cum' => ['cum', 'cumt', 'cumtr', 'cumetre', 'cumeter', 'cubm', 'cubicmetre', 'cubicmeter', 'cubicmtr', 'm3', 'm³', 'cmt', 'घमी', 'घनमी', 'घनमीटर', 'घमि'],
        'sqm' => ['sqm', 'sqmt', 'sqmtr', 'sqmetre', 'sqmeter', 'squaremetre', 'squaremeter', 'm2', 'm²', 'चौमी', 'चौरसमीटर', 'वर्गमीटर', 'वमी', 'चौमि'],
        'sqft' => ['sqft', 'sft', 'sqfeet', 'squarefeet', 'squarefoot', 'चौफुट', 'चौफू', 'वर्गफुट', 'चौफूट'],
        'cft' => ['cft', 'cuft', 'cubicfeet', 'घनफुट'],
        'rmt' => ['rmt', 'rm', 'rmtr', 'runningmetre', 'runningmeter', 'runningmtr', 'lm', 'rnm', 'रनिंगमीटर', 'रनिंगमी', 'रमी', 'रनिंगमिटर'],
        'rft' => ['rft', 'runningfeet', 'runningfoot', 'रनिंगफुट'],
        'm' => ['m', 'mtr', 'mtrs', 'metre', 'meter', 'metres', 'meters', 'मीटर', 'मी'],
        'nos' => ['nos', 'no', 'number', 'numbers', 'each', 'ea', 'pcs', 'pc', 'piece', 'pieces', 'नग', 'नं', 'संख्या'],
        'kg' => ['kg', 'kgs', 'kilogram', 'kilograms', 'किलो', 'किग्रा', 'किलोग्राम'],
        'MT' => ['mt', 'ton', 'tons', 'tonne', 'tonnes', 'metricton', 'टन', 'मेटन'],
        'qtl' => ['qtl', 'quintal', 'quintals', 'क्विंटल'],
        'brass' => ['brass', 'ब्रास'],
        'LS' => ['ls', 'lumpsum', 'lumsum', 'lump', 'job', 'lot', 'लमसम', 'एकमुश्त', 'एकरकमी'],
        'ltr' => ['ltr', 'ltrs', 'litre', 'liter', 'litres', 'liters', 'लिटर', 'लीटर'],
        'day' => ['day', 'days', 'perday', 'दिवस', 'दिन', 'रोज'],
        'month' => ['month', 'months', 'महिना', 'महीना'],
        'hr' => ['hr', 'hrs', 'hour', 'hours', 'तास', 'घंटा'],
        'bag' => ['bag', 'bags', 'गोणी', 'बोरी'],
        'set' => ['set', 'sets'],
        'pair' => ['pair', 'pairs', 'जोडी', 'जोड़ी'],
        'point' => ['point', 'points', 'pt', 'pts', 'पॉइंट'],
        'sqcm' => ['sqcm', 'cm2'],
        'mm' => ['mm'],
        'cm' => ['cm'],
        '%' => ['%', 'percent', 'percentage', 'pct', 'टक्के', 'प्रतिशत'],
    ];

    /** What a quantity cell says when a line is priced by rate only: quote rate only, rate only, R.O. */
    private const RATE_ONLY = ['qro', 'rateonly', 'ro', 'qrateonly', 'quoterateonly', 'quoterate', 'rateonlyitem', 'ratesonly', 'फक्तदर', 'केवलदर', 'सिर्फदर'];

    /** @var array<string, string>|null Spelling => unit, built from UNITS on first use. */
    private static ?array $lookup = null;

    /** Devanagari digits written as ASCII ones. */
    public static function asciiDigits(string $text): string
    {
        return strtr($text, self::DIGITS);
    }

    /**
     * An exact decimal string, or null when the cell holds no plain number.
     *
     * Takes 1,23,456.50 and 123,456.50, Devanagari digits, a rupee sign, Rs. or INR before the figure,
     * "/-" after it, and a minus sign or brackets for a negative figure.
     */
    public static function number(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? self::trimZeros(number_format($value, 6, '.', '')) : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $text = trim(self::asciiDigits($value));

        if ($text === '') {
            return null;
        }

        $text = (string) preg_replace('/^(?:₹|rs\.?|inr|रु\.?|रुपये)\s*/iu', '', $text);
        $text = (string) preg_replace('/\s*(?:\/-|\/=|-\/-)$/u', '', $text);
        $negative = false;

        if (preg_match('/^\((.*)\)$/u', $text, $match)) {
            $negative = true;
            $text = $match[1];
        }

        $text = str_replace([',', ' ', "\u{00A0}", '₹'], '', $text);

        if (str_starts_with($text, '-') || str_starts_with($text, '−')) {
            $negative = true;
            $text = (string) preg_replace('/^[-−]/u', '', $text);
        }

        if (preg_match('/^(\d+(\.\d*)?|\.\d+)$/', $text) !== 1 || strlen($text) > 30) {
            return null;
        }

        $number = BigDecimal::of(str_starts_with($text, '.') ? '0'.$text : rtrim($text, '.'));
        $text = self::trimZeros((string) $number);

        return $negative && ! $number->isZero() ? '-'.$text : $text;
    }

    /** Whether the cell holds a number. */
    public static function isNumber(mixed $value): bool
    {
        return self::number($value) !== null;
    }

    /** Whether a quantity or rate cell marks the line as priced by rate only, such as QRO or "Rate only". */
    public static function isRateOnly(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $key = (string) preg_replace('/[^\p{L}\p{M}]/u', '', mb_strtolower($value));

        return $key !== '' && in_array($key, self::RATE_ONLY, true);
    }

    /**
     * The unit in its usual short form: cum, sqm, sqft, rmt, nos, kg, MT, LS and so on. "Rs/cu.m",
     * "per sqm" and "Rs. per Cum" become the unit alone. Null when the cell is empty. A unit not known
     * here is returned as it was typed, without the "Rs/" in front of it.
     */
    public static function unit(mixed $value): ?string
    {
        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
        $text = trim((string) preg_replace('/^(?:(?:rs|inr|₹|रु)\.?\s*(?:\/|per|प्रति)?\s*|per\s+|प्रति\s+|\/\s*)/iu', '', $text));

        if ($text === '') {
            return null;
        }

        return self::knownUnit($text) ?? mb_substr($text, 0, 16);
    }

    /** The unit a text names, or null when it names none of the usual ones. */
    public static function knownUnit(string $text): ?string
    {
        $key = (string) preg_replace('/[\s.\/\-_()]+/u', '', mb_strtolower(self::asciiDigits(trim($text))));

        if ($key === '') {
            return null;
        }

        if (self::$lookup === null) {
            self::$lookup = [];

            foreach (self::UNITS as $unit => $spellings) {
                foreach ($spellings as $spelling) {
                    self::$lookup[$spelling] = $unit;
                }
            }
        }

        return self::$lookup[$key] ?? null;
    }

    /** Text as it reads on the sheet: a whole number without .0, a decimal without float noise, spaces folded. */
    public static function text(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return is_float($value) && ! is_finite($value) ? '' : self::trimZeros(number_format((float) $value, 6, '.', ''));
        }

        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    private static function trimZeros(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }
}
