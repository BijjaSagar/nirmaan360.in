<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// CRM module: follow-up reminders and tender deadline warnings go to the in-app bell.
Schedule::command('crm:send-reminders')->everyTenMinutes()->withoutOverlapping();
