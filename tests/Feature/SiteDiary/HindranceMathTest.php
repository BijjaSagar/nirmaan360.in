<?php

namespace Tests\Feature\SiteDiary;

use App\Services\SiteDiary\HindranceMath;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The day counts of the register. Pure dates: no record, no user and no contract term is involved. */
class HindranceMathTest extends TestCase
{
    public static function periods(): array
    {
        return [
            'the same day is one day' => ['2026-03-02', '2026-03-02', 1],
            'two neighbouring days' => ['2026-03-02', '2026-03-03', 2],
            'a working week' => ['2026-03-02', '2026-03-06', 5],
            'across a month end' => ['2026-01-30', '2026-02-02', 4],
            'across the end of February' => ['2026-02-27', '2026-03-01', 3],
            'across the end of a leap February' => ['2028-02-27', '2028-03-01', 4],
            'across a year end' => ['2026-12-30', '2027-01-02', 4],
            'a whole year' => ['2026-01-01', '2026-12-31', 365],
        ];
    }

    #[DataProvider('periods')]
    public function test_days_are_calendar_days_with_both_ends_counted(string $from, string $to, int $days): void
    {
        $this->assertSame($days, HindranceMath::days($from, $to));
    }

    public function test_the_time_of_day_and_the_time_zone_never_change_a_count(): void
    {
        // Late evening in India is still the previous day in UTC. The count goes by the calendar day of each moment.
        $from = Carbon::parse('2026-03-02 23:50:00', 'Asia/Kolkata');
        $to = Carbon::parse('2026-03-06 00:10:00', 'Asia/Kolkata');

        $this->assertSame(5, HindranceMath::days($from, $to));
        $this->assertSame('2026-03-02', HindranceMath::day($from)->toDateString());
        $this->assertSame(4, HindranceMath::daysUntil($to, $from));
    }

    public function test_a_period_cannot_end_before_it_starts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HindranceMath::days('2026-03-06', '2026-03-02');
    }

    public function test_something_that_is_not_a_date_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HindranceMath::day('06/03/2026');
    }

    public function test_a_hindrance_still_going_on_is_counted_up_to_today(): void
    {
        $this->assertSame(19, HindranceMath::daysOf('2026-03-02', null, '2026-03-20'));
        $this->assertSame(1, HindranceMath::daysOf('2026-03-20', null, '2026-03-20'));
        $this->assertSame('2026-03-20', HindranceMath::countedTo('2026-03-02', null, '2026-03-20')->toDateString());

        // One that has ended keeps its own end, whatever today is.
        $this->assertSame(5, HindranceMath::daysOf('2026-03-02', '2026-03-06', '2026-03-20'));
        $this->assertSame('2026-03-06', HindranceMath::countedTo('2026-03-02', '2026-03-06', '2026-03-20')->toDateString());

        // Not started yet and no end: nothing to count.
        $this->assertSame(0, HindranceMath::daysOf('2026-03-25', null, '2026-03-20'));
        $this->assertNull(HindranceMath::countedTo('2026-03-25', null, '2026-03-20'));
    }

    public function test_overlapping_periods_count_a_shared_day_once(): void
    {
        // 2 to 6 March and 5 to 9 March share the 5th and 6th: 8 days, not 10.
        $union = HindranceMath::union([['2026-03-02', '2026-03-06'], ['2026-03-05', '2026-03-09']]);

        $this->assertSame(8, $union['days']);
        $this->assertSame([['from' => '2026-03-02', 'to' => '2026-03-09', 'days' => 8]], $union['periods']);
    }

    public function test_a_period_inside_another_adds_nothing(): void
    {
        $union = HindranceMath::union([['2026-03-01', '2026-03-31'], ['2026-03-10', '2026-03-12'], ['2026-03-31', '2026-03-31']]);

        $this->assertSame(31, $union['days']);
        $this->assertCount(1, $union['periods']);
    }

    public function test_periods_apart_are_added_and_kept_apart(): void
    {
        $union = HindranceMath::union([['2026-03-10', '2026-03-12'], ['2026-03-02', '2026-03-03']]);

        $this->assertSame(5, $union['days']);
        $this->assertSame([
            ['from' => '2026-03-02', 'to' => '2026-03-03', 'days' => 2],
            ['from' => '2026-03-10', 'to' => '2026-03-12', 'days' => 3],
        ], $union['periods']);
    }

    public function test_periods_that_follow_without_a_gap_are_one_run_of_days(): void
    {
        $union = HindranceMath::union([['2026-03-02', '2026-03-03'], ['2026-03-04', '2026-03-05']]);

        $this->assertSame(4, $union['days']);
        $this->assertSame([['from' => '2026-03-02', 'to' => '2026-03-05', 'days' => 4]], $union['periods']);

        // One clear day between them keeps them apart.
        $this->assertCount(2, HindranceMath::union([['2026-03-02', '2026-03-03'], ['2026-03-05', '2026-03-05']])['periods']);
    }

    public function test_the_order_and_repeats_of_periods_do_not_change_the_total(): void
    {
        $periods = [['2026-03-05', '2026-03-09'], ['2026-03-02', '2026-03-06'], ['2026-03-20', '2026-03-20'], ['2026-03-02', '2026-03-06']];

        $this->assertSame(9, HindranceMath::union($periods)['days']);
        $this->assertSame(9, HindranceMath::union(array_reverse($periods))['days']);
        $this->assertSame(['days' => 0, 'periods' => []], HindranceMath::union([]));
    }

    public function test_days_of_a_period_with_no_report_are_counted(): void
    {
        // Five days, reports on two of them. A report outside the period and a repeated date change nothing.
        $dates = ['2026-03-02', '2026-03-04', '2026-03-04', '2026-03-09', '2026-02-27'];

        $this->assertSame(3, HindranceMath::daysWithout('2026-03-02', '2026-03-06', $dates));
        $this->assertSame(5, HindranceMath::daysWithout('2026-03-02', '2026-03-06', []));
        $this->assertSame(0, HindranceMath::daysWithout('2026-03-04', '2026-03-04', $dates));
    }

    public function test_adding_days_to_a_date_does_not_count_the_date_itself(): void
    {
        $this->assertSame('2026-03-03', HindranceMath::plusDays('2026-03-02', 1)->toDateString());
        $this->assertSame('2026-03-13', HindranceMath::plusDays('2026-03-02', 11)->toDateString());
        $this->assertSame('2026-03-02', HindranceMath::plusDays('2026-03-02', 0)->toDateString());
        $this->assertSame('2027-01-03', HindranceMath::plusDays('2026-12-30', 4)->toDateString());

        $this->expectException(InvalidArgumentException::class);
        HindranceMath::plusDays('2026-03-02', -1);
    }

    public function test_days_until_a_date_are_zero_on_the_day_and_negative_after_it(): void
    {
        $this->assertSame(3, HindranceMath::daysUntil('2026-03-23', '2026-03-20'));
        $this->assertSame(0, HindranceMath::daysUntil('2026-03-20', '2026-03-20'));
        $this->assertSame(-2, HindranceMath::daysUntil('2026-03-18', '2026-03-20'));
    }
}
