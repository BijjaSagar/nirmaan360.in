<?php

use App\Http\Controllers\ContractTerms\ContractTermController;
use Illuminate\Support\Facades\Route;

// Dated contract terms. This file is included inside the signed-in, active-user group of routes/web.php.
// A term can carry an amount from the agreement, so every route here also needs money.view.

Route::middleware(['feature:billing', 'can:money.view'])->name('contract-terms.')->group(function () {
    Route::get('/projects/{project}/contract-terms', [ContractTermController::class, 'index'])->middleware('can:contract_terms.view')->name('index');

    Route::middleware('can:contract_terms.manage')->group(function () {
        Route::post('/projects/{project}/contract-terms', [ContractTermController::class, 'store'])->name('store');
        Route::patch('/contract-terms/{term}', [ContractTermController::class, 'update'])->name('update');
        Route::delete('/contract-terms/{term}', [ContractTermController::class, 'destroy'])->name('destroy');
        Route::post('/contract-terms/{term}/confirm', [ContractTermController::class, 'confirm'])->name('confirm');
    });
});
