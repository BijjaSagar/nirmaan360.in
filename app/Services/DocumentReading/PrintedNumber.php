<?php

namespace App\Services\DocumentReading;

use App\Support\Dec;
use InvalidArgumentException;

/**
 * Reads one number exactly as it was printed, or refuses.
 *
 * The model copies cells as strings. This is the only place such a string becomes a figure, and it
 * never guesses: a cell that could be read two ways, or that carries anything besides one plain
 * number, is refused with a reason and a person types the value. Nothing here uses a float.
 *
 * Accepted: digits of one script (Devanagari included), a dot as the decimal point, and commas
 * placed the Indian way (1,00,000) or in threes (100,000). A rupee mark before or after and a
 * closing "/-" are set aside, and a closing "%" on a percentage.
 */
final class PrintedNumber
{
    public const EMPTY = 'empty';

    public const NOT_TEXT = 'not_text';

    public const MIXED_SCRIPT = 'mixed_script';

    public const UNUSUAL_DIGITS = 'unusual_digits';

    public const HAS_LETTERS = 'has_letters';

    public const NO_DIGITS = 'no_digits';

    public const NEGATIVE = 'negative';

    public const AMBIGUOUS_SEPARATOR = 'ambiguous_separator';

    public const TOO_MANY_DECIMALS = 'too_many_decimals';

    public const TOO_LARGE = 'too_large';

    /** Decimals a printed value may carry. The BOQ write rounds silently, so more than this is refused here. */
    public const DECIMALS = ['qty' => 3, 'rate' => 2, 'amount' => 2, 'percent' => 4, 'count' => 0];

    /** Largest value taken. Quantity, rate and amount match the Excel import; the other two only keep nonsense out. */
    public const LARGEST = [
        'qty' => '99999999999',
        'rate' => '9999999999999',
        'amount' => '9999999999999',
        'percent' => '999.9999',
        'count' => '999999999',
    ];

    private const SPACE = '[\s\p{Z}]';

    private const RUPEE_MARK = '(?:₹|rs\.?|inr|रु\.?|रू\.?)';

    private const DASH = '[-\x{2010}\x{2012}\x{2013}\x{2014}\x{2212}]';

    /** A plain number, Indian grouping, or groups of three. A grouped number does not start with a zero. */
    private const FORMS = [
        '/^\d+(\.\d+)?$/D',
        '/^[1-9]\d?(,\d{2})*,\d{3}(\.\d+)?$/D',
        '/^[1-9]\d{0,2}(,\d{3})+(\.\d+)?$/D',
    ];

    /** @param  string  $kind  qty, rate, amount, percent or count */
    public static function parse(string $raw, string $kind): PrintedNumberResult
    {
        if (! isset(self::DECIMALS[$kind])) {
            throw new InvalidArgumentException("Unknown kind of number: {$kind}.");
        }

        $digits = PrintedDigits::plain($raw);

        if ($digits === null) {
            return new PrintedNumberResult(false, null, self::NOT_TEXT, PrintedDigits::ASCII, false);
        }

        $text = self::bare($digits['text'], $kind);
        $refuse = fn (string $reason) => new PrintedNumberResult(false, null, $reason, $digits['script'], str_contains($digits['text'], ','));

        $reason = match (true) {
            trim($digits['text']) === '' => self::EMPTY,
            $digits['script'] === PrintedDigits::MIXED => self::MIXED_SCRIPT,
            $digits['made_digits'] => self::UNUSUAL_DIGITS,
            preg_match('/[\p{L}\p{M}]/u', $text) === 1 => self::HAS_LETTERS,
            preg_match('/\d/', $text) !== 1 => self::NO_DIGITS,
            preg_match('/^'.self::DASH.'|'.self::DASH.'$|^\(.*\)$/Du', $text) === 1 => self::NEGATIVE,
            ! self::hasForm($text) => self::AMBIGUOUS_SEPARATOR,
            default => null,
        };

        if ($reason !== null) {
            return $refuse($reason);
        }

        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '', $text), 2), 2, '');

        if (strlen($fraction) > self::DECIMALS[$kind]) {
            return $refuse(self::TOO_MANY_DECIMALS);
        }

        $whole = ltrim($whole, '0');
        $value = ($whole === '' ? '0' : $whole).($fraction === '' ? '' : '.'.$fraction);

        if (Dec::of($value)->isGreaterThan(Dec::of(self::LARGEST[$kind]))) {
            return $refuse(self::TOO_LARGE);
        }

        return new PrintedNumberResult(true, $value, null, $digits['script'], str_contains($digits['text'], ','));
    }

    /** The cell without the marks that often stand around a figure and do not change it. */
    private static function bare(string $text, string $kind): string
    {
        $text = trim((string) preg_replace('/^'.self::SPACE.'+|'.self::SPACE.'+$/Du', '', $text));
        $text = (string) preg_replace('/^'.self::RUPEE_MARK.'(?![\p{L}\p{M}])'.self::SPACE.'*/iu', '', $text);

        $slash = '/'.self::SPACE.'*\/'.self::SPACE.'*[-=]$/Du';
        $mark = '/'.self::SPACE.'*(?<![\p{L}\p{M}])'.self::RUPEE_MARK.'$/Diu';

        // "Rs 500/-", "500/- Rs" and "500 Rs/-" all occur: one closing mark and one "/-", in either order.
        $text = (string) preg_replace($mark, '', $text, 1, $marks);
        $text = (string) preg_replace($slash, '', $text, 1);

        if ($marks === 0) {
            $text = (string) preg_replace($mark, '', $text, 1);
        }

        if ($kind === 'percent') {
            $text = (string) preg_replace('/'.self::SPACE.'*%$/Du', '', $text);
        }

        return $text;
    }

    private static function hasForm(string $text): bool
    {
        foreach (self::FORMS as $form) {
            if (preg_match($form, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
