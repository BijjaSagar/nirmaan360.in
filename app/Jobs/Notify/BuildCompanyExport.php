<?php

namespace App\Jobs\Notify;

use App\Services\Notify\CompanyExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildCompanyExport implements ShouldQueue
{
    use Queueable;

    /** A long export outlasts the queue's retry window. The extra attempts find it claimed and stop at once. */
    public int $tries = 3;

    public int $timeout = 3600;

    public function __construct(public int $exportId) {}

    public function handle(CompanyExportService $exports): void
    {
        $exports->build($this->exportId);
    }
}
