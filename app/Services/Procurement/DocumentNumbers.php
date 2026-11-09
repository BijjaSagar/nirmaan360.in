<?php

namespace App\Services\Procurement;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Gap-free document numbers per company, series and financial year. */
class DocumentNumbers
{
    public function next(int $organizationId, string $series, ?CarbonInterface $date = null): string
    {
        $year = self::financialYear($date ?? now());

        return DB::transaction(function () use ($organizationId, $series, $year) {
            $key = ['organization_id' => $organizationId, 'series' => $series, 'financial_year' => $year];

            DB::table('procurement_counters')->insertOrIgnore([...$key, 'last_no' => 0]);

            $counter = DB::table('procurement_counters')->where($key)->lockForUpdate()->first();
            $number = $counter->last_no + 1;

            DB::table('procurement_counters')->where('id', $counter->id)->update(['last_no' => $number]);

            return sprintf('%s-%s-%04d', $series, $year, $number);
        });
    }

    /** April to March, written like 2026-27. */
    public static function financialYear(CarbonInterface $date): string
    {
        $start = $date->month >= 4 ? $date->year : $date->year - 1;

        return sprintf('%d-%02d', $start, ($start + 1) % 100);
    }
}
