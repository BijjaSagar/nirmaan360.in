<?php

namespace App\Services\SiteAlerts\Contracts;

use App\Models\Project;
use App\Services\SiteAlerts\Finding;
use Carbon\CarbonInterface;

/**
 * One kind of thing that can go wrong on a project, checked by the alert engine. List the class
 * under alert_sources in your module's config/modules/<name>.php; nothing else is needed.
 *
 * The engine calls findings() for every live project, on the daily schedule and on demand. It opens
 * an alert for each finding it has not seen, refreshes the ones still present, and resolves an open
 * alert of one of your types that you no longer return. So report every cause that holds today,
 * not only new ones, and keep the reference stable for one cause.
 *
 * Runs without a signed-in user from the scheduler: filter by project_id, never rely on the tenant scope.
 */
interface AlertSource
{
    /**
     * Every alert type this source can raise, for example ['material.over_boq']. Only alerts of
     * these types are resolved when they go missing, so a source that is switched off leaves
     * nothing in a wrong state.
     *
     * @return list<string>
     */
    public function types(): array;

    /**
     * The causes present on this project today. Empty when all is well.
     *
     * @return iterable<Finding>
     */
    public function findings(Project $project, CarbonInterface $today): iterable;
}
