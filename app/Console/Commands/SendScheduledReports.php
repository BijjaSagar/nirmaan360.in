<?php

namespace App\Console\Commands;

use App\Services\Reports\ReportMailGateway;
use App\Services\Reports\ScheduleService;
use Illuminate\Console\Command;

class SendScheduledReports extends Command
{
    protected $signature = 'reports:send-scheduled';

    protected $description = 'Email the saved reports whose time has come';

    public function handle(ScheduleService $schedules, ReportMailGateway $mail): int
    {
        if (! $mail->configured()) {
            $this->warn('Email is not set up, so no report can be sent. Set it up in the platform admin email settings.');
        }

        $counts = $schedules->runDue();

        $this->info("Sent {$counts['sent']}, waiting for email {$counts['waiting']}, skipped {$counts['skipped']}, failed {$counts['failed']}.");

        return self::SUCCESS;
    }
}
