<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Exact decimal arithmetic for money and quantities.
 * Money and quantities must never pass through PHP floats in business logic.
 */
final class Dec
{
    public static function of(mixed $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }

        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }

        return BigDecimal::of(is_float($value) ? (string) $value : $value);
    }

    /** Round to quantity precision: NUMERIC(14,3). */
    public static function qty(mixed $value): string
    {
        return (string) self::of($value)->toScale(3, RoundingMode::HalfUp);
    }

    /** Round to money precision: NUMERIC(15,2). */
    public static function money(mixed $value): string
    {
        return (string) self::of($value)->toScale(2, RoundingMode::HalfUp);
    }

    /** Percentage of a base, rounded to money precision. */
    public static function percentOf(mixed $base, mixed $percent): string
    {
        return self::money(
            self::of($base)->multipliedBy(self::of($percent))->dividedBy(100, 6, RoundingMode::HalfUp)
        );
    }

    /** Group digits the Indian way: 12,34,567.00 */
    public static function indian(mixed $value, int $scale = 2): string
    {
        $fixed = (string) self::of($value)->toScale($scale, RoundingMode::HalfUp);
        $negative = str_starts_with($fixed, '-');
        $fixed = ltrim($fixed, '-');
        [$whole, $fraction] = array_pad(explode('.', $fixed, 2), 2, null);

        if (strlen($whole) > 3) {
            $tail = substr($whole, -3);
            $head = substr($whole, 0, -3);
            $whole = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $head).','.$tail;
        }

        return ($negative ? '-' : '').$whole.($fraction !== null ? '.'.$fraction : '');
    }
}
