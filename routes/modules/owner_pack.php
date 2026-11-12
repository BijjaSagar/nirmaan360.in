<?php

use App\Http\Controllers\OwnerPack\QuotationController;
use App\Http\Controllers\OwnerPack\QuotationShareController;
use App\Http\Controllers\OwnerPack\QuotationStageController;
use App\Http\Controllers\OwnerPack\SharedPackController;
use Illuminate\Support\Facades\Route;

// Home-owner pack. This file is included inside the signed-in, active-user group of routes/web.php.

// A quotation is nothing but money, so every route needs money.view beside the module's own permission.
Route::middleware(['feature:owner_pack', 'can:money.view'])->group(function () {
    Route::get('/projects/{project}/quotations', [QuotationController::class, 'index'])->middleware('can:owner_pack.view')->name('quotations.index');
    Route::get('/projects/{project}/quotations/create', [QuotationController::class, 'create'])->middleware('can:owner_pack.manage')->name('quotations.create');
    Route::post('/projects/{project}/quotations', [QuotationController::class, 'store'])->middleware('can:owner_pack.manage')->name('quotations.store');
    Route::get('/quotations/{quotation}', [QuotationController::class, 'show'])->middleware('can:owner_pack.view')->name('quotations.show');

    Route::middleware('can:owner_pack.manage')->group(function () {
        Route::patch('/quotations/{quotation}', [QuotationController::class, 'update'])->name('quotations.update');
        Route::post('/quotations/{quotation}/issue', [QuotationController::class, 'issue'])->name('quotations.issue');
        Route::post('/quotations/{quotation}/revise', [QuotationController::class, 'revise'])->name('quotations.revise');

        Route::post('/quotations/{quotation}/stages', [QuotationStageController::class, 'store'])->name('quotations.stages.store');
        Route::patch('/quotation-stages/{stage}', [QuotationStageController::class, 'update'])->name('quotation-stages.update');
        Route::delete('/quotation-stages/{stage}', [QuotationStageController::class, 'destroy'])->name('quotation-stages.destroy');
        Route::post('/quotation-stages/{stage}/reached', [QuotationStageController::class, 'reached'])->name('quotation-stages.reached');

        Route::post('/quotations/{quotation}/share', [QuotationShareController::class, 'share'])->name('quotations.share');
        Route::post('/quotations/{quotation}/share/revoke', [QuotationShareController::class, 'revoke'])->name('quotations.share.revoke');
    });
});

// A shared quotation is opened by a client with no account, so this one leaves the sign-in group.
// The signature proves the address was made here. The controller checks the token, the date, the
// withdrawal, the status and the company's plan itself. The throttle has its own counter, so opening
// a shared page never uses up the sign-in tries of that address.
Route::get('/shared/quotations/{id}/{token}', [SharedPackController::class, 'quotation'])
    ->whereNumber('id')
    ->where('token', '[A-Za-z0-9]{40}')
    ->withoutMiddleware(['auth', 'active'])
    ->middleware(['signed', 'throttle:30,1,owner-share'])
    ->name('quotations.shared');
