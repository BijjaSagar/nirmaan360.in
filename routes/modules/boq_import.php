<?php

use App\Http\Controllers\BoqImport\BoqSheetImportController;
use Illuminate\Support\Facades\Route;

// A BOQ sheet in a contractor's own layout: read by its headings, checked column by column, written only on
// confirmation. The upload goes through boq.excel.import. Part of the reports module, like the Excel import.
// This file is included inside the signed-in, active-user group of routes/web.php.

Route::middleware('feature:reports')->group(function () {
    Route::get('/projects/{project}/boq/import-sheet/preview', [BoqSheetImportController::class, 'preview'])->middleware('can:boq.manage')->name('boq.sheet.preview');
    Route::post('/projects/{project}/boq/import-sheet/columns', [BoqSheetImportController::class, 'choose'])->middleware(['can:boq.manage', 'throttle:60,1'])->name('boq.sheet.choose');
    Route::post('/projects/{project}/boq/import-sheet/confirm', [BoqSheetImportController::class, 'confirm'])->middleware(['can:boq.manage', 'throttle:20,1'])->name('boq.sheet.confirm');
    Route::post('/projects/{project}/boq/import-sheet/discard', [BoqSheetImportController::class, 'discard'])->middleware(['can:boq.manage', 'throttle:20,1'])->name('boq.sheet.discard');
    Route::delete('/boq-import-layouts/{layout}', [BoqSheetImportController::class, 'destroyLayout'])->middleware(['can:boq.manage', 'throttle:30,1'])->name('boq.layouts.destroy');
});
