<?php

use App\Http\Controllers\Assets\MachineLogApiController;
use Illuminate\Support\Facades\Route;

// Mobile API for the daily machine log (field_ops). Already inside /api/v1 with Sanctum.
Route::middleware('feature:assets')->group(function () {
    Route::get('/assets', [MachineLogApiController::class, 'assets'])->middleware('can:asset.view');
    Route::get('/machine-logs', [MachineLogApiController::class, 'index'])->middleware('can:asset.view');
    Route::post('/machine-logs', [MachineLogApiController::class, 'store'])->middleware('can:asset.log');
});
