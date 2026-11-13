<?php

use App\Http\Controllers\RateHelper\RateRankingController;
use App\Http\Controllers\RateHelper\RateSuggestionController;
use App\Http\Middleware\RequireAiReader;
use Illuminate\Support\Facades\Route;

// Rate helper module. Included inside the signed-in, active-user group of routes/web.php. See config/modules/rate_helper.php.
// It runs on the plan that has reports, for people who manage the BOQ and may see money. Only rate-suggestions.rank calls an AI model.

Route::middleware(['feature:reports', 'can:boq.manage', 'can:money.view'])->name('rate-suggestions.')->group(function () {
    // Its own counter ("rate-suggest"), so building shortlists never uses up another screen's budget.
    Route::post('/projects/{project}/rate-suggestions', [RateSuggestionController::class, 'store'])->middleware('throttle:10,1,rate-suggest')->name('store');
    Route::get('/rate-suggestions/{run}', [RateSuggestionController::class, 'show'])->name('show');

    // The AI puts each line's candidates in order. Only asking makes a model call, so only that route carries the AI plan,
    // the gate, the AI permission and the text call throttle ("ai-text", one counter for every route that starts a
    // text call). Where it stands is read without any of them.
    Route::post('/rate-suggestions/{run}/rank', [RateRankingController::class, 'store'])
        ->middleware(['feature:ai_agents', RequireAiReader::class.':text', 'can:agents.use', 'throttle:20,1,ai-text'])
        ->name('rank');
    Route::get('/rate-suggestions/{run}/ranking', [RateRankingController::class, 'show'])->name('ranking');

    // A line is only ever reached through its own run.
    Route::scopeBindings()->group(function () {
        Route::post('/rate-suggestions/{run}/lines/{line}/accept', [RateSuggestionController::class, 'accept'])->name('lines.accept');
        Route::post('/rate-suggestions/{run}/lines/{line}/skip', [RateSuggestionController::class, 'skip'])->name('lines.skip');
    });
});
