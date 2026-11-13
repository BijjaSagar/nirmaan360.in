<?php

use App\Http\Controllers\DocumentReading\DocumentReadController;
use App\Http\Controllers\DocumentReading\ReaderStatusController;
use App\Http\Middleware\RequireAiReader;
use Illuminate\Support\Facades\Route;

// What the app asks before it shows any AI button: are the readers released, connected and agreed
// to, and which of them may this person use. It answers on every plan, so the app can say why not.
Route::get('/document-reading/status', ReaderStatusController::class)->name('api.document-reading.status');

// The same steps as on the web, under the same paths. A read is created by its feature's own route.
Route::middleware('feature:ai_agents')->prefix('document-reads/{read}')->name('api.document-reads.')->controller(DocumentReadController::class)->group(function () {
    // The app asks for the status every few seconds while a read runs. That has its own counter,
    // outside the one all other requests share, so watching a read cannot hold up the app's sync.
    Route::get('/', 'status')->name('status')->middleware('throttle:90,1,read-status')->withoutMiddleware('throttle:120,1');
    Route::get('/pages/{page}/image', 'image')->name('pages.image');
    Route::post('/cancel', 'cancel')->name('cancel');

    Route::middleware(['can:agents.use', RequireAiReader::class.':consent'])->group(function () {
        // Sixty pages are sixty requests. They leave the shared counter too, for the same reason.
        Route::post('/pages', 'storePage')->name('pages.store')->middleware('throttle:240,1,read-page')->withoutMiddleware('throttle:120,1');
        Route::put('/source', 'storeSource')->name('source')->middleware('throttle:20,1,read-source');
        Route::post('/quote', 'quote')->name('quote')->middleware('throttle:60,1,read-quote');
        Route::post('/start', 'start')->name('start')->middleware('throttle:10,1,read-start');
        Route::post('/resume', 'resume')->name('resume')->middleware('throttle:10,1,read-resume');
        Route::post('/pages/{page}/retry', 'retry')->name('pages.retry')->middleware('throttle:10,1,read-retry');
        Route::post('/pages/{page}/split', 'split')->name('pages.split')->middleware('throttle:10,1,read-retry');
    });
});
