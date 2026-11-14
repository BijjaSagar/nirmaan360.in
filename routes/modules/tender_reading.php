<?php

use App\Http\Controllers\TenderReading\TenderFactController;
use App\Http\Controllers\TenderReading\TenderReadController;
use App\Http\Middleware\RequireAiReader;
use Illuminate\Support\Facades\Route;

// The tender reader. This file is included inside the signed-in, active-user group of routes/web.php.
// It rides on the CRM plan feature and the tender's own permissions: crm.view reads, crm.manage acts.
// The website's AI page waits for a route named tender-reads.show before it shows its "Tender reader" card
// (App\Services\Website\AiOffer, and tests/Feature/Website/AiPagesTest, which lists that name as not built).
// The reading screen is named tender-reads.view until the reader is switched on; switching it on renames the
// card's route in AiOffer, or this one, together with listing the reader in config/modules/tender_reading.php.

Route::middleware('feature:crm')->prefix('crm')->group(function () {
    // Starting a read leads to a model call, so it needs the AI features, agents.use and the company's consent.
    // One counter of its own, so it cannot use up another route's.
    Route::post('/tenders/{tender}/documents/{document}/reads', [TenderReadController::class, 'store'])
        ->middleware(['can:crm.manage', 'can:agents.use', 'feature:ai_agents', RequireAiReader::class.':consent', 'throttle:10,1,tender-prepare'])
        ->name('tender-reads.store');

    // The answers before the price: the document's language and whether its text matches its pages. Nothing is sent here.
    Route::post('/tender-reads/{read}/mode', [TenderReadController::class, 'mode'])
        ->middleware(['can:crm.manage', 'can:agents.use', 'feature:ai_agents', 'throttle:30,1,tender-mode'])
        ->name('tender-reads.mode');

    Route::get('/tender-reads/{read}', [TenderReadController::class, 'show'])->middleware('can:crm.view')->name('tender-reads.view');

    // The sheet. Reading it and working through it never depend on the AI service, its limits or the consent switch.
    Route::get('/tenders/{tender}/facts', [TenderFactController::class, 'index'])->middleware('can:crm.view')->name('tender-facts.index');

    Route::middleware('can:crm.manage')->controller(TenderFactController::class)->group(function () {
        Route::patch('/tender-facts/{fact}', 'update')->name('tender-facts.update');
        Route::post('/tender-facts/{fact}/confirm', 'confirm')->name('tender-facts.confirm');
        Route::post('/tender-facts/{fact}/reject', 'reject')->name('tender-facts.reject');
        // A confirmed fact into the tender's field, a follow-up or a planned payment. A payment also needs crm.emd, checked in the controller.
        Route::post('/tender-facts/{fact}/apply', 'apply')->name('tender-facts.apply')->middleware('throttle:60,1,tender-apply');
    });
});
