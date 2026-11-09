<?php

namespace App\Services\ContractorControl;

use App\Support\Dec;

/** How percentages and differences are written on these screens. */
final class Figures
{
    /** 90.00 becomes 90, 70.50 becomes 70.5. */
    public static function pct(mixed $value): string
    {
        $text = Dec::money($value);

        return rtrim(rtrim($text, '0'), '.');
    }

    /** +10, −10 or 0, with a true minus sign. */
    public static function signedPct(mixed $value): string
    {
        $number = Dec::of($value);
        $sign = $number->isNegative() ? '−' : ($number->isZero() ? '' : '+');

        return $sign.ltrim(self::pct($value), '-');
    }

    /** +₹10,000.00, −₹10,000.00 or ₹0.00. */
    public static function signedInr(mixed $value): string
    {
        $number = Dec::of($value);
        $sign = $number->isNegative() ? '−' : ($number->isZero() ? '' : '+');

        return $sign.'₹'.Dec::indian($number->abs());
    }
}
