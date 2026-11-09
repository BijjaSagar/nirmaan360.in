<?php

use App\Http\Controllers\Reports\BoqExcelController;
use App\Http\Controllers\Reports\DprShareController;
use App\Http\Controllers\Reports\MeasurementSheetController;
use App\Http\Controllers\Reports\RateLibraryController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Reports\ReportScheduleController;
use Illuminate\Support\Facades\Route;

// Reports module. This file is included inside the signed-in, active-user group of routes/web.php.

Route::middleware('feature:reports')->group(function () {
    // BOQ in Excel, next to the CSV import and export.
    Route::get('/projects/{project}/boq/excel', [BoqExcelController::class, 'index'])->middleware('can:boq.view')->name('boq.excel.index');
    Route::get('/projects/{project}/boq/export.xlsx', [BoqExcelController::class, 'export'])->middleware('can:boq.view')->name('boq.excel.export');
    Route::get('/boq-template.xlsx', [BoqExcelController::class, 'template'])->middleware('can:boq.view')->name('boq.excel.template');
    Route::post('/projects/{project}/boq/import-excel', [BoqExcelController::class, 'import'])->middleware(['can:boq.manage', 'throttle:20,1'])->name('boq.excel.import');

    // Company rate library.
    Route::get('/rate-library', [RateLibraryController::class, 'index'])->middleware('can:rate.view')->name('ratelib.index');
    Route::get('/rate-library/search', [RateLibraryController::class, 'search'])->middleware(['can:rate.view', 'throttle:120,1'])->name('ratelib.search');
    Route::post('/rate-library', [RateLibraryController::class, 'store'])->middleware('can:rate.manage')->name('ratelib.store');
    Route::patch('/rate-library/{rate}', [RateLibraryController::class, 'update'])->middleware('can:rate.manage')->name('ratelib.update');
    Route::delete('/rate-library/{rate}', [RateLibraryController::class, 'destroy'])->middleware('can:rate.manage')->name('ratelib.destroy');

    // DPR as a PDF. The signed link below is what gets shared on WhatsApp.
    Route::get('/dprs/{dpr}/pdf', [DprShareController::class, 'pdf'])->middleware('can:dpr.view')->name('dprs.pdf');

    // Measurement sheet for a project and period.
    Route::get('/projects/{project}/measurement-sheet.pdf', MeasurementSheetController::class)->middleware('can:measurement.view')->name('measurements.sheet');

    // The Reports screen, its exports and the schedules that email them.
    Route::get('/reports', [ReportController::class, 'index'])->middleware('can:report.view')->name('reports.index');
    Route::get('/reports/export.xlsx', [ReportController::class, 'xlsx'])->middleware(['can:report.view', 'throttle:30,1'])->name('reports.xlsx');
    Route::get('/reports/export.pdf', [ReportController::class, 'pdf'])->middleware(['can:report.view', 'throttle:30,1'])->name('reports.pdf');
    Route::get('/reports/schedules', [ReportScheduleController::class, 'index'])->middleware('can:report.view')->name('reports.schedules.index');
    Route::post('/reports/schedules', [ReportScheduleController::class, 'store'])->middleware('can:report.manage')->name('reports.schedules.store');
    Route::patch('/reports/schedules/{schedule}', [ReportScheduleController::class, 'toggle'])->middleware('can:report.manage')->name('reports.schedules.toggle');
    Route::delete('/reports/schedules/{schedule}', [ReportScheduleController::class, 'destroy'])->middleware('can:report.manage')->name('reports.schedules.destroy');
});

// A shared DPR is opened by someone with no account, so this one leaves the sign-in group.
// The signature is the proof, and the controller checks the company's plan itself.
Route::get('/shared/dprs/{id}', [DprShareController::class, 'shared'])
    ->whereNumber('id')
    ->withoutMiddleware(['auth', 'active'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('dprs.shared');
