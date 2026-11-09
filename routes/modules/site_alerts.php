<?php

use App\Http\Controllers\SiteAlerts\AlertController;
use App\Http\Controllers\SiteAlerts\SiteDashboardController;
use Illuminate\Support\Facades\Route;

// Site dashboard and alerts (site_control feature). Already inside the signed-in, active-user group. See config/modules/site_alerts.php.
Route::middleware('feature:site_control')->group(function () {
    Route::get('/projects/{project}/site-dashboard', [SiteDashboardController::class, 'show'])->middleware('can:progress.view')->name('site-dashboard.show');

    Route::get('/alerts', [AlertController::class, 'company'])->middleware('can:alerts.view')->name('alerts.company');
    Route::get('/projects/{project}/alerts', [AlertController::class, 'index'])->middleware('can:alerts.view')->name('alerts.index');
    Route::post('/projects/{project}/alerts/scan', [AlertController::class, 'scan'])->middleware(['can:alerts.manage', 'throttle:10,1'])->name('alerts.scan');
});
