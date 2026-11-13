<?php

use App\Http\Controllers\SiteDiary\HindranceController;
use App\Http\Controllers\SiteDiary\SiteLetterController;
use Illuminate\Support\Facades\Route;

// The hindrance register (site_control feature). This file is included inside the signed-in, active-user
// group of routes/web.php. Nothing here is money, so no route needs money.view.
//
// Making or changing an entry copies what daily reports say, so those two routes also need dpr.view.

Route::middleware('feature:site_control')->name('site-diary.hindrances.')->group(function () {
    Route::get('/projects/{project}/hindrances', [HindranceController::class, 'index'])->middleware('can:site_diary.view')->name('index');

    Route::middleware('can:site_diary.manage')->group(function () {
        Route::post('/projects/{project}/hindrances', [HindranceController::class, 'store'])->middleware('can:dpr.view')->name('store');
        Route::patch('/hindrances/{hindrance}', [HindranceController::class, 'update'])->middleware('can:dpr.view')->name('update');
        Route::post('/hindrances/{hindrance}/confirm', [HindranceController::class, 'confirm'])->name('confirm');
        Route::post('/hindrances/{hindrance}/dismiss', [HindranceController::class, 'dismiss'])->name('dismiss');
    });
});

// Letters drafted from the register and from unpaid client bills. A payment follow-up is nothing but money,
// so the controller also asks for money.view on every route that prepares, opens, changes or finalises one.
// No route here calls an AI model, and none sends a letter anywhere.

Route::middleware(['feature:site_control', 'can:site_diary.letters'])->name('site-diary.letters.')->group(function () {
    Route::get('/projects/{project}/site-letters', [SiteLetterController::class, 'index'])->name('index');
    Route::post('/projects/{project}/site-letters', [SiteLetterController::class, 'store'])->name('store');
    Route::get('/site-letters/{letter}', [SiteLetterController::class, 'show'])->name('show');
    Route::patch('/site-letters/{letter}', [SiteLetterController::class, 'update'])->name('update');
    Route::post('/site-letters/{letter}/finalise', [SiteLetterController::class, 'finalise'])->name('finalise');
});
