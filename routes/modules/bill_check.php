<?php

use App\Http\Controllers\BillCheck\BillCheckController;
use Illuminate\Support\Facades\Route;

// RA bill pre-check. This file is included inside the signed-in, active-user group of routes/web.php.
// It rides on the billing plan feature and on the bill's own permissions, so it adds none.

// The first route is named bill-checks.run, not bill-checks.store: the website's AI page shows its
// "RA bill pre-check" card as soon as a route called bill-checks.store exists (App\Services\Website\AiOffer).

Route::middleware('feature:billing')->name('bill-checks.')->group(function () {
    Route::post('/ra-bills/{bill}/check', [BillCheckController::class, 'store'])->middleware('can:ra_bill.generate')->name('run');
    Route::get('/ra-bills/{bill}/check', [BillCheckController::class, 'show'])->middleware('can:ra_bill.view')->name('show');
    Route::post('/bill-checks/{check}/acks', [BillCheckController::class, 'acknowledge'])->middleware('can:ra_bill.generate')->name('acks.store');

    // Owner and admin only: nobody else holds settings.manage, and a person limited to chosen projects never does.
    Route::patch('/settings/bill-check', [BillCheckController::class, 'policy'])->middleware('can:settings.manage')->name('policy');
});
