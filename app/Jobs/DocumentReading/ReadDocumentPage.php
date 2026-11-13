<?php

namespace App\Jobs\DocumentReading;

use App\Services\DocumentReading\PageReader;
use App\Support\PlatformSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one page of a document read to the reader.
 *
 * A call can take longer than the queue's retry window, so the queue may hand this job to a second
 * worker while the first is still inside the call. That is why it may be tried more than once: the
 * second worker finds the page already claimed and stops at once. Only the worker that claimed a
 * page can write or charge it.
 */
class ReadDocumentPage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Longer than the longest call (240 seconds) and its connection time, so a call is never cut off by the worker. */
    public int $timeout = 270;

    public function __construct(public int $pageId) {}

    public function handle(): void
    {
        // A worker outlives many requests, so it must see a key or a limit saved since it started.
        app()->forgetInstance(PlatformSettings::class);

        app(PageReader::class)->run($this->pageId);
    }

    /**
     * The page is deliberately left alone here. Another worker may hold it, and a page that really was
     * lost is found by the tending command, which gives it one more try and then fails it uncharged.
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }
}
