<?php

namespace App\Console\Commands;

use App\Services\Crm\ReminderService;
use Illuminate\Console\Command;

class CrmSendReminders extends Command
{
    protected $signature = 'crm:send-reminders';

    protected $description = 'Send due follow-up reminders and tender deadline warnings to the bell';

    public function handle(ReminderService $reminders): int
    {
        $sent = $reminders->run();

        $this->info("Sent {$sent['tasks']} task reminders and {$sent['tenders']} tender deadline warnings.");

        return self::SUCCESS;
    }
}
