<?php

namespace App\Services\SiteAlerts;

use App\Models\BoqItem;
use App\Models\Project;
use App\Services\SiteAlerts\Contracts\SpendSource;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Budget and money gone on a project. The budget is the figure set on the project, or the BOQ
 * value when none is set, because the budget is decided at BOQ rates. Spend is the sum of every
 * registered spend source. Everything here is money, so callers must hold money.overview.
 */
class Spend
{
    /** @return list<SpendSource> */
    public function sources(): array
    {
        $sources = [];

        foreach (config('modules', []) as $module) {
            foreach ($module['spend_sources'] ?? [] as $class) {
                $sources[$class] ??= app($class);
            }
        }

        return array_values($sources);
    }

    /** @return array{amount: ?string, from_boq: bool, margin_pct: string} */
    public function budget(Project $project): array
    {
        if ($project->budget_amount !== null) {
            return ['amount' => Dec::money($project->budget_amount), 'from_boq' => false, 'margin_pct' => Dec::money($project->budget_margin_pct ?? 5)];
        }

        $boq = BigDecimal::zero();

        foreach (BoqItem::where('project_id', $project->id)->get(['qty', 'rate']) as $item) {
            $boq = $boq->plus(Dec::of($item->qty)->multipliedBy(Dec::of($item->rate)));
        }

        return ['amount' => $boq->isPositive() ? Dec::money($boq) : null, 'from_boq' => true, 'margin_pct' => Dec::money($project->budget_margin_pct ?? 5)];
    }

    /** @return array{total: string, lines: list<array{key: string, label: string, amount: string}>} */
    public function spent(Project $project): array
    {
        $total = BigDecimal::zero();
        $lines = [];

        foreach ($this->sources() as $source) {
            $amount = $source->spent($project);
            $total = $total->plus($amount);
            $lines[] = ['key' => $source->key(), 'label' => $source->label(), 'amount' => Dec::money($amount)];
        }

        return ['total' => Dec::money($total), 'lines' => $lines];
    }

    /** Spend divided by the share of work done: what the project will cost if the rest goes the same way. Null before any work. */
    public function projectedFinalCost(string $spent, string $workPercent): ?string
    {
        $work = Dec::of($workPercent);

        if (! $work->isPositive()) {
            return null;
        }

        return Dec::money(Dec::of($spent)->multipliedBy(100)->dividedBy($work, 6, RoundingMode::HalfUp));
    }
}
