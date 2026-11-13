<?php

use App\Http\Controllers\Reports\Api\ReportShareApiController;
use Illuminate\Support\Facades\Route;

// The field app's copies of the web's DPR PDF, WhatsApp link and measurement sheet. Already inside /api/v1 with Sanctum.
// Same plan and permissions as routes/modules/reports.php; the shared link itself stays the web's /shared/dprs/{id}.
Route::middleware('feature:reports')->group(function () {
    Route::middleware('feature:site')->group(function () {
        Route::get('/dprs/{dpr}/pdf', [ReportShareApiController::class, 'dprPdf'])->middleware('can:dpr.view')->name('api.reports.dprs.pdf');
        Route::post('/dprs/{dpr}/share-link', [ReportShareApiController::class, 'dprShareLink'])->middleware(['can:dpr.view', 'throttle:30,1,dpr-share'])->name('api.reports.dprs.share-link');
    });

    Route::get('/projects/{project}/measurement-sheet.pdf', [ReportShareApiController::class, 'measurementSheet'])
        ->middleware(['feature:measurement', 'can:measurement.view'])->name('api.reports.measurement-sheet');
});
