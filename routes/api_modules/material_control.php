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

    // Answering the check, bills and later shortages from site, with the web's permissions.
    Route::post('/projects/{project}/material-control/reasons', [MaterialControlApiController::class, 'storeReason'])->middleware('can:material_control.reason');
    Route::get('/projects/{project}/material-control/bills', [MaterialControlApiController::class, 'bills'])->middleware('can:material_control.bills');
    Route::get('/delivery-challans/{deliveryChallan}', [MaterialControlApiController::class, 'showChallan'])->middleware('can:material_control.view');
    Route::get('/delivery-challans/{deliveryChallan}/bill', [MaterialControlApiController::class, 'bill'])->middleware(['can:material_control.view', 'can:money.view']);
    Route::post('/delivery-challans/{deliveryChallan}/bill', [MaterialControlApiController::class, 'attachBill'])->middleware('can:material_control.bills');
    Route::post('/delivery-challans/{deliveryChallan}/short', [MaterialControlApiController::class, 'storeShort'])->middleware('can:material_control.challan');
});
