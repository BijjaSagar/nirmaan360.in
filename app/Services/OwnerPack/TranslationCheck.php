<?php

namespace App\Services\OwnerPack;

/**
 * Whether a translation of a quotation line's description may be kept for a person to read.
 *
 * Code cannot tell whether a translation says the same thing ("excluding" against "including"),
 * which is why a person accepts each line before it is printed. What code can hold the AI to is
 * what a home owner would pay by: every number of the description stands in the translation as
 * it was written and in the same order, and every grade (M and digits, Fe and digits) and ratio
 * (1:2:4) stands unchanged. A translation that fails any rule is dropped, never mended.
 */
final class TranslationCheck
{
    public const EMPTY = 'empty';

    public const ENCODING = 'encoding';

    public const CONTROL = 'control';

    public const TOKEN = 'token';

    public const LENGTH = 'length';

    public const SCRIPT = 'script';

    public const SAME = 'same';

    public const NUMBERS = 'numbers';

    public const GRADES = 'grades';

    /** The longest translation kept, in characters, whatever the description's length. */
    public const MOST_CHARS = 3000;

    /** The languages a description is translated into, each with the script its translation must use. */
    private const SCRIPTS = ['hi' => 'Devanagari', 'mr' => 'Devanagari'];

    /**
     * The rule a translation breaks, or null when it may be kept.
     *
     * @param  string  $source  The description as it stands on the line.
     * @param  string  $translation  The AI's text, as it came.
     * @param  string  $locale  The language it was asked for.
     */
    public static function fault(string $source, string $translation, string $locale): ?string
    {
        if (! mb_check_encoding($translation, 'UTF-8')) {
            return self::ENCODING;
        }

        $translation = self::tidy($translation);

        if ($translation === '' || preg_match('/\p{L}/u', $translation) !== 1) {
            return self::EMPTY;
        }

        // Line breaks and tabs are text. Every other control or format character is not, except the two
        // joiners that Marathi and Hindi spelling uses inside words.
        if (preg_match('/[\p{Cc}\p{Cf}]/u', str_replace(["\n", "\t", "\u{200C}", "\u{200D}"], '', $translation)) === 1) {
            return self::CONTROL;
        }

        if (str_contains($translation, '{{') || str_contains($translation, '}}')) {
            return self::TOKEN;
        }

        if (mb_strlen($translation) > min(self::MOST_CHARS, max(200, 3 * mb_strlen($source)))) {
            return self::LENGTH;
        }

        if (self::collapsed($translation) === self::collapsed($source)) {
            return self::SAME;
        }

        $script = self::SCRIPTS[$locale] ?? null;

        if ($script !== null && preg_match('/\p{'.$script.'}/u', $translation) !== 1) {
            return self::SCRIPT;
        }

        if (self::numbers($source) !== self::numbers($translation)) {
            return self::NUMBERS;
        }

        if (self::grades($source) !== self::grades($translation)) {
            return self::GRADES;
        }

        return null;
    }

    /**
     * Every number in a text, in order, exactly as written: a run of digits of any script, with a
     * decimal point or a comma only between digits. "1,200.50" is one number; "१२" is not "12".
     *
     * @return list<string>
     */
    public static function numbers(string $text): array
    {
        preg_match_all('/\p{N}+(?:[.,]\p{N}+)*/u', $text, $matches);

        return $matches[0];
    }

    /**
     * Every grade (M20, M 25, Fe500, Fe 500D) and every ratio (1:2:4, 1 : 6) in a text, in order,
     * exactly as written. A grade may run into a Devanagari ending ("M20च्या"), not into a Latin letter or digit.
     *
     * @return list<string>
     */
    public static function grades(string $text): array
    {
        preg_match_all('/(?<![A-Za-z0-9])(?:M|Fe)\s?[0-9]+[A-Z]{0,2}(?![A-Za-z0-9])|[0-9]+(?:\s?:\s?[0-9]+)+/u', $text, $matches);

        return $matches[0];
    }

    /** The translation as it is kept: one kind of line break and no space at the ends. */
    public static function tidy(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    private static function collapsed(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
