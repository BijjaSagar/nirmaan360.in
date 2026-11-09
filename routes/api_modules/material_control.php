<?php

use App\Http\Controllers\MaterialControl\Api\MaterialControlApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:site_control')->group(function () {
    Route::get('/material-control/suppliers', [MaterialControlApiController::class, 'suppliers'])->middleware('can:material_control.view');
    Route::get('/projects/{project}/material-control/check', [MaterialControlApiController::class, 'check'])->middleware('can:material_control.view');

    Route::get('/projects/{project}/material-control/challans', [MaterialControlApiController::class, 'challanIndex'])->middleware('can:material_control.view');
    Route::post('/projects/{project}/material-control/challans', [MaterialControlApiController::class, 'storeChallan'])->middleware('can:material_control.challan');
    Route::get('/delivery-challans/{deliveryChallan}/photo', [MaterialControlApiController::class, 'photo'])->middleware('can:material_control.view');

    Route::get('/projects/{project}/material-control/consumption', [MaterialControlApiController::class, 'consumptionIndex'])->middleware('can:material_control.view');
    Route::post('/projects/{project}/material-control/consumption', [MaterialControlApiController::class, 'storeConsumption'])->middleware('can:material_control.consume');

    Route::get('/projects/{project}/material-control/stock-checks', [MaterialControlApiController::class, 'stockCheckIndex'])->middleware('can:material_control.view');
    Route::post('/projects/{project}/material-control/stock-checks', [MaterialControlApiController::class, 'storeStockCheck'])->middleware('can:material_control.count');
});
