<?php

namespace Tests\Feature\SiteAlerts\Support;

use App\Models\Project;
use App\Services\SiteAlerts\Contracts\AlertSource;
use Carbon\CarbonInterface;
use RuntimeException;

final class FailingTestSource implements AlertSource
{
    public function types(): array
    {
        return ['test.failing'];
    }

    public function findings(Project $project, CarbonInterface $today): iterable
    {
        throw new RuntimeException('Table missing');
    }
}
