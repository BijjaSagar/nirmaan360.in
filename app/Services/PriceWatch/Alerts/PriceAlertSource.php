<?php

namespace App\Services\PriceWatch\Alerts;

use App\Models\Alert;
use App\Models\Project;
use App\Services\PriceWatch\PriceWatchSettings;
use App\Services\PriceWatch\ProjectPriceImpact;
use App\Services\SiteAlerts\Contracts\AlertSource;
use App\Services\SiteAlerts\Finding;
use App\Support\Dec;
use App\Support\Figure;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Two alerts from price watch. A material whose current price is above the BOQ rate by the
 * rise limit, or has jumped that much since its previous price, is a rate alert for money.view.
 * The total impact on the budget crossing its limit is a budget alert for money.overview.
 * An out-of-date price raises nothing: nobody should act on a stale figure.
 */
class PriceAlertSource implements AlertSource
{
    public const RISE = 'price.rise';

    public const IMPACT = 'price.budget_impact';

    /** The budget alert's wording, against the approved budget or, without one, the BOQ value. Translated when shown. */
    public const IMPACT_OF_BUDGET = 'Buying the rest of :count materials at today\'s prices costs :impact more than the BOQ allowed, :pct% of the :budget budget.';

    public const IMPACT_OF_BOQ = 'Buying the rest of :count materials at today\'s prices costs :impact more than the BOQ allowed, :pct% of the :boq BOQ value.';

    public function __construct(private ProjectPriceImpact $impact, private PriceWatchSettings $settings) {}

    public function types(): array
    {
        return [self::RISE, self::IMPACT];
    }

    public function findings(Project $project, CarbonInterface $today): iterable
    {
        if (! $project->organization?->allowsFeature('price_watch')) {
            return;
        }

        $rows = $this->impact->rows($project, $today);
        $rise = Dec::of($this->settings->risePct());
        $url = route('price-watch.project', $project);

        foreach ($rows as $row) {
            if ($row['current'] === null || $row['stale']) {
                continue;
            }

            $current = $row['current'];
            $words = [
                'material' => $row['name'],
                'unit' => $row['unit'],
                'current' => inr($current['rate']),
                'when' => Carbon::parse($current['priced_on'])->format('d M Y').($current['source'] ? ', '.$current['source'] : ''),
            ];
            $data = Arr::only($row, ['id', 'key', 'name', 'unit', 'boq_rate', 'above_boq_pct', 'change_pct']) + ['current' => $current, 'previous' => $row['previous']];

            if ($row['above_boq_pct'] !== null && Dec::of($row['above_boq_pct'])->isGreaterThanOrEqualTo($rise)) {
                yield new Finding(
                    type: self::RISE,
                    title: 'Material price above the BOQ rate',
                    message: ':material is :current per :unit as on :when, :pct% above the BOQ rate of :boq.',
                    params: [...$words, 'pct' => Figure::trim($row['above_boq_pct']), 'boq' => inr($row['boq_rate'])],
                    reference: 'price_watch:'.$row['id'],
                    severity: $this->severity($row['above_boq_pct'], $rise),
                    data: $data,
                    needs: 'money.view',
                    url: $url,
                );

                continue;
            }

            // No BOQ rate to compare with, but the project buys this material and its price has just jumped.
            if ($row['boq_rate'] === null && $row['need'] !== null && $row['previous'] !== null && $row['change_pct'] !== null && Dec::of($row['change_pct'])->isGreaterThanOrEqualTo($rise)) {
                yield new Finding(
                    type: self::RISE,
                    title: 'Material price has risen',
                    message: ':material has risen :pct% to :current per :unit as on :when, from :previous.',
                    params: [...$words, 'pct' => Figure::trim($row['change_pct']), 'previous' => inr($row['previous']['rate'])],
                    reference: 'price_watch:'.$row['id'],
                    severity: $this->severity($row['change_pct'], $rise),
                    data: $data,
                    needs: 'money.view',
                    url: $url,
                );
            }
        }

        $summary = $this->impact->summary($project, $rows);

        if ($summary['fresh_impact_pct'] === null || ! Dec::of($summary['fresh_impact'])->isPositive() || Dec::of($summary['fresh_impact_pct'])->isLessThan(Dec::of($summary['impact_alert_pct']))) {
            return;
        }

        $params = ['impact' => inr($summary['fresh_impact']), 'pct' => Figure::trim($summary['fresh_impact_pct']), 'count' => $summary['with_impact']];

        yield new Finding(
            type: self::IMPACT,
            title: 'Material prices are eating into the budget',
            message: $summary['basis'] === 'budget' ? self::IMPACT_OF_BUDGET : self::IMPACT_OF_BOQ,
            params: [...$params, 'budget' => $summary['budget'] === null ? '' : inr($summary['budget']), 'boq' => inr($summary['boq'])],
            severity: $summary['beyond_margin'] ? Alert::CRITICAL : Alert::WARNING,
            data: Arr::only($summary, ['impact', 'fresh_impact', 'fresh_impact_pct', 'basis', 'budget', 'ceiling', 'boq', 'projected', 'with_impact', 'stale']),
            needs: 'money.overview',
            url: $url,
        );
    }

    /** Critical at twice the rise limit. */
    private function severity(string $pct, BigDecimal $rise): string
    {
        return Dec::of($pct)->isGreaterThanOrEqualTo($rise->multipliedBy(2)) ? Alert::CRITICAL : Alert::WARNING;
    }
}
