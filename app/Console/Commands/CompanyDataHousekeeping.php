<?php

namespace App\Console\Commands;

use App\Services\Notify\CompanyDeletionService;
use App\Services\Notify\CompanyExportService;
use Illuminate\Console\Command;

class CompanyDataHousekeeping extends Command
{
    protected $signature = 'company-data:housekeeping';

    protected $description = 'Erase companies whose deletion grace period has ended and remove expired data exports';

    public function handle(CompanyDeletionService $deletions, CompanyExportService $exports): int
    {
        $this->info('Companies erased: '.$deletions->purgeDue());
        $this->info('Expired exports removed: '.$exports->removeExpired());

        return self::SUCCESS;
    }
}
