<?php

namespace App\Services\OwnerPack;

use App\Models\ClientPaymentStage;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/**
 * Every figure of a quotation, worked out from what was copied or typed: line amounts, the subtotal,
 * GST on the percentage typed for this quotation, the total, and the amount of each payment stage.
 * Pure arithmetic on decimal strings. Nothing here knows a tax rate or reads a record.
 */
final class QuotationMath
{
    /** Quantity times rate, to the paisa. The same sum as App\Models\BoqItem::amount(). */
    public static function lineAmount(mixed $qty, mixed $rate): string
    {
        return Dec::money(Dec::of($qty)->multipliedBy(Dec::of($rate)));
    }

    /**
     * @param  iterable<mixed>  $lineAmounts
     * @param  mixed  $gstPct  The percentage typed for this quotation, or null when it is quoted without GST.
     * @return array{subtotal: string, gst_amount: string, total: string}
     */
    public static function totals(iterable $lineAmounts, mixed $gstPct): array
    {
        $subtotal = BigDecimal::zero();

        foreach ($lineAmounts as $amount) {
            $subtotal = $subtotal->plus(Dec::of($amount));
        }

        $gst = $gstPct === null || $gstPct === '' ? '0.00' : Dec::percentOf($subtotal, $gstPct);

        return [
            'subtotal' => Dec::money($subtotal),
            'gst_amount' => $gst,
            'total' => Dec::money($subtotal->plus(Dec::of($gst))),
        ];
    }

    /**
     * The amount of each stage, in order.
     *
     * A percentage stage is that share of the total. When every stage is a percentage and they add up
     * to the whole, the last stage takes what is left, so the stages add up to the total to the paisa.
     * A stage typed as an amount keeps its amount. The stages together may never come to more than the total.
     *
     * @param  list<array{basis: string, percent?: mixed, amount?: mixed}>  $stages
     * @return list<string>
     *
     * @throws ValidationException
     */
    public static function stageAmounts(mixed $total, array $stages): array
    {
        $total = Dec::of($total);
        $amounts = [];

        foreach ($stages as $stage) {
            $amounts[] = $stage['basis'] === ClientPaymentStage::PERCENT
                ? Dec::percentOf($total, $stage['percent'] ?? 0)
                : Dec::money($stage['amount'] ?? 0);
        }

        if ($amounts === []) {
            return [];
        }

        if (self::allPercent($stages)) {
            $share = self::percentTotal($stages);

            if ($share->isGreaterThan(100)) {
                throw ValidationException::withMessages(['percent' => __('The stage percentages add up to :sum, which is more than the whole of the total.', ['sum' => self::plain($share)])]);
            }

            if ($share->isEqualTo(100)) {
                $last = array_key_last($amounts);
                $others = self::sum(array_slice($amounts, 0, $last));
                $rest = $total->minus($others);

                if ($rest->isNegative()) {
                    throw ValidationException::withMessages(['percent' => __('These percentages cannot be split to the paisa on this total. Change one of them.')]);
                }

                $amounts[$last] = Dec::money($rest);
            }
        }

        if (self::sum($amounts)->isGreaterThan($total)) {
            throw ValidationException::withMessages(['amount' => __('The stages would total more than the quotation total of :value.', ['value' => inr($total)])]);
        }

        return $amounts;
    }

    /** @param  list<array{basis: string}>  $stages */
    public static function allPercent(array $stages): bool
    {
        foreach ($stages as $stage) {
            if ($stage['basis'] !== ClientPaymentStage::PERCENT) {
                return false;
            }
        }

        return $stages !== [];
    }

    /** @param  list<array{basis: string, percent?: mixed}>  $stages */
    public static function percentTotal(array $stages): BigDecimal
    {
        $share = BigDecimal::zero();

        foreach ($stages as $stage) {
            if ($stage['basis'] === ClientPaymentStage::PERCENT) {
                $share = $share->plus(Dec::of($stage['percent'] ?? 0));
            }
        }

        return $share;
    }

    /** @param  iterable<mixed>  $amounts */
    public static function sum(iterable $amounts): BigDecimal
    {
        $sum = BigDecimal::zero();

        foreach ($amounts as $amount) {
            $sum = $sum->plus(Dec::of($amount));
        }

        return $sum;
    }

    /** A percentage as a person writes it: 33.3333, 40, never 40.0000. */
    public static function plain(mixed $number): string
    {
        $number = Dec::of($number);

        return $number->isZero() ? '0' : (string) $number->strippedOfTrailingZeros();
    }
}
