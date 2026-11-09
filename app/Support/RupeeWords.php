<?php

namespace App\Support;

use Brick\Math\RoundingMode;

/** An amount in words the Indian way, for payslips: "Rupees Four Thousand Five Hundred Only". */
final class RupeeWords
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function of(mixed $amount): string
    {
        $fixed = (string) Dec::of($amount)->toScale(2, RoundingMode::HalfUp);
        [$rupees, $paise] = array_map('intval', explode('.', ltrim($fixed, '-')));

        if ($rupees === 0 && $paise === 0) {
            return 'Rupees Zero Only';
        }

        $words = $rupees > 0 ? 'Rupees '.self::number($rupees) : '';

        if ($paise > 0) {
            $words .= ($rupees > 0 ? ' and ' : '').self::number($paise).' Paise';
        }

        return trim($words).' Only';
    }

    private static function number(int $n): string
    {
        $parts = [];

        foreach ([10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand'] as $unit => $name) {
            if ($n >= $unit) {
                $parts[] = self::number(intdiv($n, $unit)).' '.$name;
                $n %= $unit;
            }
        }

        if ($n >= 100) {
            $parts[] = self::ONES[intdiv($n, 100)].' Hundred';
            $n %= 100;
        }

        if ($n > 0) {
            $parts[] = $n < 20 ? self::ONES[$n] : trim(self::TENS[intdiv($n, 10)].' '.self::ONES[$n % 10]);
        }

        return implode(' ', $parts);
    }
}
