<?php

use App\Http\Controllers\VoiceDpr\VoiceDraftController;
use App\Http\Middleware\RequireAiReader;
use Illuminate\Support\Facades\Route;

// A daily report spoken in the field app. Only the words heard on the phone are sent; the server
// returns a proposal and never writes a DPR. Every route needs the site module, the AI feature and dpr.create.
Route::middleware(['feature:site', 'feature:ai_agents', 'can:dpr.create'])->controller(VoiceDraftController::class)->group(function () {
    // The one route that sends words to the AI model.
    Route::post('/projects/{project}/dpr-voice-drafts', 'store')->name('api.dpr-voice-drafts.store')
        ->middleware(['can:agents.use', RequireAiReader::class.':consent', 'throttle:10,1,voice-draft']);

    // The phone asks every few seconds while it waits. That has its own counter, outside the one the app's sync shares.
    Route::get('/dpr-voice-drafts/{draft}', 'show')->name('api.dpr-voice-drafts.show')
        ->middleware('throttle:90,1,voice-status')->withoutMiddleware('throttle:120,1');

    Route::post('/dpr-voice-drafts/{draft}/link', 'link')->name('api.dpr-voice-drafts.link')
        ->middleware('throttle:30,1,voice-link');
});
