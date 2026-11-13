<?php

use App\Http\Controllers\SiteDiary\HindranceController;
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
