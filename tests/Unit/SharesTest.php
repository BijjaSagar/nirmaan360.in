<?php

namespace Tests\Unit;

use App\Support\Dec;
use App\Support\Shares;
use PHPUnit\Framework\TestCase;

class SharesTest extends TestCase
{
    private function sum(array $shares): string
    {
        return Dec::money(array_reduce($shares, fn ($sum, $share) => Dec::of($sum)->plus(Dec::of($share)), '0'));
    }

    public function test_exact_shares_are_given_as_they_are(): void
    {
        $this->assertSame(['20.00', '30.00', '50.00'], Shares::of(['4000000', '6000000', '10000000']));
        $this->assertSame(['100.00'], Shares::of(['1250000.50']));
    }

    public function test_thirds_still_add_up_to_a_hundred_with_the_first_taking_the_spare_hundredth(): void
    {
        $shares = Shares::of(['100', '100', '100']);

        $this->assertSame(['33.34', '33.33', '33.33'], $shares);
        $this->assertSame('100.00', $this->sum($shares));
    }

    public function test_the_spare_hundredths_go_to_the_largest_remainders(): void
    {
        // 1/7, 2/7, 4/7: 14.2857…, 28.5714…, 57.1428… The first has the largest remainder, so it takes the spare hundredth.
        $shares = Shares::of(['1', '2', '4']);

        $this->assertSame(['14.29', '28.57', '57.14'], $shares);

        $this->assertSame(['42.86', '28.57', '28.57'], Shares::of(['3', '2', '2']));

        // Equal remainders: the spare hundredths go to the earlier phases.
        $this->assertSame(['16.67', '16.67', '16.67', '16.67', '16.66', '16.66'], Shares::of(['1', '1', '1', '1', '1', '1']));
        $this->assertSame('100.00', $this->sum($shares));
    }

    public function test_many_uneven_phases_always_add_up_to_exactly_a_hundred(): void
    {
        $amounts = ['1234567.89', '0.01', '99.99', '7777777.77', '3', '0', '42424242.42', '15.5'];
        $shares = Shares::of($amounts);

        $this->assertCount(count($amounts), $shares);
        $this->assertSame('100.00', $this->sum($shares));
        $this->assertSame('0.00', $shares[5]);

        foreach ($shares as $share) {
            $this->assertMatchesRegularExpression('/^\d{1,3}\.\d{2}$/', $share);
        }
    }

    public function test_a_zero_total_has_no_shares(): void
    {
        $this->assertSame([null, null], Shares::of(['0', '0.00']));
        $this->assertSame([], Shares::of([]));
    }

    public function test_amounts_are_taken_at_money_precision_without_floats(): void
    {
        $this->assertSame(['50.00', '50.00'], Shares::of(['0.1', '0.10']));
        $this->assertSame(['50.00', '50.00'], Shares::of([Dec::of('0.1')->plus('0.2'), '0.30']));
    }
}
