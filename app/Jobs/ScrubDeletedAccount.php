<?php

namespace App\Jobs;

use App\Services\Access\AccountDeletionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Clears a deleted person's agent questions, answers and bell once more, after every agent step that was
 * already running when they deleted their account has ended. Safe to repeat, so it may be retried.
 */
class ScrubDeletedAccount implements ShouldQueue
{
    use Queueable;

    /** Longer than RunAgentStep and ResolveAgentAction may run, so any step in flight at the deletion is over. */
    public const DELAY_SECONDS = 120;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $organizationId, public int $userId) {}

    public function handle(AccountDeletionService $deletions): void
    {
        $deletions->scrubLateWrites($this->organizationId, $this->userId);
    }
}
