<?php

use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\MeasurementApiController;
use App\Http\Controllers\Api\ProjectApiController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthApiController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'active', 'throttle:120,1'])->group(function () {
        Route::post('/auth/logout', [AuthApiController::class, 'logout']);
        Route::get('/me', [AuthApiController::class, 'me']);

        Route::get('/projects', [ProjectApiController::class, 'index'])->middleware('can:project.view');
        Route::get('/projects/{project}/boq', [ProjectApiController::class, 'boq'])->middleware('can:boq.view');

        Route::post('/projects/{project}/measurements', [MeasurementApiController::class, 'store'])->middleware('can:measurement.create');
        Route::patch('/measurements/{measurement}', [MeasurementApiController::class, 'update'])->middleware('can:measurement.create');
        Route::post('/measurements/{measurement}/submit', [MeasurementApiController::class, 'submit'])->middleware('can:measurement.create');

        Route::post('/sync/push', [SyncController::class, 'push']);
        Route::get('/sync/pull', [SyncController::class, 'pull']);
    });
});
