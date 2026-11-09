<?php

use App\Http\Controllers\ProjectSetup\BoqBudgetController;
use App\Http\Controllers\ProjectSetup\BoqHistoryController;
use App\Http\Controllers\ProjectSetup\BoqSetupController;
use App\Http\Controllers\ProjectSetup\PermitController;
use Illuminate\Support\Facades\Route;

// Project setup module. This file is included inside the signed-in, active-user group of routes/web.php.

Route::middleware('feature:site_control')->name('project-setup.')->group(function () {
    // Permission checklist.
    Route::get('/projects/{project}/permits', [PermitController::class, 'index'])->middleware('can:permit.view')->name('permits.index');
    Route::get('/permit-files/{file}', [PermitController::class, 'showFile'])->middleware('can:permit.view')->name('permits.files.show');
    Route::middleware('can:permit.manage')->group(function () {
        Route::post('/projects/{project}/permits', [PermitController::class, 'store'])->name('permits.store');
        Route::patch('/permits/{permit}', [PermitController::class, 'update'])->name('permits.update');
        Route::delete('/permits/{permit}', [PermitController::class, 'destroy'])->name('permits.destroy');
        Route::post('/permits/{permit}/files', [PermitController::class, 'storeFile'])->middleware('throttle:30,1')->name('permits.files.store');
        Route::delete('/permit-files/{file}', [PermitController::class, 'destroyFile'])->name('permits.files.destroy');
    });

    // BOQ style, starter template and concrete ingredients.
    Route::get('/projects/{project}/boq/setup', [BoqSetupController::class, 'show'])->middleware('can:boq.view')->name('boq-setup');
    Route::middleware('can:boq.manage')->group(function () {
        Route::patch('/projects/{project}/boq/mode', [BoqSetupController::class, 'mode'])->name('boq-setup.mode');
        Route::post('/projects/{project}/boq/starter', [BoqSetupController::class, 'starter'])->name('boq-setup.starter');
        Route::post('/boq-items/{item}/concrete', [BoqSetupController::class, 'concrete'])->name('boq-setup.concrete');
    });

    // Who changed the BOQ, when, from what to what, and why.
    Route::get('/projects/{project}/boq/history', BoqHistoryController::class)->middleware('can:boq.view')->name('history');

    // Budget against the BOQ, and market rates typed in by hand. Money, so money.overview.
    Route::middleware('can:money.overview')->group(function () {
        Route::get('/projects/{project}/boq/budget', [BoqBudgetController::class, 'show'])->name('budget');
        Route::post('/projects/{project}/boq/budget/rates', [BoqBudgetController::class, 'rates'])->name('budget.rates');
        Route::post('/projects/{project}/boq/budget/swap', [BoqBudgetController::class, 'swap'])->middleware('can:boq.manage')->name('budget.swap');
    });
});
