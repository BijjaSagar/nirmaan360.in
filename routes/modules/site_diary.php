<?php

use App\Http\Controllers\SiteDiary\HindranceController;
use App\Http\Controllers\SiteDiary\SiteLetterController;
use App\Http\Middleware\RequireAiReader;
use Illuminate\Support\Facades\Route;

// The hindrance register (site_control feature). This file is included inside the signed-in, active-user
// group of routes/web.php. Nothing here is money, so no route needs money.view.
//
// Making or changing an entry copies what daily reports say, so those two routes also need dpr.view.
//
// Two routes send something to the AI model and carry its guards: the AI plan, agents.use, RequireAiReader and
// the "ai-text" throttle, a counter of its own. Suggesting groups sends the hindrance notes staff wrote in daily
// reports, so it needs the company's consent switch (':consent'). Drafting a letter's three sentences sends only
// what each sentence is for and the names of the figures around it (':text').

Route::middleware('feature:site_control')->name('site-diary.hindrances.')->group(function () {
    Route::get('/projects/{project}/hindrances', [HindranceController::class, 'index'])->middleware('can:site_diary.view')->name('index');

    Route::middleware('can:site_diary.manage')->group(function () {
        Route::post('/projects/{project}/hindrances', [HindranceController::class, 'store'])->middleware('can:dpr.view')->name('store');
        Route::patch('/hindrances/{hindrance}', [HindranceController::class, 'update'])->middleware('can:dpr.view')->name('update');
        Route::post('/hindrances/{hindrance}/confirm', [HindranceController::class, 'confirm'])->name('confirm');
        Route::post('/hindrances/{hindrance}/dismiss', [HindranceController::class, 'dismiss'])->name('dismiss');

        Route::post('/projects/{project}/hindrances/suggest', [HindranceController::class, 'suggest'])
            ->middleware(['can:dpr.view', 'feature:ai_agents', 'can:agents.use', RequireAiReader::class.':consent', 'throttle:20,1,ai-text'])
            ->name('suggest');
    });
});

// Letters drafted from the register and from unpaid client bills. A payment follow-up is nothing but money,
// so the controller also asks for money.view on every route that prepares, opens, changes or finalises one.
// Only "draft" calls an AI model, and no route sends a letter anywhere.

Route::middleware(['feature:site_control', 'can:site_diary.letters'])->name('site-diary.letters.')->group(function () {
    Route::get('/projects/{project}/site-letters', [SiteLetterController::class, 'index'])->name('index');
    Route::post('/projects/{project}/site-letters', [SiteLetterController::class, 'store'])->name('store');
    Route::get('/site-letters/{letter}', [SiteLetterController::class, 'show'])->name('show');
    Route::patch('/site-letters/{letter}', [SiteLetterController::class, 'update'])->name('update');
    Route::post('/site-letters/{letter}/finalise', [SiteLetterController::class, 'finalise'])->name('finalise');

    Route::post('/site-letters/{letter}/draft', [SiteLetterController::class, 'draft'])
        ->middleware(['feature:ai_agents', 'can:agents.use', RequireAiReader::class.':text', 'throttle:20,1,ai-text'])
        ->name('draft');
});
