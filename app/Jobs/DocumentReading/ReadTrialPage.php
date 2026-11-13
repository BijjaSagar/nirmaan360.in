<?php

namespace App\Jobs\DocumentReading;

use App\Services\DocumentReading\TrialRunner;
use App\Support\PlatformSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one page of the platform admin's dry run to the model.
 *
 * Like a company's page job it may be handed to a second worker while the first is still inside
 * the call. The second finds the page claimed and stops at once. The job carries the page's id and
 * nothing else: no picture, no text.
 */
class ReadTrialPage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Longer than the longest call (240 seconds) and its connection time, so a call is never cut off by the worker. */
    public int $timeout = 270;

    public function __construct(public int $pageId) {}

    public function handle(): void
    {
        // A worker outlives many requests, so it must see a key or an allowance saved since it started.
        app()->forgetInstance(PlatformSettings::class);

        app(TrialRunner::class)->run($this->pageId);
    }

    /** The page is left alone here, as for a company's page: another worker may hold it, and a lost page is found by tending. */
    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }
}
