<?php

use App\Http\Controllers\BillCheck\BillCheckController;
use App\Http\Controllers\BillCheck\BillCheckExplanationController;
use App\Http\Middleware\RequireAiReader;
use Illuminate\Support\Facades\Route;

// RA bill pre-check. This file is included inside the signed-in, active-user group of routes/web.php.
// It rides on the billing plan feature and on the bill's own permissions, so it adds none.

// The website's AI page shows its "RA bill pre-check" card while the route named bill-checks.run exists
// (App\Services\Website\AiOffer). Renaming it hides the card.

Route::middleware('feature:billing')->name('bill-checks.')->group(function () {
    Route::post('/ra-bills/{bill}/check', [BillCheckController::class, 'store'])->middleware('can:ra_bill.generate')->name('run');
    Route::get('/ra-bills/{bill}/check', [BillCheckController::class, 'show'])->middleware('can:ra_bill.view')->name('show');
    Route::post('/bill-checks/{check}/acks', [BillCheckController::class, 'acknowledge'])->middleware('can:ra_bill.generate')->name('acks.store');

    // The findings explained in plain words. Reading needs nothing but the bill: without the AI every finding is
    // explained in the app's own words. Only asking for it written with AI makes a model call, so only that route
    // carries the AI plan, the gate, the AI permission and the text call throttle.
    Route::get('/bill-checks/{check}/explanation', [BillCheckExplanationController::class, 'show'])->middleware('can:ra_bill.view')->name('explanation');
    Route::post('/bill-checks/{check}/explain', [BillCheckExplanationController::class, 'store'])
        ->middleware(['feature:ai_agents', RequireAiReader::class.':text', 'can:agents.use', 'can:ra_bill.generate', 'throttle:10,1,ai-text'])
        ->name('explain');

    // Owner and admin only: nobody else holds settings.manage, and a person limited to chosen projects never does.
    Route::patch('/settings/bill-check', [BillCheckController::class, 'policy'])->middleware('can:settings.manage')->name('policy');
});
