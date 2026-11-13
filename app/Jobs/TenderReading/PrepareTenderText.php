<?php

namespace App\Jobs\TenderReading;

use App\Services\TenderReading\TenderTextPreparer;
use App\Support\PlatformSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Takes the text out of a tender PDF for a read. The read is claimed by its status, so a second
 * delivery of the same job does nothing, and a read left half prepared is failed by the tending
 * command after a few minutes.
 */
class PrepareTenderText implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public int $readId) {}

    public function handle(TenderTextPreparer $preparer): void
    {
        // A worker outlives many requests, so it must see a key saved since it started.
        app()->forgetInstance(PlatformSettings::class);

        $preparer->prepare($this->readId);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }
}
