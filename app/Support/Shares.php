<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * Each amount's share of their total as a percentage with two decimals, adding up to exactly 100.00.
 * Every share is rounded down to the hundredth, and the hundredths left over go one each to the
 * largest remainders, the earlier amount first on a tie. The field app does the same sum, so the
 * phone and the website always show the same figures.
 */
final class Shares
{
    /** Hundredths of a percent in a whole. */
    private const WHOLE = 10000;

    /**
     * @param  list<mixed>  $amounts  Money, none below zero.
     * @return list<?string> One share per amount, as "12.50". Null for every amount when the total is nothing.
     */
    public static function of(array $amounts): array
    {
        $paise = array_map(fn ($amount) => Dec::of($amount)->toScale(2, RoundingMode::HalfUp)->getUnscaledValue(), array_values($amounts));
        $total = array_reduce($paise, fn (BigInteger $sum, BigInteger $value) => $sum->plus($value), BigInteger::zero());

        if (! $total->isPositive()) {
            return array_fill(0, count($paise), null);
        }

        $units = [];
        $remainders = [];

        foreach ($paise as $index => $value) {
            [$quotient, $remainder] = $value->multipliedBy(self::WHOLE)->quotientAndRemainder($total);
            $units[$index] = $quotient->toInt();
            $remainders[$index] = $remainder;
        }

        $order = array_keys($remainders);
        usort($order, fn (int $a, int $b) => $remainders[$b]->compareTo($remainders[$a]) ?: $a <=> $b);

        foreach (array_slice($order, 0, self::WHOLE - array_sum($units)) as $index) {
            $units[$index]++;
        }

        return array_map(fn (int $unit) => (string) BigDecimal::ofUnscaledValue($unit, 2), $units);
    }
}
