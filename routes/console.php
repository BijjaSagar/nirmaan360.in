<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reports module: email the saved report schedules whose time has come. Sends nothing unless email is set up.
Schedule::command('reports:send-scheduled')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
