<?php

namespace App\Console\Commands;

use App\Services\Notify\CompanyDeletionService;
use App\Services\Notify\CompanyExportService;
use App\Services\Notify\DeviceRegistry;
use Illuminate\Console\Command;

class CompanyDataHousekeeping extends Command
{
    protected $signature = 'company-data:housekeeping';

    protected $description = 'Erase companies whose deletion grace period has ended, remove expired data exports and forget phones that can no longer be pushed to';

    public function handle(CompanyDeletionService $deletions, CompanyExportService $exports, DeviceRegistry $devices): int
    {
        $this->info('Companies erased: '.$deletions->purgeDue());
        $this->info('Expired exports removed: '.$exports->removeExpired());

        $pruned = $devices->prune();
        $this->info('Phones forgotten: '.$pruned['phones'].'. Old push receipts removed: '.$pruned['receipts'].'.');

        return self::SUCCESS;
    }
}
