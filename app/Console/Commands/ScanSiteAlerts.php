<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\SiteAlerts\AlertEngine;
use Illuminate\Console\Command;

class ScanSiteAlerts extends Command
{
    protected $signature = 'site-alerts:scan {--project= : Scan one project by id instead of every live project}';

    protected $description = 'Run every alert source over the live projects: open new alerts, resolve cleared ones and tell the people concerned';

    public function handle(AlertEngine $engine): int
    {
        if ($id = $this->option('project')) {
            $project = Project::withoutGlobalScopes()->whereNull('deleted_at')->find($id);

            if ($project === null) {
                $this->error("No project with id {$id}.");

                return self::FAILURE;
            }

            $result = $engine->scanProject($project);
            $this->info("Project {$project->code}: {$result['open']} open, {$result['new']} new, {$result['resolved']} resolved.");

            return self::SUCCESS;
        }

        $result = $engine->scanAll();
        $this->info("Projects checked: {$result['projects']}. New alerts: {$result['new']}. Resolved: {$result['resolved']}.");

        return self::SUCCESS;
    }
}
