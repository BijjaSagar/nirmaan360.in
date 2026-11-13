<?php

use App\Http\Controllers\BoqScan\BoqScanApiController;
use App\Http\Middleware\RequireAiReader;
use Illuminate\Support\Facades\Route;

// Scanned BOQ reader for the field app. The same gates as on the web. Uploading pages, the price,
// starting and the status are the shared read routes; checking and confirming the lines is on the web.

Route::middleware(['feature:reports', 'feature:ai_agents', 'can:boq.manage', 'can:money.view'])->name('api.boq-scans.')->group(function () {
    Route::post('/projects/{project}/boq-scans', [BoqScanApiController::class, 'store'])
        ->middleware(['can:agents.use', RequireAiReader::class.':consent', 'throttle:10,1,read-create'])->name('store');
    Route::get('/boq-scans/{read}', [BoqScanApiController::class, 'show'])->name('show');
});
