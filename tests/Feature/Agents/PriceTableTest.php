<?php

namespace Tests\Feature\Agents;

use App\Models\AgentStep;
use App\Services\Agents\CostMeter;
use App\Services\Agents\ModelGateway;
use RuntimeException;

/** The list prices and the one model that costs more for a long prompt. Figures are US dollars per million tokens. */
class PriceTableTest extends AgentTestCase
{
    private function usd(string $model, int $input = 0, int $output = 0, int $cacheRead = 0, int $cacheWrite = 0): string
    {
        return app(CostMeter::class)->usd($model, ['input' => $input, 'output' => $output, 'cache_read' => $cacheRead, 'cache_write' => $cacheWrite]);
    }

    public function test_the_table_prices_exactly_the_three_models_the_gateway_names(): void
    {
        $this->assertSame(
            [ModelGateway::DEFAULT_MODEL, ModelGateway::HARD_MODEL, ModelGateway::CHEAP_MODEL],
            array_keys(config('modules.agents.pricing')),
        );
        $this->assertSame(['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-haiku-5-5'], array_keys(config('modules.agents.pricing')));
    }

    public function test_a_cache_read_costs_what_each_model_lists(): void
    {
        $this->assertSame('0.100000', $this->usd('claude-sonnet-5-5', cacheRead: 1000000));
        $this->assertSame('0.200000', $this->usd('claude-opus-5-5', cacheRead: 1000000));
        $this->assertSame('0.001000', $this->usd('claude-haiku-5-5', cacheRead: 100000));
    }

    public function test_the_other_prices_did_not_move(): void
    {
        $this->assertSame('2.000000', $this->usd('claude-sonnet-5-5', input: 1000000));
        $this->assertSame('10.000000', $this->usd('claude-sonnet-5-5', output: 1000000));
        $this->assertSame('2.500000', $this->usd('claude-sonnet-5-5', cacheWrite: 1000000));
        $this->assertSame('4.000000', $this->usd('claude-opus-5-5', input: 1000000));
        $this->assertSame('20.000000', $this->usd('claude-opus-5-5', output: 1000000));
        $this->assertSame('5.000000', $this->usd('claude-opus-5-5', cacheWrite: 1000000));
        $this->assertSame('0.010000', $this->usd('claude-haiku-5-5', input: 100000));
        $this->assertSame('0.500000', $this->usd('claude-haiku-5-5', output: 1000000));
        $this->assertSame('0.012500', $this->usd('claude-haiku-5-5', cacheWrite: 100000));
    }

    public function test_haiku_at_exactly_the_threshold_pays_the_base_prices(): void
    {
        // 60,000 + 30,000 + 10,000 = 100,000 prompt tokens.
        // 60,000 × 0.10 + 30,000 × 0.01 + 10,000 × 0.125 + 1,000 × 0.50 = 8,050 per million.
        $this->assertSame('0.008050', $this->usd('claude-haiku-5-5', input: 60000, output: 1000, cacheRead: 30000, cacheWrite: 10000));
        $this->assertSame('0.010000', $this->usd('claude-haiku-5-5', input: 100000));
    }

    public function test_haiku_one_token_over_the_threshold_pays_the_long_prompt_prices_for_everything(): void
    {
        // 60,001 + 30,000 + 10,000 = 100,001 prompt tokens.
        // 60,001 × 0.50 + 30,000 × 0.05 + 10,000 × 0.625 + 1,000 × 2.50 = 40,250.5 per million, rounded half up.
        $this->assertSame('0.040251', $this->usd('claude-haiku-5-5', input: 60001, output: 1000, cacheRead: 30000, cacheWrite: 10000));
        $this->assertSame('0.050001', $this->usd('claude-haiku-5-5', input: 100001));
    }

    public function test_a_long_prompt_is_long_even_when_all_of_it_came_from_the_cache(): void
    {
        $this->assertSame('0.001000', $this->usd('claude-haiku-5-5', cacheRead: 100000));
        $this->assertSame('0.005000', $this->usd('claude-haiku-5-5', cacheRead: 100001));
        $this->assertSame('0.062501', $this->usd('claude-haiku-5-5', cacheWrite: 100001));
    }

