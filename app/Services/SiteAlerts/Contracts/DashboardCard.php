<?php

namespace App\Services\SiteAlerts\Contracts;

use App\Models\Project;
use App\Models\User;
use App\Services\SiteAlerts\Card;
use Carbon\CarbonInterface;

/**
 * A card on the project's site dashboard, for the web page and the mobile API alike. List the
 * class under dashboard_cards in your module's config/modules/<name>.php.
 *
 * Money rule: rates, amounts and payments only when $viewer->can('money.view'); budget and total
 * spend only when $viewer->can('money.overview'). Leave the figure out, or the whole card, for
 * anyone else. Work percentages come from App\Services\SiteControl\WorkProgress.
 */
interface DashboardCard
{
    /**
     * Zero or more cards for this project and viewer. Yield nothing when there is nothing to say.
     *
     * @return iterable<Card>
     */
    public function cards(Project $project, User $viewer, CarbonInterface $today): iterable;
}
