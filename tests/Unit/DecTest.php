<?php

namespace Tests\Unit;

use App\Support\Dec;
use PHPUnit\Framework\TestCase;

class DecTest extends TestCase
{
    public function test_groups_digits_the_indian_way(): void
    {
        $this->assertSame('12,34,567.89', Dec::indian('1234567.89'));
        $this->assertSame('1,00,000.00', Dec::indian('100000'));
        $this->assertSame('999.00', Dec::indian('999'));
        $this->assertSame('-12,500.00', Dec::indian('-12500'));
        $this->assertSame('1,23,45,678.900', Dec::indian('12345678.9', 3));
    }

    public function test_rounds_half_up_without_float_drift(): void
    {
        $this->assertSame('1.01', Dec::money('1.005'));
        $this->assertSame('0.30', Dec::money(Dec::of('0.1')->plus('0.2')));
        $this->assertSame('2.346', Dec::qty('2.3455'));
    }

    public function test_percent_of_base(): void
    {
        $this->assertSame('12500.00', Dec::percentOf('250000', '5'));
        $this->assertSame('33.33', Dec::percentOf('1000', '3.333'));
    }
}
