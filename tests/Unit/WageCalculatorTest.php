<?php

namespace Tests\Unit;

use App\Services\Payroll\WageCalculator;
use App\Support\RupeeWords;
use PHPUnit\Framework\TestCase;

class WageCalculatorTest extends TestCase
{
    private const TERMS = ['shift_hours' => '8', 'ot_multiplier' => '2'];

    private function marks(array $rows): array
    {
        return array_map(fn ($row) => ['status' => $row[0], 'ot_hours' => $row[1] ?? 0], $rows);
    }

    /**
     * A mason on ₹800 a day works 5 full days, 1 half day, is absent for 1 day, and does 3 hours of
     * overtime (2 on one day, 1 on an overtime-status day).
     * Payable days 5.5 -> ₹4,400. Hourly rate ₹100, overtime at 2x for 3 hours -> ₹600. Gross ₹5,000.
     */
    public function test_worked_example_with_half_day_absence_and_overtime(): void
    {
        $calculator = new WageCalculator;
        $tally = $calculator->tally($this->marks([
            ['present', 2], ['present'], ['present'], ['overtime', 1], ['present'], ['half'], ['absent'],
        ]));

        $this->assertSame(['present' => '5.000', 'half' => '1.000', 'absent' => '1.000', 'payable' => '5.500', 'ot_hours' => '3.000'], $tally);

        $pay = $calculator->pay('800.00', $tally, self::TERMS);

        $this->assertSame('4400.00', $pay['day_pay']);
        $this->assertSame('600.00', $pay['ot_pay']);
        $this->assertSame('5000.00', $pay['gross']);
    }

    public function test_overtime_is_rounded_once_on_the_whole_amount(): void
    {
        // ₹725 / 8 = ₹90.625 an hour. 2.5 hours at 2x = ₹453.125, which rounds half up to ₹453.13.
        $calculator = new WageCalculator;
        $tally = $calculator->tally($this->marks([['present', 2.5]]));
        $pay = $calculator->pay('725.00', $tally, self::TERMS);

        $this->assertSame('725.00', $pay['day_pay']);
        $this->assertSame('453.13', $pay['ot_pay']);
        $this->assertSame('1178.13', $pay['gross']);
    }

    public function test_overtime_on_an_absent_day_earns_nothing(): void
    {
        $tally = (new WageCalculator)->tally($this->marks([['absent', 4]]));

        $this->assertSame('0.000', $tally['ot_hours']);
        $this->assertSame('0.000', $tally['payable']);
    }

    public function test_company_terms_change_the_overtime_rate(): void
    {
        // 9 hour day, overtime at 1.5x: ₹900 / 9 = ₹100 an hour, 4 hours -> ₹600.
        $calculator = new WageCalculator;
        $pay = $calculator->pay('900.00', $calculator->tally($this->marks([['present', 4]])), ['shift_hours' => '9', 'ot_multiplier' => '1.5']);

        $this->assertSame('900.00', $pay['day_pay']);
        $this->assertSame('600.00', $pay['ot_pay']);
    }

    public function test_money_never_picks_up_float_error(): void
    {
        // 0.1 + 0.2 style trouble: 33 half days of ₹333.33.
        $calculator = new WageCalculator;
        $tally = $calculator->tally($this->marks(array_fill(0, 33, ['half'])));

        $this->assertSame('16.500', $tally['payable']);
        $this->assertSame('5499.95', $calculator->pay('333.33', $tally, self::TERMS)['day_pay']);
    }

    public function test_amount_in_words_uses_lakh_and_crore(): void
    {
        $this->assertSame('Rupees Four Thousand Five Hundred Only', RupeeWords::of('4500.00'));
        $this->assertSame('Rupees One Lakh Twenty Three Thousand Four Hundred Fifty Six and Seventy Eight Paise Only', RupeeWords::of('123456.78'));
        $this->assertSame('Rupees Two Crore Five Only', RupeeWords::of('20000005'));
        $this->assertSame('Rupees Zero Only', RupeeWords::of('0'));
        $this->assertSame('Fifty Paise Only', RupeeWords::of('0.50'));
    }
}
