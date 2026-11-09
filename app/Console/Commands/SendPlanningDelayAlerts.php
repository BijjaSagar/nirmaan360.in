<?php

namespace App\Console\Commands;

use App\Services\Planning\DelayAlertService;
use Illuminate\Console\Command;

class SendPlanningDelayAlerts extends Command
{
    protected $signature = 'planning:delay-alerts';

    protected $description = 'Find delayed activities on running projects and notify the people who plan them';

    public function handle(DelayAlertService $alerts): int
    {
        $result = $alerts->scanAll();

        $this->info("Projects checked: {$result['projects']}. New delays: {$result['new']}. Cleared: {$result['resolved']}.");

        return self::SUCCESS;
    }
}
