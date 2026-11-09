<?php

namespace App\Services\Crm;

use Carbon\CarbonImmutable;

/** Indian financial year: 1 April to 31 March. A year is named by the calendar year it starts in. */
final class FinancialYear
{
    public static function startYearOf(CarbonImmutable|\DateTimeInterface $date): int
    {
        $date = CarbonImmutable::instance($date);

        return $date->month >= 4 ? $date->year : $date->year - 1;
    }

    public static function current(): int
    {
        return self::startYearOf(CarbonImmutable::now());
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} First and last day. */
    public static function range(int $startYear): array
    {
        return [
            CarbonImmutable::create($startYear, 4, 1)->startOfDay(),
            CarbonImmutable::create($startYear + 1, 3, 31)->startOfDay(),
        ];
    }

    public static function label(int $startYear): string
    {
        return 'FY '.$startYear.'-'.substr((string) ($startYear + 1), -2);
    }
}
