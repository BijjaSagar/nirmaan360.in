<?php

namespace App\Services\SiteAlerts\Sources;

use App\Models\Alert;
use App\Models\Project;
use App\Services\ProjectSetup\BoqBudget;
use App\Services\SiteAlerts\Contracts\AlertSource;
use App\Services\SiteAlerts\Finding;
use App\Support\Figure;
use Carbon\CarbonInterface;

/**
 * The budget heading beyond its margin. project_setup's BoqBudget compares the BOQ with the
 * approved budget and its margin, at BOQ rates and again at the latest rates people have typed
 * in. Everything here is money, so the alerts need money.overview.
 */
class BudgetSource implements AlertSource
{
    public const OVER = 'budget.over';

    public const HEADING_OVER = 'budget.heading_over';

    public function __construct(private BoqBudget $budget) {}

    public function types(): array
    {
        return [self::OVER, self::HEADING_OVER];
    }

    public function findings(Project $project, CarbonInterface $today): iterable
    {
        $summary = $this->budget->summary($project);

        if ($summary['budget'] === null) {
            return;
        }

        $params = ['budget' => inr($summary['budget']), 'margin' => Figure::trim($summary['margin_pct'])];
        $data = ['budget' => $summary['budget'], 'margin_pct' => $summary['margin_pct'], 'ceiling' => $summary['ceiling'], 'boq' => $summary['boq'], 'variance' => $summary['variance'], 'variance_pct' => $summary['variance_pct']];

        if ($summary['status'] === 'over') {
            yield new Finding(
                type: self::OVER,
                title: 'Budget beyond margin',
                message: 'The BOQ comes to :boq, :pct% over the :budget budget and beyond the :margin% margin.',
                params: [...$params, 'boq' => inr($summary['boq']), 'pct' => Figure::trim($summary['variance_pct'])],
                severity: Alert::CRITICAL,
                data: $data,
                needs: 'money.overview',
                url: route('project-setup.budget', $project),
            );

            return;
        }

        if ($summary['projected_status'] === 'over') {
            yield new Finding(
                type: self::HEADING_OVER,
                title: 'Budget heading beyond margin',
                message: 'At the latest rates the BOQ comes to :projected, :pct% over the :budget budget and beyond the :margin% margin.',
                params: [...$params, 'projected' => inr($summary['projected']), 'pct' => Figure::trim($summary['projected_variance_pct'])],
                severity: Alert::WARNING,
                data: [...$data, 'projected' => $summary['projected'], 'projected_variance' => $summary['projected_variance'], 'projected_variance_pct' => $summary['projected_variance_pct'], 'priced_lines' => $summary['priced_lines']],
                needs: 'money.overview',
                url: route('project-setup.budget', $project),
            );
        }
    }
}
