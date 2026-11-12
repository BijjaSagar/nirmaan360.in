<?php

namespace App\Console\Commands;

use App\Services\DocumentReading\ReadTending;
use App\Support\PlatformSettings;
use Illuminate\Console\Command;

class TendDocumentReads extends Command
{
    protected $signature = 'document-reads:tend';

    protected $description = 'Move document reads along, recover pages a worker lost, close reads nobody came back to, and delete old page files';

    public function handle(): int
    {
        // The scheduler can be one long-lived process, so it must see settings saved since it started.
        app()->forgetInstance(PlatformSettings::class);

        $done = app(ReadTending::class)->all();

        $this->info("Tended {$done['reads']} reads, deleted the files of {$done['purged']}, and cleared {$done['retained']} other old records.");

        return self::SUCCESS;
    }
}
