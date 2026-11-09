<?php

namespace App\Jobs;

use App\Services\Agents\AgentOrchestrator;
use App\Support\PlatformSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TitleAgentConversation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 40;

    public function __construct(public int $conversationId, public int $runId) {}

    public function handle(): void
    {
        app()->forgetInstance(PlatformSettings::class);

        app(AgentOrchestrator::class)->titleConversation($this->conversationId, $this->runId);
    }
}
