<?php

use App\Http\Controllers\SiteAlerts\AlertApiController;
use App\Http\Controllers\SiteAlerts\SiteDashboardApiController;
use Illuminate\Support\Facades\Route;

// Mobile API for the site dashboard and alerts (site_control feature). Already inside /api/v1 with Sanctum.
Route::middleware('feature:site_control')->group(function () {
    Route::get('/projects/{project}/site-dashboard', [SiteDashboardApiController::class, 'show'])->middleware('can:progress.view');

    Route::get('/alerts', [AlertApiController::class, 'company'])->middleware('can:alerts.view');
    Route::get('/projects/{project}/alerts', [AlertApiController::class, 'index'])->middleware('can:alerts.view');
    Route::post('/projects/{project}/alerts/scan', [AlertApiController::class, 'scan'])->middleware(['can:alerts.manage', 'throttle:10,1']);
});
