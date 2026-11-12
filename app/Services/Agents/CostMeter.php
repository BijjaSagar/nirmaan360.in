<?php

namespace App\Services\Agents;

use App\Models\AgentStep;
use App\Models\Organization;
use App\Support\Dec;
use App\Support\PlatformSettings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use RuntimeException;

/** Turns token counts into dollars and credits, and reports each company's usage. */
class CostMeter
{
    public function __construct(private PlatformSettings $settings) {}

    /**
     * @param  array{input:int,output:int,cache_read:int,cache_write:int}  $usage  One call's tokens, never a total over several calls: a model with a long-prompt price is priced call by call.
     * @return string US dollars at list price, six decimals.
     */
    public function usd(string $model, array $usage): string
    {
        $rates = config("modules.agents.pricing.{$model}") ?? throw new RuntimeException("No price set for model {$model}.");

        // The prompt is everything sent in, cached or not. What the model wrote back does not count toward it.
        $prompt = (int) ($usage['input'] ?? 0) + (int) ($usage['cache_read'] ?? 0) + (int) ($usage['cache_write'] ?? 0);

        if (isset($rates['long_prompt']) && $prompt > (int) $rates['long_prompt']['over']) {
            $rates = $rates['long_prompt'];
        }

        $total = BigDecimal::zero();

        foreach ($usage as $kind => $tokens) {
            $total = $total->plus(Dec::of($tokens)->multipliedBy(Dec::of($rates[$kind])));
        }

        return (string) $total->dividedBy(1000000, 6, RoundingMode::HalfUp);
    }

    /** Credits to charge for a dollar cost, rounded up so a sliver of usage is never free. */
    public function credits(string $usd): string
    {
        return (string) Dec::of($usd)
            ->multipliedBy(Dec::of($this->usdToInr()))
            ->multipliedBy(Dec::of($this->margin()))
            ->dividedBy(Dec::of(config('modules.agents.credit_inr')), 4, RoundingMode::Up);
    }

    public function usdToInr(): string
    {
        return (string) $this->settings->get('ai_usd_inr', config('modules.agents.usd_inr'));
    }

    public function margin(): string
    {
        return (string) $this->settings->get('ai_margin', config('modules.agents.margin'));
    }

    /**
     * One company's use of each model from a date onward.
     *
     * @return array<string, array{calls:int,input:int,output:int,cache_read:int,cache_write:int,cost_usd:string,credits:string}>
     */
    public function usage(Organization $organization, mixed $from): array
    {
        $rows = AgentStep::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('created_at', '>=', $from)
            ->groupBy('model')
            ->selectRaw('model, COUNT(*) as calls, SUM(input_tokens) as input, SUM(output_tokens) as output, SUM(cache_read_tokens) as cache_read, SUM(cache_write_tokens) as cache_write, SUM(cost_usd) as cost_usd, SUM(credits) as credits')
            ->get();

        return $rows->mapWithKeys(fn ($row) => [$row->model => [
            'calls' => (int) $row->calls,
            'input' => (int) $row->input,
            'output' => (int) $row->output,
            'cache_read' => (int) $row->cache_read,
            'cache_write' => (int) $row->cache_write,
            'cost_usd' => (string) Dec::of($row->cost_usd ?? 0)->toScale(6, RoundingMode::HalfUp),
            'credits' => (string) Dec::of($row->credits ?? 0)->toScale(4, RoundingMode::HalfUp),
        ]])->all();
    }
}
