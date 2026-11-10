<?php

namespace App\Console\Commands;

use App\Services\Notify\AnnouncementService;
use Illuminate\Console\Command;

class SendDueAnnouncements extends Command
{
    protected $signature = 'announcements:send-due';

    protected $description = 'Start the scheduled announcements and reminders whose time has come';

    public function handle(AnnouncementService $announcements): int
    {
        $started = $announcements->dispatchDue();

        $this->info("Started {$started} announcement runs.");

        return self::SUCCESS;
    }
}
