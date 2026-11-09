<?php

namespace App\Support;

/** Display helper for decimal strings: "26.500" becomes "26.5" and "8.000" becomes "8". Whole numbers are left alone. */
final class Figure
{
    public static function trim(mixed $value): string
    {
        $text = (string) $value;

        if (! str_contains($text, '.')) {
            return $text === '' ? '0' : $text;
        }

        return rtrim(rtrim($text, '0'), '.') ?: '0';
    }
}