    public function test_what_the_model_writes_back_does_not_make_a_prompt_long(): void
    {
        // 100,000 in and 50,000 out: still the base prices. 100,000 × 0.10 + 50,000 × 0.50 = 35,000 per million.
        $this->assertSame('0.035000', $this->usd('claude-haiku-5-5', input: 100000, output: 50000));
        // One more token in and the answer is charged at the higher output price too.
        $this->assertSame('0.175001', $this->usd('claude-haiku-5-5', input: 100001, output: 50000));
    }

    public function test_sonnet_and_opus_have_one_price_at_any_length(): void
    {
        $this->assertSame('1.800000', $this->usd('claude-sonnet-5-5', input: 900000));
        $this->assertSame('3.600000', $this->usd('claude-opus-5-5', input: 900000));
        $this->assertArrayNotHasKey('long_prompt', config('modules.agents.pricing.claude-sonnet-5-5'));
        $this->assertArrayNotHasKey('long_prompt', config('modules.agents.pricing.claude-opus-5-5'));
    }

    public function test_the_one_hour_cache_price_is_listed_and_charged_to_nobody(): void
    {
        $this->assertSame('4.00', config('modules.agents.pricing.claude-sonnet-5-5.cache_write_1h'));
        $this->assertSame('8.00', config('modules.agents.pricing.claude-opus-5-5.cache_write_1h'));
        $this->assertSame('0.20', config('modules.agents.pricing.claude-haiku-5-5.cache_write_1h'));
        $this->assertSame('1.00', config('modules.agents.pricing.claude-haiku-5-5.long_prompt.cache_write_1h'));

        // A call with nothing but the four counted kinds, all zero, costs nothing whatever else the table lists.
        $this->assertSame('0.000000', $this->usd('claude-sonnet-5-5'));
        $this->assertSame('0.000000', $this->usd('claude-haiku-5-5'));
    }

    public function test_one_hour_cache_writes_would_be_priced_and_counted_as_prompt_if_a_caller_reported_them(): void
    {
        $meter = app(CostMeter::class);

        $this->assertSame('0.004000', $meter->usd('claude-sonnet-5-5', ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'cache_write_1h' => 1000]));
        $this->assertSame('0.020000', $meter->usd('claude-haiku-5-5', ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'cache_write_1h' => 100000]));
        $this->assertSame('0.100001', $meter->usd('claude-haiku-5-5', ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'cache_write_1h' => 100001]));
    }

    public function test_every_price_row_can_price_every_kind_of_token_a_reply_reports(): void
    {
        $kinds = ['input', 'output', 'cache_read', 'cache_write', 'cache_write_1h'];

        foreach (config('modules.agents.pricing') as $model => $rates) {
            foreach ($kinds as $kind) {
                $this->assertIsString($rates[$kind], "{$model} {$kind}");
                $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $rates[$kind], "{$model} {$kind}");
            }
        }

        $long = config('modules.agents.pricing.claude-haiku-5-5.long_prompt');
        $this->assertSame(100000, $long['over']);

        foreach ($kinds as $kind) {
            $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $long[$kind], "long prompt {$kind}");
        }
    }

    public function test_a_model_without_a_price_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->usd('claude-sonnet-5');
    }

    public function test_an_agent_step_that_reads_from_the_cache_is_charged_the_new_sonnet_price(): void
    {
        $organization = $this->tenant('Shree Buildcon');
        $owner = $this->member($organization, 'owner');
        $this->connect();

        $reply = $this->finalAnswer('All fine.');
        $reply['usage'] = ['input_tokens' => 1000, 'output_tokens' => 100, 'cache_read_input_tokens' => 10000, 'cache_creation_input_tokens' => 0];
        $this->fakeClaude([$reply]);

        $run = $this->runAgent('project_brain', $owner);

        $step = AgentStep::withoutGlobalScopes()->where('agent_run_id', $run->id)->where('model', 'claude-sonnet-5-5')->sole();

        // 1,000 × 2.00 + 100 × 10.00 + 10,000 × 0.10 = 4,000 per million.
        $this->assertSame(10000, $step->cache_read_tokens);
        $this->assertSame('0.004000', (string) $step->cost_usd);
    }
}
