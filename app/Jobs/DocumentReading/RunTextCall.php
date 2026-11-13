<?php

namespace App\Jobs\DocumentReading;

use App\Services\DocumentReading\TextCallRunner;
use App\Support\PlatformSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one text call to the model: the free sentences of a letter, a ranking, a grouping.
 *
 * A call can take longer than the queue's retry window, so the queue may hand this job to a second
 * worker while the first is still inside the call. That is why it may be tried more than once: the
 * second worker finds the call already claimed and stops at once. Only the worker that claimed a
 * call can write or charge it.
 */
class RunTextCall implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Longer than the longest text call (90 seconds) and its connection time, so a call is never cut off by the worker. */
    public int $timeout = 110;

    public function __construct(public int $callId) {}

    public function handle(): void
    {
        // A worker outlives many requests, so it must see a key or a limit saved since it started.
        app()->forgetInstance(PlatformSettings::class);

        app(TextCallRunner::class)->run($this->callId);
    }

    /**
     * The call is deliberately left alone here. Another worker may hold it, and a call that really
     * was lost is found by tending, which ends it as failed and uncharged.
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }
}
