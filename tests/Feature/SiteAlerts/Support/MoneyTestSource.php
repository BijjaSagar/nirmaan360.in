<?php

namespace Tests\Feature\SiteAlerts\Support;

use App\Models\Project;
use App\Services\SiteAlerts\Contracts\AlertSource;
use App\Services\SiteAlerts\Finding;
use Carbon\CarbonInterface;

/** A money alert anyone with alerts.view would be told about, were it not for the money permission it needs. */
final class MoneyTestSource implements AlertSource
{
    public function types(): array
    {
        return ['test.money'];
    }

    public function findings(Project $project, CarbonInterface $today): iterable
    {
        yield new Finding(
            type: 'test.money',
            title: 'Test money alert',
            message: 'Projected :projected against a budget of :budget.',
            params: ['projected' => '₹2,08,00,000.00', 'budget' => '₹2,00,00,000.00'],
            needs: 'money.overview',
            notify: 'alerts.view',
        );
    }
}
