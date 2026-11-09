<?php

namespace App\Jobs;

use App\Services\Agents\AgentOrchestrator;
use App\Support\PlatformSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** One pass of an agent's loop. Never retried: a repeat could call the model and charge credits twice. */
class RunAgentStep implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 85;

    public function __construct(public int $runId) {}

    public function handle(): void
    {
        // A worker outlives many requests, so it must see a key saved or removed since it started.
        app()->forgetInstance(PlatformSettings::class);

        app(AgentOrchestrator::class)->advance($this->runId);
    }

    public function failed(?Throwable $exception): void
    {
        report($exception);

        app(AgentOrchestrator::class)->markCrashed($this->runId, 'Something went wrong while running this agent.');
    }
}
