<?php

namespace App\Jobs;

use App\Models\AgentActionRequest;
use App\Services\Agents\AgentOrchestrator;
use App\Support\PlatformSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Carries out a person's decision on an agent's request and wakes the run. */
class ResolveAgentAction implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 85;

    public function __construct(public int $requestId) {}

    public function handle(): void
    {
        app()->forgetInstance(PlatformSettings::class);

        app(AgentOrchestrator::class)->applyResolution($this->requestId);
    }

    public function failed(?Throwable $exception): void
    {
        report($exception);

        $runId = AgentActionRequest::withoutGlobalScopes()->whereKey($this->requestId)->value('agent_run_id');

        if ($runId) {
            app(AgentOrchestrator::class)->markCrashed($runId, 'Something went wrong after this request was decided.');
        }
    }
}
