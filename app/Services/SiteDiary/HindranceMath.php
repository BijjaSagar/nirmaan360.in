<?php

namespace App\Services\SiteDiary;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The arithmetic of the hindrance register, on calendar dates alone.
 *
 * Days are calendar days and both ends count: a hindrance that starts and ends on the same day
 * lasted one day. The product has no holiday calendar, so Sundays and holidays count like any
 * other day, and every screen that shows a count says so. Nothing here knows a notice period:
 * a number of days to add is always handed in by the caller, who read it from a confirmed term.
 */
final class HindranceMath
{
    /** The calendar day of a moment, with the time of day dropped. */
    public static function day(CarbonInterface|string $date): CarbonImmutable
    {
        $text = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
            throw new InvalidArgumentException('A day is written as year-month-day.');
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $text, 'UTC');
    }

    /** Calendar days from the first day to the last, both counted. */
    public static function days(CarbonInterface|string $from, CarbonInterface|string $to): int
    {
        $from = self::day($from);
        $to = self::day($to);

        if ($to->lt($from)) {
            throw new InvalidArgumentException('A period cannot end before it starts.');
        }

        return (int) $from->diffInDays($to) + 1;
    }

    /**
     * The last day counted for a hindrance: the day it ended, or today while it is still going on.
     * Null when it is still going on and has not started yet, so there is nothing to count.
     */
    public static function countedTo(CarbonInterface|string $start, CarbonInterface|string|null $end, CarbonInterface|string $today): ?CarbonImmutable
    {
        if ($end !== null) {
            return self::day($end);
        }

        $today = self::day($today);

        return $today->lt(self::day($start)) ? null : $today;
    }

    /** Days a hindrance has lasted: up to its end, or up to today while it is still going on. */
    public static function daysOf(CarbonInterface|string $start, CarbonInterface|string|null $end, CarbonInterface|string $today): int
    {
        $to = self::countedTo($start, $end, $today);

        return $to === null ? 0 : self::days($start, $to);
    }

    /**
     * Several periods laid over one another, so a day two hindrances share is counted once.
     * Periods that overlap or follow each other without a gap are joined.
     *
     * @param  iterable<array{0: CarbonInterface|string, 1: CarbonInterface|string}>  $periods  First and last day of each.
     * @return array{days: int, periods: list<array{from: string, to: string, days: int}>}
     */
    public static function union(iterable $periods): array
    {
        $spans = [];

        foreach ($periods as [$from, $to]) {
            $from = self::day($from);
            $to = self::day($to);

            if ($to->lt($from)) {
                throw new InvalidArgumentException('A period cannot end before it starts.');
            }

            $spans[] = [$from, $to];
        }

        usort($spans, fn (array $a, array $b) => [$a[0]->toDateString(), $a[1]->toDateString()] <=> [$b[0]->toDateString(), $b[1]->toDateString()]);

        $joined = [];

        foreach ($spans as [$from, $to]) {
            $last = array_key_last($joined);

            // The day after the last period's end still belongs to the same run of days.
            if ($last !== null && $from->lte($joined[$last][1]->addDay())) {
                if ($to->gt($joined[$last][1])) {
                    $joined[$last][1] = $to;
                }

                continue;
            }

            $joined[] = [$from, $to];
        }

        $total = 0;
        $list = [];

        foreach ($joined as [$from, $to]) {
            $days = self::days($from, $to);
            $total += $days;
            $list[] = ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days];
        }

        return ['days' => $total, 'periods' => $list];
    }

    /**
     * How many days of a period are not among the given dates, for example the days of a
     * hindrance on which no daily report was written.
     *
     * @param  iterable<CarbonInterface|string>  $dates
     */
    public static function daysWithout(CarbonInterface|string $from, CarbonInterface|string $to, iterable $dates): int
    {
        $from = self::day($from)->toDateString();
        $to = self::day($to)->toDateString();
        $inside = [];

        foreach ($dates as $date) {
            $day = self::day($date)->toDateString();

            if ($day >= $from && $day <= $to) {
                $inside[$day] = true;
            }
        }

        return self::days($from, $to) - count($inside);
    }

    /** The date a number of days after a day: the day itself is not counted, so one day after is the next day. */
    public static function plusDays(CarbonInterface|string $from, int $days): CarbonImmutable
    {
        if ($days < 0) {
            throw new InvalidArgumentException('A number of days cannot be below zero.');
        }

        return self::day($from)->addDays($days);
    }

    /** Days from today to a date: 0 on the day itself, below zero once it has passed. */
    public static function daysUntil(CarbonInterface|string $date, CarbonInterface|string $today): int
    {
        return (int) self::day($today)->diffInDays(self::day($date), false);
    }
}
