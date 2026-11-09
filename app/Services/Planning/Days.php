<?php

namespace App\Services\Planning;

use Carbon\CarbonInterface;

/**
 * Whole-day arithmetic for schedules. A date becomes a plain day number, so spans and
 * shifts stay exact whatever the server's timezone or daylight saving does.
 */
final class Days
{
    public static function of(CarbonInterface|string $date): int
    {
        $text = is_string($date) ? substr($date, 0, 10) : $date->format('Y-m-d');
        [$year, $month, $day] = array_map('intval', explode('-', $text));

        return (int) floor(gmmktime(0, 0, 0, $month, $day, $year) / 86400);
    }

    public static function toDate(int $day): string
    {
        return gmdate('Y-m-d', $day * 86400);
    }

    public static function today(): int
    {
        return self::of(now());
    }

    /** The Sunday that closes the week holding this day. */
    public static function endOfWeek(int $day): int
    {
        return $day + 6 - (($day + 3) % 7);
    }

    public static function endOfMonth(int $day): int
    {
        return self::of(gmdate('Y-m-t', $day * 86400));
    }
}
