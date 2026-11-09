<?php

namespace App\Services\Qhs;

use App\Models\QualityTest;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * Turns cube loads into strengths and flags results against the grade.
 *
 * This is a site register, not a certificate. At 28 days the mean of the specimens must reach the
 * grade, and no single specimen may sit more than the individual tolerance (3 N/mm2 by default,
 * the IS 456 individual-result limit) below it. At 7 days the mean is only an early indicator.
 */
final class ConcreteStrength
{
    /** The strength in N/mm2 named by a grade such as "M25", or null if the grade is not of that form. */
    public static function gradeStrength(?string $grade): ?string
    {
        if ($grade !== null && preg_match('/^M\s*(\d{2,3})$/i', trim($grade), $match)) {
            return Dec::money($match[1]);
        }

        return null;
    }

    /** Strength in N/mm2 from the crushing load in kN on a standard 150 mm cube. */
    public static function fromCubeLoad(mixed $loadKn): string
    {
        return (string) Dec::of($loadKn)
            ->multipliedBy(1000)
            ->dividedBy(config('modules.qhs.cube_area_mm2'), 2, RoundingMode::HalfUp);
    }

    /**
     * Results by age. Verdicts: 28 days is pending, pass or fail; 7 days is pending, ok or low;
     * other ages, and any age on a test with no required strength, carry no verdict.
     *
     * @return Collection<int, array{age:int,scheduled:int,tested:int,mean:?string,lowest:?string,verdict:?string}>
     */
    public static function evaluate(QualityTest $test): Collection
    {
        $required = $test->required_strength !== null ? Dec::of($test->required_strength) : null;

        return $test->results->groupBy('age_days')->sortKeys()->map(function (Collection $specimens, int|string $age) use ($required) {
            $tested = $specimens->filter(fn ($result) => $result->strength !== null);
            $strengths = $tested->map(fn ($result) => Dec::of($result->strength));

            $mean = $strengths->isEmpty()
                ? null
                : $strengths->reduce(fn (BigDecimal $sum, BigDecimal $value) => $sum->plus($value), Dec::of(0))->dividedBy($strengths->count(), 2, RoundingMode::HalfUp);

            $lowest = $strengths->isEmpty() ? null : $strengths->reduce(fn (?BigDecimal $min, BigDecimal $value) => $min === null || $value->isLessThan($min) ? $value : $min);

            return [
                'age' => (int) $age,
                'scheduled' => $specimens->count(),
                'tested' => $tested->count(),
                'mean' => $mean === null ? null : (string) $mean,
                'lowest' => $lowest === null ? null : (string) $lowest,
                'verdict' => self::verdict((int) $age, $specimens->count(), $tested->count(), $mean, $lowest, $required),
            ];
        })->values();
    }

    /** The 28-day verdict for a test, or null if it has none. */
    public static function overall(QualityTest $test): ?string
    {
        return self::evaluate($test)->firstWhere('age', 28)['verdict'] ?? null;
    }

    private static function verdict(int $age, int $scheduled, int $tested, ?BigDecimal $mean, ?BigDecimal $lowest, ?BigDecimal $required): ?string
    {
        if ($required === null || ! in_array($age, [7, 28], true)) {
            return null;
        }

        if ($age === 7) {
            if ($mean === null) {
                return 'pending';
            }

            $expected = $required->multipliedBy(config('modules.qhs.early_strength_pct'))->dividedBy(100, 2, RoundingMode::HalfUp);

            return $mean->isGreaterThanOrEqualTo($expected) ? 'ok' : 'low';
        }

        if ($tested === 0 || $tested < $scheduled) {
            return 'pending';
        }

        $floor = $required->minus(config('modules.qhs.individual_tolerance'));

        return $mean->isGreaterThanOrEqualTo($required) && $lowest->isGreaterThanOrEqualTo($floor) ? 'pass' : 'fail';
    }
}
