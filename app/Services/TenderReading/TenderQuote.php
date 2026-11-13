<?php

namespace App\Services\TenderReading;

use App\Services\DocumentReading\ReaderRequest;
use Normalizer;

/**
 * Holds the line the reader quoted against the text the server itself took out of the page.
 *
 * Both sides are put in one spelling first: compatibility form, the look-alike brackets the page
 * text was sent with (ReaderRequest::safe), and single spaces. The quote must then occur in that
 * page's text, and is widened to the whole extracted line or lines it stands in. That line must be
 * long enough and hold a real word besides the value, so a bare figure that happens to occur
 * somewhere on the page does not count as found.
 *
 * Passing says only that the quote is in the PDF's text layer. It does not say the text layer is what
 * the page shows, nor that the figure belongs to the field the reader put it under: the page drawn
 * beside the fact and the person who confirms it are the check for that.
 */
final class TenderQuote
{
    public const MATCHED = 'matched';

    public const NOT_IN_TEXT = 'not_in_text';

    public const ONLY_THE_VALUE = 'only_the_value';

    public const LINE_TOO_SHORT = 'line_too_short';

    /**
     * @return array{found: bool, reason: string, line: ?string, value_in_quote: bool}
     */
    public static function check(string $quote, string $value, string $pageText): array
    {
        $quoted = self::spelling($quote);
        $printed = self::spelling($value);
        $valueInQuote = $printed !== '' && str_contains(mb_strtolower($quoted), mb_strtolower($printed));
        $result = fn (bool $found, string $reason, ?string $line = null) => ['found' => $found, 'reason' => $reason, 'line' => $line, 'value_in_quote' => $valueInQuote];

        if ($quoted === '') {
            return $result(false, self::NOT_IN_TEXT);
        }

        $line = self::lineOf($quoted, $pageText);

        if ($line === null) {
            return $result(false, self::NOT_IN_TEXT);
        }

        // A quote that is the value and nothing else proves nothing about what the value is.
        if ($printed !== '' && mb_strtolower($quoted) === mb_strtolower($printed)) {
            return $result(false, self::ONLY_THE_VALUE, $line);
        }

        if (! self::lineHoldsWords($line, $printed)) {
            return $result(false, self::LINE_TOO_SHORT, $line);
        }

        return $result(true, self::MATCHED, $line);
    }

    /** The text in the one spelling both sides are compared in. */
    public static function spelling(string $text): string
    {
        $text = Normalizer::normalize($text, Normalizer::FORM_KC);

        return trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', ReaderRequest::safe(is_string($text) ? $text : '')));
    }

    /** The whole extracted line or lines the quote stands in, or null when the quote is not in the page's text. */
    private static function lineOf(string $quoted, string $pageText): ?string
    {
        $lines = [];
        $joined = '';

        foreach (preg_split('/\R/u', $pageText) ?: [] as $line) {
            $line = self::spelling($line);

            if ($line === '') {
                continue;
            }

            $start = $joined === '' ? 0 : strlen($joined) + 1;
            $joined = $joined === '' ? $line : $joined.' '.$line;
            $lines[] = [$start, $start + strlen($line), $line];
        }

        $at = strpos($joined, $quoted);

        if ($at === false) {
            return null;
        }

        $end = $at + strlen($quoted);
        $covering = array_filter($lines, fn (array $line) => $line[1] > $at && $line[0] < $end);

        return implode(' ', array_column($covering, 2));
    }

    /** At least the shortest length, and a word of enough letters that is not part of the value. */
    private static function lineHoldsWords(string $line, string $printed): bool
    {
        if (mb_strlen($line) < TenderFields::setting('line_min_chars', 20)) {
            return false;
        }

        $rest = $printed === '' ? $line : str_ireplace($printed, ' ', $line);
        $letters = max(1, TenderFields::setting('line_word_letters', 3));

        return preg_match('/[\p{L}\p{M}]{'.$letters.',}/u', $rest) === 1;
    }
}
