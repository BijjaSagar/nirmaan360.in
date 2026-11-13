<?php

namespace App\Services\RateHelper;

/**
 * How alike two work descriptions are, by the words they share. Plain code: the same two texts always
 * give the same score, and nothing is learned or guessed.
 */
final class TextMatch
{
    /** Words that say nothing about what the work is. */
    private const NOISE = [
        'a', 'an', 'the', 'and', 'or', 'of', 'in', 'on', 'at', 'to', 'for', 'with', 'by', 'as', 'per', 'is', 'are', 'be',
        'etc', 'all', 'any', 'from', 'upto', 'up', 'including', 'include', 'incl', 'excluding', 'excl', 'complete',
        'providing', 'provide', 'laying', 'lay', 'supplying', 'supply', 'fixing', 'fix', 'directed', 'engineer',
        'charge', 'required', 'approved', 'necessary', 'item', 'work', 'same', 'also', 'not', 'its', 'this', 'that',
    ];

    /** @return list<string> The distinct words of a description that carry meaning, in lower case. */
    public static function words(string $text): array
    {
        $text = mb_strtolower(self::plainDigits($text));
        $words = [];

        foreach (preg_split('/[^\p{L}\p{M}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            // "slabs" and "slab" are the same word here.
            if (mb_strlen($word) > 3 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss')) {
                $word = substr($word, 0, -1);
            }

            if (mb_strlen($word) >= 2 && ! in_array($word, self::NOISE, true)) {
                $words[$word] = true;
            }
        }

        return array_map('strval', array_keys($words));
    }

    /**
     * 0 to 100: twice the words in common over the words of both, so a short library name inside a
     * long tender description still scores.
     *
     * @param  int  $shared  Words in common.
     * @param  int  $one  Distinct words on one side.
     * @param  int  $other  Distinct words on the other.
     */
    public static function score(int $shared, int $one, int $other): int
    {
        return $one + $other === 0 ? 0 : intdiv(200 * $shared, $one + $other);
    }

    /** Devanagari digits as the plain ones, so २५ and 25 are the same figure. */
    public static function plainDigits(string $text): string
    {
        return strtr($text, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
    }
}
