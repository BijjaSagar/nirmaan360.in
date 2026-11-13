<?php

namespace App\Services\DocumentReading;

use Normalizer;

/**
 * A printed unit as the app spells it.
 *
 * "cu.m.", "Cum", "M3", "m³" and "घ.मी." are one unit. The spellings are a list in
 * config('modules.document_reading.unit_words'), not a rule, so a unit that is not on the list is
 * passed through as printed and marked unknown rather than bent into the nearest known one. The
 * screen always shows the printed text beside the result.
 */
final class UnitText
{
    public const MAX_LENGTH = 16;

    /** @return array{unit: string, known: bool} A blank unit stays blank; it is never turned into a default. */
    public static function normalise(string $raw): array
    {
        $text = mb_check_encoding($raw, 'UTF-8') ? Normalizer::normalize($raw, Normalizer::FORM_KC) : false;
        $printed = is_string($text) ? trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $text)) : '';

        if ($printed === '') {
            return ['unit' => '', 'known' => false];
        }

        $words = self::words();

        // A rate column often prints the unit as "Rs/cu.m", "per cum" or "/cum". The unit is the part after it.
        $after = (string) preg_replace('/^(?:(?:₹|rs\.?|inr|रु\.?|रू\.?)\s*\/|\/|per\b[\s.]*|प्रति(?![\p{L}\p{M}]))\s*/iu', '', $printed);

        $unit = $words[self::key($printed)] ?? $words[self::key($after)] ?? null;

        return $unit !== null
            ? ['unit' => $unit, 'known' => true]
            : ['unit' => mb_substr($printed, 0, self::MAX_LENGTH), 'known' => false];
    }

    /** @return array<string, string> Spelling in the form key() gives, to the unit as the app writes it. */
    public static function words(): array
    {
        $words = [];

        foreach ((array) config('buildos.units', []) as $unit) {
            $words[self::key((string) $unit)] = (string) $unit;
        }

        foreach ((array) config('modules.document_reading.unit_words', []) as $unit => $spellings) {
            $words[self::key((string) $unit)] ??= (string) $unit;

            foreach ((array) $spellings as $spelling) {
                $words[self::key((string) $spelling)] ??= (string) $unit;
            }
        }

        return $words;
    }

    /** Lower case, without dots, spaces or the Devanagari abbreviation sign: "Cu. M." and "cum" compare equal. */
    public static function key(string $text): string
    {
        $text = Normalizer::normalize($text, Normalizer::FORM_KC);

        return (string) preg_replace('/[.\x{0970}\s\p{Z}]+/u', '', mb_strtolower(is_string($text) ? $text : ''));
    }
}
