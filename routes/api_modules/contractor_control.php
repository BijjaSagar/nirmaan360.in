<?php

use App\Http\Controllers\ContractorControl\Api\ContractorControlApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:site_control')->group(function () {
    // Work % for anyone with contractor.view. The presenter leaves out everything else without money.view.
    Route::get('/projects/{project}/contractor-control', [ContractorControlApiController::class, 'board'])->middleware('can:contractor.view');
    Route::get('/work-orders/{workOrder}/contractor-control', [ContractorControlApiController::class, 'contract'])->middleware('can:contractor.view');

    Route::get('/projects/{project}/contractor-payments', [ContractorControlApiController::class, 'payments'])->middleware('can:contractor.view');
    Route::post('/work-orders/{workOrder}/contractor-payments', [ContractorControlApiController::class, 'requestPayment'])->middleware('can:contractor.request');
    Route::get('/contractor-payments/{payment}', [ContractorControlApiController::class, 'showPayment'])->middleware('can:contractor.view');
    Route::get('/contractor-payments/{payment}/bill', [ContractorControlApiController::class, 'bill'])->middleware('can:money.view');
});
