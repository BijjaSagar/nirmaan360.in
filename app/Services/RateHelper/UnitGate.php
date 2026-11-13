<?php

namespace App\Services\RateHelper;

use App\Services\Drawings\TakeoffService;

/**
 * Decides whether two rates are per the same kind of unit. A rate per cubic metre is never offered
 * for a line measured in square metres, and a lump sum is never a rate at all: another job's lump
 * sum says nothing about this one.
 */
final class UnitGate
{
    /**
     * What a unit is compared by: its dimension for lengths, areas, volumes and counts (the same
     * list the drawing takeoff uses), otherwise its own text in lower case without dots or spaces.
     * Null for a blank unit or a lump sum, which match nothing.
     */
    public static function key(?string $unit): ?string
    {
        $text = self::normalise($unit);

        if ($text === '' || self::isLumpSum($unit)) {
            return null;
        }

        $dimension = TakeoffService::dimensionOfUnit((string) $unit);

        return $dimension !== null ? 'dimension:'.$dimension : 'unit:'.$text;
    }

    public static function isLumpSum(?string $unit): bool
    {
        return in_array(self::normalise($unit), config('modules.rate_helper.lump_sum_units', []), true);
    }

    /** Both are real units of the same kind. */
    public static function same(?string $one, ?string $other): bool
    {
        $key = self::key($one);

        return $key !== null && $key === self::key($other);
    }

    private static function normalise(?string $unit): string
    {
        return mb_strtolower(str_replace([' ', '.', '²', '³'], ['', '', '2', '3'], trim((string) $unit)));
    }
}
