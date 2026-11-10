<?php

use App\Http\Controllers\ContractorControl\Api\ContractorControlApiController;
use App\Http\Controllers\ContractorControl\Api\TenderApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:site_control')->group(function () {
    // Work % for anyone with contractor.view. The presenter leaves out everything else without money.view.
    Route::get('/projects/{project}/contractor-control', [ContractorControlApiController::class, 'board'])->middleware('can:contractor.view');
    Route::get('/work-orders/{workOrder}/contractor-control', [ContractorControlApiController::class, 'contract'])->middleware('can:contractor.view');

    Route::get('/projects/{project}/contractor-payments', [ContractorControlApiController::class, 'payments'])->middleware('can:contractor.view');
    Route::post('/work-orders/{workOrder}/contractor-payments', [ContractorControlApiController::class, 'requestPayment'])->middleware('can:contractor.request');
    Route::get('/contractor-payments/{payment}', [ContractorControlApiController::class, 'showPayment'])->middleware('can:contractor.view');
    Route::get('/contractor-payments/{payment}/bill', [ContractorControlApiController::class, 'bill'])->middleware('can:money.view');

    // The tenders a BOQ line is in, and one tender's quotes side by side. The presenter leaves every price out without money.view.
    Route::get('/boq-items/{item}/tenders', [ContractorControlApiController::class, 'lineTenders'])->middleware(['can:boq.view', 'can:contractor.view'])->name('api.boq.tenders.show');
    Route::get('/tenders/{tender}', [TenderApiController::class, 'show'])->middleware(['can:boq.view', 'can:contractor.view'])->name('api.contractor-control.tenders.show');

    // Tendering from the app takes the web's permissions for each step. Quoting means typing rates, so it needs money.view.
    Route::middleware('can:money.view')->group(function () {
        Route::post('/boq-items/{item}/tenders', [TenderApiController::class, 'storeForLine'])->middleware('can:contractor.tender')->name('api.boq.tenders.store');
        Route::get('/contractors', [TenderApiController::class, 'contractors'])->middleware('can:contractor.tender')->name('api.contractor-control.contractors.index');
        Route::post('/contractors', [TenderApiController::class, 'storeContractor'])->middleware('can:contractor.tender')->name('api.contractor-control.contractors.store');
        Route::post('/tenders/{tender}/bids', [TenderApiController::class, 'storeBid'])->middleware('can:contractor.tender')->name('api.contractor-control.tenders.bids.store');
        Route::get('/bids/{bid}/quotation', [TenderApiController::class, 'quotation'])->middleware('can:contractor.view')->name('api.contractor-control.tenders.bids.quotation');
        Route::post('/tenders/{tender}/award', [TenderApiController::class, 'award'])->middleware('can:contractor.award')->name('api.contractor-control.tenders.award');
    });
});
