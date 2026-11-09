<?php

use App\Http\Controllers\Workforce\ProductivityController;
use App\Http\Controllers\Workforce\QrScanController;
use App\Http\Controllers\Workforce\WorkerCardController;
use Illuminate\Support\Facades\Route;

// QR attendance and productivity. Loaded inside the signed-in group in routes/web.php.
Route::middleware('feature:labour')->group(function () {
    Route::get('/workers/cards', [WorkerCardController::class, 'index'])->middleware('can:worker.view')->name('workforce.cards');
    Route::post('/workers/{worker}/card/reissue', [WorkerCardController::class, 'reissue'])->middleware('can:worker.manage')->name('workforce.cards.reissue');

    Route::get('/projects/{project}/attendance/scan', [QrScanController::class, 'show'])->middleware('can:attendance.manage')->name('workforce.scan');
    Route::post('/projects/{project}/attendance/scan', [QrScanController::class, 'store'])->middleware(['can:attendance.manage', 'throttle:120,1'])->name('workforce.scan.store');

    Route::get('/projects/{project}/productivity', [ProductivityController::class, 'index'])->middleware('can:attendance.view')->name('workforce.productivity');
    Route::post('/projects/{project}/productivity/allocations', [ProductivityController::class, 'allocate'])->middleware('can:attendance.manage')->name('workforce.productivity.allocate');
    Route::delete('/labour-allocations/{allocation}', [ProductivityController::class, 'destroy'])->middleware('can:attendance.manage')->name('workforce.productivity.destroy');
});
