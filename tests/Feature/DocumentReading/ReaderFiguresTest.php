<?php

namespace Tests\Feature\DocumentReading;

use App\Services\Agents\CostMeter;
use App\Services\DocumentReading\ImageTokens;
use App\Support\Dec;
use Tests\TestCase;

/** The reader's figures in config/modules/document_reading.php belong together. These hold them to one another. */
class ReaderFiguresTest extends TestCase
{
    /** @return array<string, array<string, mixed>> Every call the module can make: "kind / pass" and "purpose". */
    private function calls(): array
    {
        $calls = [];

        foreach (config('modules.document_reading.kinds') as $kind => $figures) {
            foreach ($figures['passes'] as $pass => $call) {
                $calls["{$kind} / {$pass}"] = $call;
            }
        }

        return [...$calls, ...config('modules.document_reading.purposes')];
    }

    public function test_every_call_can_write_its_maximum_well_inside_its_time_limit(): void
    {
        $speed = (int) config('modules.document_reading.assumed_tokens_per_second');

        $this->assertGreaterThan(0, $speed);
        $this->assertGreaterThanOrEqual(13, count($this->calls()));

        foreach ($this->calls() as $name => $call) {
            // Thinking counts toward max_tokens, so the whole of it must fit in 80 percent of the time allowed.
            $this->assertLessThanOrEqual(0.8 * $call['timeout'] * $speed, $call['max_tokens'], $name);
            // Longer than this needs streaming, which this program does not use.
            $this->assertLessThanOrEqual(240, $call['timeout'], $name);
            $this->assertSame('low', $call['effort'], $name);
        }
    }

    public function test_every_model_named_has_a_price_and_is_one_of_the_three_in_use(): void
    {
        $meter = app(CostMeter::class);

        foreach ($this->calls() as $name => $call) {
            foreach (array_filter([$call['model'], $call['stronger'] ?? null]) as $model) {
                $this->assertContains($model, ['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-haiku-5-5'], $name);
                $this->assertTrue(Dec::of($meter->usd($model, ['input' => 1000, 'output' => 1000, 'cache_read' => 0, 'cache_write' => 0]))->isPositive(), $name);
            }

            if (isset($call['stronger'])) {
                $this->assertNotSame($call['model'], $call['stronger'], $name);
            }
        }
    }

    public function test_a_reader_call_never_reaches_the_long_prompt_price(): void
    {
        $largest = ImageTokens::MAX_TOKENS + (int) config('modules.document_reading.prompt_tokens');

        foreach (config('modules.agents.pricing') as $model => $rates) {
            if (isset($rates['long_prompt'])) {
                $this->assertLessThan(90000, $largest, $model);
                $this->assertLessThan($rates['long_prompt']['over'], $largest, $model);
            }
        }
    }

    public function test_each_pass_has_its_typical_figures_and_follows_only_passes_of_its_own_kind(): void
    {
        foreach (config('modules.document_reading.kinds') as $kind => $figures) {
            foreach ($figures['passes'] as $pass => $call) {
                $this->assertGreaterThan(0, $call['typical_output'], "{$kind} / {$pass}");
                $this->assertLessThanOrEqual($call['max_tokens'], $call['typical_output'], "{$kind} / {$pass}");
                $this->assertGreaterThan(0, $call['typical_seconds'], "{$kind} / {$pass}");
                $this->assertLessThan($call['timeout'], $call['typical_seconds'], "{$kind} / {$pass}");

                foreach ($call['then'] ?? [] as $next) {
                    $this->assertArrayHasKey($next, $figures['passes'], "{$kind} / {$pass}");
                    $this->assertNotSame($pass, $next);
                }
            }
        }
    }

    public function test_budgets_name_features_that_exist_and_the_shares_are_shares(): void
    {
        $features = [...array_keys(config('modules.document_reading.kinds')), ...array_keys(config('modules.document_reading.purposes'))];

        foreach (['user', 'company'] as $scope) {
            foreach (config("modules.document_reading.budgets.{$scope}") as $feature => $credits) {
                $this->assertContains($feature, [...$features, 'total'], $scope);

                // Nobody's own budget is larger than the company's for the same feature.
                if ($scope === 'user') {
                    $this->assertTrue(Dec::of($credits)->isLessThanOrEqualTo(Dec::of(config("modules.document_reading.budgets.company.{$feature}"))), $feature);
                }
            }
        }

        $this->assertArrayNotHasKey('total', config('modules.document_reading.budgets.user'));
        $this->assertNull(config('modules.document_reading.budgets.company.total'));

        foreach (['hold_share', 'company_share_of_ceiling', 'ceilings.month_share'] as $share) {
            $value = Dec::of(config("modules.document_reading.{$share}"));
            $this->assertTrue($value->isPositive() && $value->isLessThan(1), $share);
        }

        // The floor is below the offered limit, and both are above the estimate itself.
        $this->assertTrue(Dec::of(config('modules.document_reading.floor_factor'))->isGreaterThan(1));
        $this->assertTrue(Dec::of(config('modules.document_reading.cap_factor'))->isGreaterThan(Dec::of(config('modules.document_reading.floor_factor'))));

        // A trial never gets more than a paying company.
        $this->assertTrue(Dec::of(config('modules.document_reading.trial.max_credits_per_read'))->isLessThan(Dec::of(config('modules.document_reading.max_credits_per_read'))));
        $this->assertTrue(Dec::of(config('modules.document_reading.absorbed_usd_per_day.trial'))->isLessThan(Dec::of(config('modules.document_reading.absorbed_usd_per_day.paying'))));
    }
}
