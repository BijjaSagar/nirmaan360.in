<?php

use App\Http\Controllers\SiteProgress\ProgressApiController;
use App\Http\Controllers\SiteProgress\StageApiController;
use Illuminate\Support\Facades\Route;

// Mobile API for site progress and the plumbing and electrical stage matrix. Already inside /api/v1 with Sanctum.
// Quantities and percentages only: nothing here returns a rate, an amount or a budget.
Route::middleware('feature:site_control')->group(function () {
    Route::get('/projects/{project}/floors', [ProgressApiController::class, 'floors'])->middleware('can:progress.view');
    Route::get('/floors/{floor}/progress', [ProgressApiController::class, 'floor'])->middleware('can:progress.view');
    Route::get('/projects/{project}/progress/summary', [ProgressApiController::class, 'summary'])->middleware('can:progress.view');
    Route::get('/projects/{project}/progress/items', [ProgressApiController::class, 'items'])->middleware('can:progress.view');
    Route::get('/projects/{project}/progress/pending', [ProgressApiController::class, 'pending'])->middleware('can:progress.view');

    Route::get('/projects/{project}/progress/entries', [ProgressApiController::class, 'index'])->middleware('can:progress.view');
    Route::post('/projects/{project}/progress/entries', [ProgressApiController::class, 'store'])->middleware('can:progress.create');
    Route::post('/progress/entries/{progressEntry}/corrections', [ProgressApiController::class, 'correct'])->middleware('can:progress.create');
    Route::post('/progress/entries/{progressEntry}/resolve', [ProgressApiController::class, 'resolve'])->middleware('can:progress.create');
    Route::post('/progress/entries/{progressEntry}/photos', [ProgressApiController::class, 'storePhoto'])->middleware('can:progress.create');
    Route::get('/progress/photos/{progressPhoto}', [ProgressApiController::class, 'photo'])->middleware('can:progress.view');

    Route::get('/projects/{project}/stages', [StageApiController::class, 'matrix'])->middleware('can:progress.view');
    Route::post('/projects/{project}/stages/cells', [StageApiController::class, 'update'])->middleware('can:progress.create');
    Route::post('/stage-cells/{cell}/photos', [StageApiController::class, 'storePhoto'])->middleware('can:progress.create');
    Route::get('/floors/{floor}/before-finishing', [StageApiController::class, 'beforeFinishing'])->middleware('can:progress.view');
});
