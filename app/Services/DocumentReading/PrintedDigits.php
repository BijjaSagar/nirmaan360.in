<?php

namespace App\Services\DocumentReading;

use IntlChar;
use Normalizer;

/**
 * Printed digits of any script as plain 0 to 9, with a note of the script they were printed in.
 * Shared by the number and date parsers so both treat Devanagari digits the same way.
 */
final class PrintedDigits
{
    public const ASCII = 'ascii';

    public const DEVANAGARI = 'devanagari';

    public const OTHER = 'other';

    public const MIXED = 'mixed';

    /**
     * The text in compatibility form (NFKC) with every decimal digit as 0 to 9, or null when the
     * text is not valid UTF-8.
     *
     * "script" is that of the digits: ascii (also when there is no digit at all), devanagari, other
     * for one other script, mixed when two scripts share the cell. "made_digits" is true when the
     * compatibility form produced a digit from a character that is not a decimal digit, such as a
     * raised footnote mark or a one-character fraction. Such a cell does not say what it seems to.
     *
     * @return array{text: string, script: string, made_digits: bool}|null
     */
    public static function plain(string $raw): ?array
    {
        if (! mb_check_encoding($raw, 'UTF-8')) {
            return null;
        }

        $text = Normalizer::normalize($raw, Normalizer::FORM_KC);

        if (! is_string($text)) {
            return null;
        }

        $scripts = [];

        $plain = (string) preg_replace_callback('/\p{Nd}/u', function (array $match) use (&$scripts): string {
            $scripts[self::scriptOf($match[0])] = true;

            return (string) IntlChar::charDigitValue($match[0]);
        }, $text);

        return [
            'text' => $plain,
            'script' => match (true) {
                count($scripts) > 1 => self::MIXED,
                isset($scripts[self::DEVANAGARI]) => self::DEVANAGARI,
                $scripts === [] || isset($scripts[self::ASCII]) => self::ASCII,
                default => self::OTHER,
            },
            'made_digits' => preg_match_all('/\p{Nd}/u', $text) > preg_match_all('/\p{Nd}/u', $raw),
        ];
    }

    private static function scriptOf(string $digit): string
    {
        if ($digit >= '0' && $digit <= '9') {
            return self::ASCII;
        }

        $script = (string) IntlChar::getPropertyValueName(
            IntlChar::PROPERTY_SCRIPT,
            IntlChar::getIntPropertyValue($digit, IntlChar::PROPERTY_SCRIPT),
            IntlChar::LONG_PROPERTY_NAME,
        );

        // Any other script keeps its own name, so two of them in one cell still count as mixed.
        return $script === 'Devanagari' ? self::DEVANAGARI : 'other:'.$script;
    }
}
