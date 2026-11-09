<?php

namespace Tests\Feature\Qhs;

use App\Models\QualityTest;
use App\Models\QualityTestResult;
use App\Services\Qhs\ConcreteStrength;
use Tests\TestCase;

class ConcreteStrengthTest extends TestCase
{
    /** @param  array<int, list<?string>>  $byAge  Strengths per age, null for a specimen not yet tested. */
    private function test(?string $required, array $byAge): QualityTest
    {
        $test = new QualityTest(['required_strength' => $required]);
        $results = collect();

        foreach ($byAge as $age => $strengths) {
            foreach ($strengths as $strength) {
                $results->push(new QualityTestResult(['age_days' => $age, 'strength' => $strength]));
            }
        }

        return $test->setRelation('results', $results);
    }

    private function verdict(QualityTest $test, int $age): ?string
    {
        return ConcreteStrength::evaluate($test)->firstWhere('age', $age)['verdict'];
    }

    public function test_cube_load_becomes_strength_on_a_150_mm_cube(): void
    {
        $this->assertSame('30.00', ConcreteStrength::fromCubeLoad('675'));
        $this->assertSame('20.00', ConcreteStrength::fromCubeLoad(450));
        $this->assertSame('15.00', ConcreteStrength::fromCubeLoad('337.5'));
        $this->assertSame('22.22', ConcreteStrength::fromCubeLoad('500')); // 22.2222
        $this->assertSame('22.23', ConcreteStrength::fromCubeLoad('500.2')); // 22.2311
    }

    public function test_the_grade_names_the_strength(): void
    {
        $this->assertSame('25.00', ConcreteStrength::gradeStrength('M25'));
        $this->assertSame('30.00', ConcreteStrength::gradeStrength(' m 30 '));
        $this->assertSame('100.00', ConcreteStrength::gradeStrength('M100'));
        $this->assertNull(ConcreteStrength::gradeStrength('Fe500'));
        $this->assertNull(ConcreteStrength::gradeStrength('M7.5'));
        $this->assertNull(ConcreteStrength::gradeStrength(null));
    }

    public function test_28_day_mean_must_reach_the_grade(): void
    {
        $this->assertSame('pass', $this->verdict($this->test('25.00', [28 => ['26.00', '27.50', '25.50']]), 28));
        $this->assertSame('pass', $this->verdict($this->test('25.00', [28 => ['24.00', '25.00', '26.00']]), 28), 'A mean of exactly 25.00 reaches the grade.');
        $this->assertSame('fail', $this->verdict($this->test('25.00', [28 => ['24.00', '24.00', '25.00']]), 28), 'A mean of 24.33 is short.');
    }

    public function test_28_day_no_single_specimen_may_be_far_below_the_grade(): void
    {
        $this->assertSame('fail', $this->verdict($this->test('25.00', [28 => ['34.00', '34.00', '21.90']]), 28), 'Mean 29.97 is fine but one cube is 3.10 below.');
        $this->assertSame('pass', $this->verdict($this->test('25.00', [28 => ['34.00', '34.00', '22.00']]), 28), 'Exactly 3.00 below is allowed.');
    }

    public function test_28_day_waits_for_every_specimen(): void
    {
        $partial = $this->test('25.00', [28 => ['10.00', '30.00', null]]);

        $this->assertSame('pending', $this->verdict($partial, 28));
        $this->assertSame('pending', $this->verdict($this->test('25.00', [28 => [null, null, null]]), 28));
        $this->assertSame(2, ConcreteStrength::evaluate($partial)->first()['tested']);
        $this->assertSame(3, ConcreteStrength::evaluate($partial)->first()['scheduled']);
    }

    public function test_7_day_is_only_an_early_indicator_of_about_65_percent(): void
    {
        $this->assertSame('ok', $this->verdict($this->test('25.00', [7 => ['16.20', '16.30']]), 7), 'Mean 16.25 is exactly 65% of 25.');
        $this->assertSame('low', $this->verdict($this->test('25.00', [7 => ['16.00', '16.20']]), 7));
        $this->assertSame('pending', $this->verdict($this->test('25.00', [7 => [null, null, null]]), 7));
        $this->assertSame('ok', $this->verdict($this->test('25.00', [7 => ['20.00', null, null]]), 7), 'One tested cube is enough to read the early trend.');
    }

    public function test_mean_and_lowest_are_reported_to_two_decimals(): void
    {
        $row = ConcreteStrength::evaluate($this->test('25.00', [28 => ['24.00', '24.00', '25.00']]))->first();

        $this->assertSame('24.33', $row['mean']);
        $this->assertSame('24.00', $row['lowest']);
    }

    public function test_no_verdict_without_a_required_strength_or_for_other_ages(): void
    {
        $this->assertNull($this->verdict($this->test(null, [28 => ['30.00', '30.00', '30.00']]), 28));
        $this->assertSame('30.00', ConcreteStrength::evaluate($this->test(null, [28 => ['30.00']]))->first()['mean']);
        $this->assertNull($this->verdict($this->test('25.00', [56 => ['35.00']]), 56));
        $this->assertNull($this->verdict($this->test('25.00', [3 => ['10.00']]), 3));
    }

    public function test_the_overall_verdict_is_the_28_day_one(): void
    {
        $this->assertSame('pass', ConcreteStrength::overall($this->test('25.00', [7 => ['10.00'], 28 => ['30.00', '30.00', '30.00']])));
        $this->assertNull(ConcreteStrength::overall($this->test('25.00', [7 => ['20.00']])));
    }

    public function test_ages_come_back_in_order(): void
    {
        $ages = ConcreteStrength::evaluate($this->test('25.00', [28 => ['30.00'], 7 => ['20.00'], 3 => ['10.00']]))->pluck('age')->all();

        $this->assertSame([3, 7, 28], $ages);
    }
}
