<?php

use App\Http\Controllers\PriceWatch\Api\PriceWatchApiController;
use Illuminate\Support\Facades\Route;

// Read-only mobile API for price watch. Already inside /api/v1 with Sanctum. Rates need money.view, the budget impact money.overview.
Route::middleware('feature:price_watch')->group(function () {
    Route::get('/price-watch', [PriceWatchApiController::class, 'board'])->middleware('can:money.view');
    Route::get('/price-watch/materials/{watchMaterial}', [PriceWatchApiController::class, 'material'])->middleware('can:money.view');
    Route::get('/projects/{project}/price-watch', [PriceWatchApiController::class, 'project'])->middleware('can:money.overview');
});
