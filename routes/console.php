<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('company-data:housekeeping')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('planning:delay-alerts')->dailyAt('07:00')->timezone('Asia/Kolkata');
Schedule::command('agents:daily-briefs')->dailyAt('08:00')->timezone('Asia/Kolkata');
// Reports module: email the saved report schedules whose time has come. Sends nothing unless email is set up.
Schedule::command('reports:send-scheduled')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
// CRM module: follow-up reminders and tender deadline warnings go to the in-app bell.
Schedule::command('crm:send-reminders')->everyTenMinutes()->withoutOverlapping();
