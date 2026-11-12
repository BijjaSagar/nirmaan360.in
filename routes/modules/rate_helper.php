<?php

use App\Http\Controllers\RateHelper\RateSuggestionController;
use Illuminate\Support\Facades\Route;

// Rate helper module. Included inside the signed-in, active-user group of routes/web.php. See config/modules/rate_helper.php.
// It runs on the plan that has reports, for people who manage the BOQ and may see money. No route here calls an AI model.

Route::middleware(['feature:reports', 'can:boq.manage', 'can:money.view'])->name('rate-suggestions.')->group(function () {
    // Its own counter ("rate-suggest"), so building shortlists never uses up another screen's budget.
    Route::post('/projects/{project}/rate-suggestions', [RateSuggestionController::class, 'store'])->middleware('throttle:10,1,rate-suggest')->name('store');
    Route::get('/rate-suggestions/{run}', [RateSuggestionController::class, 'show'])->name('show');

    // A line is only ever reached through its own run.
    Route::scopeBindings()->group(function () {
        Route::post('/rate-suggestions/{run}/lines/{line}/accept', [RateSuggestionController::class, 'accept'])->name('lines.accept');
        Route::post('/rate-suggestions/{run}/lines/{line}/skip', [RateSuggestionController::class, 'skip'])->name('lines.skip');
    });
});
