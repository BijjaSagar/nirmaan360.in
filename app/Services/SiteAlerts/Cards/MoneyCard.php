<?php

namespace App\Services\SiteAlerts\Cards;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectSetup\BoqBudget;
use App\Services\SiteAlerts\Card;
use App\Services\SiteAlerts\Contracts\DashboardCard;
use App\Services\SiteAlerts\Spend;
use App\Services\SiteControl\WorkProgress;
use App\Support\Dec;
use App\Support\Figure;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;

/**
 * Money spent against budget, only for people with money.overview. Site staff never get this
 * card, not even an empty one, so the page carries no trace of the figures. The BOQ against the
 * budget, at BOQ rates and at the latest rates, comes from project_setup's BoqBudget.
 */
class MoneyCard implements DashboardCard
{
    public function __construct(private Spend $spend, private WorkProgress $progress, private BoqBudget $boqBudget) {}

    public function cards(Project $project, User $viewer, CarbonInterface $today): iterable
    {
        if (! $viewer->can('money.overview')) {
            return;
        }

        $budget = $this->spend->budget($project);
        $spent = $this->spend->spent($project);
        $work = $this->progress->projectPercent($project);
        $projected = $this->spend->projectedFinalCost($spent['total'], $work);
        $summary = $this->boqBudget->summary($project);

        $rows = array_map(fn (array $line) => ['label' => __($line['label']), 'value' => inr($line['amount'])], $spent['lines']);
        $tone = Card::OK;
        $meters = [];
        $note = __('No budget set. Add one on the project, or enter the BOQ.');

        if ($budget['amount'] !== null) {
            $limit = Dec::of($budget['amount'])->multipliedBy(Dec::of(100)->plus(Dec::of($budget['margin_pct'])))->dividedBy(100, 2, RoundingMode::HalfUp);
            $usedPct = $this->progress->percent($spent['total'], $budget['amount']);

            $tone = match (true) {
                Dec::of($spent['total'])->isGreaterThan($limit) => Card::BAD,
                $projected !== null && Dec::of($projected)->isGreaterThan($limit) => Card::WARN,
                default => Card::OK,
            };

            $note = __(':pct% of the :budget budget, margin :margin%', ['pct' => Figure::trim($usedPct), 'budget' => inr($budget['amount']), 'margin' => Figure::trim($budget['margin_pct'])]);

            if ($budget['from_boq']) {
                $note .= ' · '.__('Budget taken from the BOQ value');
            }

            $meters[] = ['label' => __('Budget used'), 'percent' => $usedPct, 'tone' => $tone];

            if ($projected !== null) {
                $variance = Dec::of($projected)->minus(Dec::of($budget['amount']));
                $rows[] = [
                    'label' => __('Projected at finish'),
                    'value' => inr($projected),
                    'sub' => $this->signed($this->progress->percent($variance, $budget['amount'])).' '.__('against budget'),
                    'tone' => Dec::of($projected)->isGreaterThan($limit) ? Card::BAD : ($variance->isPositive() ? Card::WARN : Card::OK),
                ];
            }
        }

        $rows[] = [
            'label' => __('BOQ value'),
            'value' => inr($summary['boq']),
            'sub' => $summary['variance_pct'] === null ? null : $this->signed($summary['variance_pct']).' '.__('against budget'),
            'tone' => $this->budgetTone($summary['status']),
        ];

        if ($summary['priced_lines'] > 0) {
            $rows[] = [
                'label' => __('BOQ at the latest rates'),
                'value' => inr($summary['projected']),
                'sub' => __(':priced of :total lines priced', ['priced' => $summary['priced_lines'], 'total' => $summary['line_count']])
                    .($summary['projected_variance_pct'] === null ? '' : ' · '.$this->signed($summary['projected_variance_pct']).' '.__('against budget')),
                'tone' => $this->budgetTone($summary['projected_status']),
            ];
        }

        yield new Card(
            key: 'money',
            title: __('Money spent against budget'),
            order: 30,
            value: inr($spent['total']),
            note: $note,
            tone: $tone,
            meters: $meters,
            rows: $rows,
            money: true,
        );
    }

    /** +4%, -7% or 0%. */
    private function signed(string $percent): string
    {
        $number = Dec::of($percent);

        return ($number->isPositive() ? '+' : '').Figure::trim($percent).'%';
    }

    private function budgetTone(?string $status): ?string
    {
        return match ($status) {
            'within' => Card::OK,
            'margin' => Card::WARN,
            'over' => Card::BAD,
            default => null,
        };
    }
}
