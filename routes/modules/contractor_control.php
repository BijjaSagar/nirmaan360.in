<?php

use App\Http\Controllers\ContractorControl\BoardController;
use App\Http\Controllers\ContractorControl\BoqLineTenderController;
use App\Http\Controllers\ContractorControl\ContractController;
use App\Http\Controllers\ContractorControl\LedgerController;
use App\Http\Controllers\ContractorControl\MilestoneController;
use App\Http\Controllers\ContractorControl\PaymentController;
use App\Http\Controllers\ContractorControl\ReportController;
use App\Http\Controllers\ContractorControl\TenderController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:site_control')->prefix('contractor-control')->name('contractor-control.')->group(function () {
    // Work % for anyone with contractor.view. Everything about money also needs money.view, checked in the controller.
    Route::get('/projects/{project}/board', [BoardController::class, 'index'])->middleware('can:contractor.view')->name('board');
    Route::patch('/projects/{project}/settings', [BoardController::class, 'settings'])->middleware('can:contractor.manage')->name('settings');
    Route::get('/projects/{project}/deadlines', [ReportController::class, 'deadlines'])->middleware('can:contractor.view')->name('deadlines');
    Route::get('/work-orders/{workOrder}', [ContractController::class, 'show'])->middleware('can:contractor.view')->name('contracts.show');
    Route::patch('/work-orders/{workOrder}/deadline', [ContractController::class, 'deadline'])->middleware('can:contractor.manage')->name('contracts.deadline');

    Route::post('/work-orders/{workOrder}/milestones', [MilestoneController::class, 'store'])->middleware('can:contractor.manage')->name('milestones.store');
    Route::patch('/milestones/{milestone}/done', [MilestoneController::class, 'toggle'])->middleware('can:contractor.manage')->name('milestones.toggle');
    Route::delete('/milestones/{milestone}', [MilestoneController::class, 'destroy'])->middleware('can:contractor.manage')->name('milestones.destroy');

    Route::get('/projects/{project}/payments', [PaymentController::class, 'index'])->middleware('can:contractor.view')->name('payments.index');
    Route::get('/work-orders/{workOrder}/payments/create', [PaymentController::class, 'create'])->middleware('can:contractor.request')->name('payments.create');
    Route::post('/work-orders/{workOrder}/payments', [PaymentController::class, 'store'])->middleware('can:contractor.request')->name('payments.store');
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])->middleware('can:contractor.view')->name('payments.show');
    Route::get('/payments/{payment}/edit', [PaymentController::class, 'edit'])->middleware('can:contractor.request')->name('payments.edit');
    Route::patch('/payments/{payment}', [PaymentController::class, 'update'])->middleware('can:contractor.request')->name('payments.update');
    Route::post('/payments/{payment}/reverse', [PaymentController::class, 'reverse'])->middleware('can:contractor.approve')->name('payments.reverse');
    Route::get('/payments/{payment}/bill', [PaymentController::class, 'bill'])->middleware('can:money.view')->name('payments.bill');

    Route::middleware('can:money.view')->group(function () {
        Route::get('/projects/{project}/cost-variance', [ReportController::class, 'variance'])->middleware('can:contractor.view')->name('variance');

        Route::get('/projects/{project}/tenders', [TenderController::class, 'index'])->middleware('can:contractor.view')->name('tenders.index');
        Route::get('/projects/{project}/tenders/create', [TenderController::class, 'create'])->middleware('can:contractor.tender')->name('tenders.create');
        Route::post('/projects/{project}/tenders', [TenderController::class, 'store'])->middleware('can:contractor.tender')->name('tenders.store');
        Route::get('/tenders/{tender}', [TenderController::class, 'show'])->middleware('can:contractor.view')->name('tenders.show');
        Route::post('/tenders/{tender}/bids', [TenderController::class, 'addBid'])->middleware('can:contractor.tender')->name('tenders.bids.store');
        Route::get('/bids/{bid}/quotation', [TenderController::class, 'quotation'])->middleware('can:contractor.view')->name('tenders.bids.quotation');
        Route::post('/tenders/{tender}/award', [TenderController::class, 'award'])->middleware('can:contractor.award')->name('tenders.award');
        Route::post('/tenders/{tender}/cancel', [TenderController::class, 'cancel'])->middleware('can:contractor.tender')->name('tenders.cancel');

        Route::get('/contractors', [LedgerController::class, 'index'])->middleware('can:contractor.view')->name('contractors.index');
        Route::post('/contractors', [TenderController::class, 'storeContractor'])->middleware('can:contractor.tender')->name('contractors.store');
        Route::get('/contractors/{party}', [LedgerController::class, 'show'])->middleware('can:contractor.view')->name('contractors.show');
        Route::get('/contractors/{party}/ledger.pdf', [LedgerController::class, 'pdf'])->middleware('can:contractor.view')->name('contractors.pdf');
    });
});

// Opened from a line on the BOQ page, so these sit beside the other /boq-items addresses and keep the BOQ menu item lit.
Route::middleware('feature:site_control')->group(function () {
    // Who quoted, for anyone with contractor.view. The presenter leaves every price out without money.view.
    Route::get('/boq-items/{item}/tenders', [BoqLineTenderController::class, 'show'])->middleware(['can:boq.view', 'can:contractor.view'])->name('boq.tenders.show');
    Route::post('/boq-items/{item}/tenders', [BoqLineTenderController::class, 'store'])->middleware(['can:money.view', 'can:contractor.tender'])->name('boq.tenders.store');
});
