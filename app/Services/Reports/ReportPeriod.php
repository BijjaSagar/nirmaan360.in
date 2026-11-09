<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Turns a named period such as "last month" into dates. The financial year runs April to March. */
final class ReportPeriod
{
    /** Periods a saved schedule can use. They are worked out again on every run. */
    public const SCHEDULABLE = ['last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_fy'];

    public const LABELS = [
        'last_7_days' => 'Last 7 days',
        'last_30_days' => 'Last 30 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_fy' => 'This financial year',
        'custom' => 'Custom dates',
    ];

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    public static function resolve(string $period, ?string $from = null, ?string $to = null, ?CarbonInterface $now = null): array
    {
        $today = CarbonImmutable::instance($now ?? now())->startOfDay();

        return match ($period) {
            'last_7_days' => [$today->subDays(6), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'this_month' => [$today->startOfMonth(), $today],
            'this_fy' => [self::financialYearStart($today), $today],
            'custom' => [
                CarbonImmutable::parse($from ?: $today->startOfMonth()->toDateString())->startOfDay(),
                CarbonImmutable::parse($to ?: $today->toDateString())->startOfDay(),
            ],
            default => [$today->subDays(29), $today],
        };
    }

    public static function financialYearStart(CarbonInterface $day): CarbonImmutable
    {
        $day = CarbonImmutable::instance($day);

        return $day->month >= 4 ? $day->setDate($day->year, 4, 1)->startOfDay() : $day->setDate($day->year - 1, 4, 1)->startOfDay();
    }

    /** "01 Nov 2026 to 30 Nov 2026" */
    public static function label(CarbonInterface $from, CarbonInterface $to): string
    {
        return $from->format('d M Y').' '.__('to').' '.$to->format('d M Y');
    }
}
