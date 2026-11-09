<?php

namespace App\Services\PriceWatch\Cards;

use App\Models\Project;
use App\Models\User;
use App\Services\PriceWatch\ProjectPriceImpact;
use App\Services\SiteAlerts\Card;
use App\Services\SiteAlerts\Contracts\DashboardCard;
use App\Support\Dec;
use App\Support\Figure;
use Carbon\CarbonInterface;

/**
 * Material prices against the BOQ on the site dashboard, for money.overview only. Site staff
 * never get this card, not even an empty one. Nothing shows until at least one price is on record.
 */
class PriceCard implements DashboardCard
{
    public function __construct(private ProjectPriceImpact $impact) {}

    public function cards(Project $project, User $viewer, CarbonInterface $today): iterable
    {
        if (! $viewer->can('money.overview') || ! $project->organization?->allowsFeature('price_watch')) {
            return;
        }

        $rows = $this->impact->rows($project, $today);

        if ($rows->whereNotNull('current')->isEmpty()) {
            return;
        }

        $summary = $this->impact->summary($project, $rows);
        $impact = Dec::of($summary['impact']);

        $tone = match (true) {
            $summary['beyond_margin'] => Card::BAD,
            $impact->isPositive() && $summary['fresh_impact_pct'] !== null && Dec::of($summary['fresh_impact_pct'])->isGreaterThanOrEqualTo(Dec::of($summary['impact_alert_pct'])) => Card::WARN,
            default => Card::OK,
        };

        $note = $summary['impact_pct'] === null
            ? __(':priced of :total materials priced', ['priced' => $summary['priced'], 'total' => $summary['materials']])
            : __(':pct of the :basis', ['pct' => $this->signed($summary['impact_pct']).'%', 'basis' => $summary['basis'] === 'budget' ? __('approved budget') : __('BOQ value')]);

        if ($summary['stale'] > 0) {
            $note .= ' · '.__(':count prices out of date', ['count' => $summary['stale']]);
        }

        $lines = $rows->filter(fn (array $row) => $row['impact'] !== null || $row['above_boq_pct'] !== null)
            ->sortByDesc(fn (array $row) => Dec::of($row['impact'] ?? 0)->abs()->toFloat())
            ->take(3)
            ->map(fn (array $row) => [
                'label' => $row['name'],
                'value' => $row['impact'] === null ? '—' : $this->signed($row['impact'], true),
                'sub' => $row['above_boq_pct'] === null ? __('No BOQ rate') : __(':pct against the BOQ rate', ['pct' => $this->signed($row['above_boq_pct']).'%']),
                'tone' => $row['stale'] ? Card::WARN : ($row['impact'] !== null && Dec::of($row['impact'])->isPositive() ? Card::BAD : Card::OK),
            ])->values()->all();

        yield new Card(
            key: 'price_watch',
            title: __('Material prices against the BOQ'),
            order: 35,
            value: $this->signed($summary['impact'], true),
            note: $note,
            tone: $tone,
            rows: $lines,
            href: route('price-watch.project', $project),
            money: true,
        );
    }

    private function signed(string $value, bool $money = false): string
    {
        $number = Dec::of($value);
        $text = $money ? inr($number->abs()) : Figure::trim($number->abs());

        return ($number->isNegative() ? '-' : ($number->isPositive() ? '+' : '')).$text;
    }
}
