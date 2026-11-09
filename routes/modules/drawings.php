<?php

use App\Http\Controllers\Drawings\BbsController;
use App\Http\Controllers\Drawings\PinController;
use App\Http\Controllers\Drawings\RegisterController;
use App\Http\Controllers\Drawings\SheetController;
use App\Http\Controllers\Drawings\TakeoffController;
use App\Http\Controllers\Drawings\TransmittalController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:drawings')->group(function () {
    Route::middleware('can:drawings.view')->group(function () {
        Route::get('/projects/{project}/drawings', [RegisterController::class, 'index'])->name('drawings.register.index');
        Route::get('/drawings/{drawing}', [RegisterController::class, 'show'])->name('drawings.register.show');
        Route::get('/drawing-versions/{version}/file', [RegisterController::class, 'file'])->name('drawings.register.file');
        Route::get('/drawing-sheets/{sheet}', [SheetController::class, 'show'])->name('drawings.register.sheet');

        Route::get('/projects/{project}/takeoff', [TakeoffController::class, 'index'])->name('drawings.takeoff.index');

        Route::get('/projects/{project}/transmittals', [TransmittalController::class, 'index'])->name('drawings.transmittals.index');
        Route::get('/transmittals/{transmittal}', [TransmittalController::class, 'show'])->name('drawings.transmittals.show');
        Route::get('/transmittals/{transmittal}/pdf', [TransmittalController::class, 'pdf'])->name('drawings.transmittals.pdf');

        Route::get('/projects/{project}/bbs', [BbsController::class, 'index'])->name('drawings.bbs.index');
        Route::get('/bbs/{schedule}', [BbsController::class, 'show'])->name('drawings.bbs.show');
        Route::get('/bbs/{schedule}/export', [BbsController::class, 'export'])->name('drawings.bbs.export');
    });

    Route::middleware('can:drawings.manage')->group(function () {
        Route::post('/projects/{project}/drawing-folders', [RegisterController::class, 'storeFolder'])->name('drawings.register.folders.store');
        Route::patch('/drawing-folders/{folder}', [RegisterController::class, 'updateFolder'])->name('drawings.register.folders.update');
        Route::delete('/drawing-folders/{folder}', [RegisterController::class, 'destroyFolder'])->name('drawings.register.folders.destroy');

        Route::post('/projects/{project}/drawings', [RegisterController::class, 'store'])->name('drawings.register.store');
        Route::patch('/drawings/{drawing}', [RegisterController::class, 'update'])->name('drawings.register.update');
        Route::delete('/drawings/{drawing}', [RegisterController::class, 'destroy'])->name('drawings.register.destroy');
        Route::post('/drawings/{drawing}/versions', [RegisterController::class, 'storeVersion'])->name('drawings.register.versions.store');

        Route::post('/drawing-versions/{version}/sheets', [SheetController::class, 'register'])->name('drawings.register.sheets.store');
        Route::patch('/drawing-sheets/{sheet}', [SheetController::class, 'update'])->name('drawings.register.sheets.update');

        Route::get('/projects/{project}/transmittals/create', [TransmittalController::class, 'create'])->name('drawings.transmittals.create');
        Route::post('/projects/{project}/transmittals', [TransmittalController::class, 'store'])->name('drawings.transmittals.store');
        Route::post('/transmittals/{transmittal}/acknowledge', [TransmittalController::class, 'acknowledge'])->name('drawings.transmittals.acknowledge');
        Route::post('/transmittals/{transmittal}/cancel', [TransmittalController::class, 'cancel'])->name('drawings.transmittals.cancel');
    });

    Route::middleware('can:drawings.takeoff')->group(function () {
        Route::post('/drawing-sheets/{sheet}/calibrate', [SheetController::class, 'calibrate'])->name('drawings.register.sheets.calibrate');

        Route::post('/projects/{project}/takeoff-layers', [TakeoffController::class, 'storeLayer'])->name('drawings.takeoff.layers.store');
        Route::patch('/takeoff-layers/{layer}', [TakeoffController::class, 'updateLayer'])->name('drawings.takeoff.layers.update');
        Route::delete('/takeoff-layers/{layer}', [TakeoffController::class, 'destroyLayer'])->name('drawings.takeoff.layers.destroy');

        Route::post('/drawing-sheets/{sheet}/measurements', [TakeoffController::class, 'store'])->name('drawings.takeoff.measurements.store');
        Route::patch('/takeoff-measurements/{measurement}', [TakeoffController::class, 'update'])->name('drawings.takeoff.measurements.update');
        Route::delete('/takeoff-measurements/{measurement}', [TakeoffController::class, 'destroy'])->name('drawings.takeoff.measurements.destroy');

        Route::post('/boq-items/{item}/apply-takeoff', [TakeoffController::class, 'apply'])->middleware('can:boq.manage')->name('drawings.takeoff.apply');
    });

    // A pin creates or moves an issue, so it needs the issue permission as well.
    Route::middleware(['can:drawings.view', 'can:issue.manage'])->group(function () {
        Route::post('/drawing-sheets/{sheet}/pins', [PinController::class, 'store'])->name('drawings.register.pins.store');
        Route::delete('/drawing-pins/{pin}', [PinController::class, 'destroy'])->name('drawings.register.pins.destroy');
    });

    Route::middleware('can:drawings.bbs')->group(function () {
        Route::post('/projects/{project}/bbs', [BbsController::class, 'store'])->name('drawings.bbs.store');
        Route::patch('/bbs/{schedule}', [BbsController::class, 'update'])->name('drawings.bbs.update');
        Route::delete('/bbs/{schedule}', [BbsController::class, 'destroy'])->name('drawings.bbs.destroy');
        Route::post('/bbs/{schedule}/bars', [BbsController::class, 'storeBar'])->name('drawings.bbs.bars.store');
        Route::patch('/bbs-bars/{bar}', [BbsController::class, 'updateBar'])->name('drawings.bbs.bars.update');
        Route::delete('/bbs-bars/{bar}', [BbsController::class, 'destroyBar'])->name('drawings.bbs.bars.destroy');
    });
});
