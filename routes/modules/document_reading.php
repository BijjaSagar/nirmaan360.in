<?php

use App\Http\Controllers\DocumentReading\AiDocumentSettingsController;
use App\Http\Controllers\DocumentReading\DocumentReadController;
use App\Http\Controllers\DocumentReading\ReaderAdminController;
use App\Http\Controllers\DocumentReading\ReaderTrialController;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\RequireAiReader;
use Illuminate\Support\Facades\Route;

// The company's own switch for sending documents to the AI service, and its spending limits.
// settings.manage is held by owners and admins only; a project manager cannot switch it.
Route::middleware(['feature:ai_agents', 'can:settings.manage'])->controller(AiDocumentSettingsController::class)->group(function () {
    Route::get('/settings/ai-documents', 'edit')->name('ai-documents.edit');
    Route::post('/settings/ai-documents', 'update')->name('ai-documents.update')->middleware('throttle:30,1,ai-documents');
});

// The steps every kind of read shares. A read is created by its feature's own route; these take its
// pages, quote it, start it and report on it. They answer JSON and are called by the capture script.
// Who may open a read depends on its kind, so that is checked for each read in the controller
// (ReadAccess), beside the plan feature of the kind's own module.
Route::middleware('feature:ai_agents')->prefix('document-reads/{read}')->name('document-reads.')->controller(DocumentReadController::class)->group(function () {
    // Watching, cancelling and looking at a stored page never depend on the provider, the limits or the consent switch.
    Route::get('/status', 'status')->name('status');
    Route::get('/pages/{page}/image', 'image')->name('pages.image');
    Route::post('/cancel', 'cancel')->name('cancel');

    // Everything that leads to a page being sent. Each throttle has its own counter, so one cannot use up another's.
    Route::middleware(['can:agents.use', RequireAiReader::class.':consent'])->group(function () {
        Route::post('/pages', 'storePage')->name('pages.store')->middleware('throttle:240,1,read-page');
        Route::put('/source', 'storeSource')->name('source')->middleware('throttle:20,1,read-source');
        Route::post('/quote', 'quote')->name('quote')->middleware('throttle:60,1,read-quote');
        Route::post('/start', 'start')->name('start')->middleware('throttle:10,1,read-start');
        Route::post('/resume', 'resume')->name('resume')->middleware('throttle:10,1,read-resume');
        Route::post('/pages/{page}/retry', 'retry')->name('pages.retry')->middleware('throttle:10,1,read-retry');
        Route::post('/pages/{page}/split', 'split')->name('pages.split')->middleware('throttle:10,1,read-retry');
    });
});

// Platform staff only. Everyone else gets a 404. Nothing here needs a company's plan or consent:
// a dry run belongs to no company, and it is what the admin does before the readers are released.
Route::prefix('admin/ai-reader')->name('ai-reader.admin.')->middleware(EnsurePlatformAdmin::class)->group(function () {
    Route::controller(ReaderAdminController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::patch('/settings', 'update')->name('settings');
        Route::patch('/companies/{organization}', 'company')->name('companies.update');
        Route::delete('/provider-stop', 'clearStop')->name('provider-stop.clear');
        Route::get('/fixtures/{name}', 'fixture')->name('fixtures');
    });

    Route::controller(ReaderTrialController::class)->prefix('trials')->name('trials.')->group(function () {
        Route::post('/', 'store')->name('store')->middleware('throttle:30,1,ai-trial-create');
        Route::get('/{trial}', 'show')->name('show');
        Route::get('/{trial}/status', 'status')->name('status');
        Route::post('/{trial}/pages', 'storePage')->name('pages.store')->middleware('throttle:240,1,ai-trial-page');
        Route::get('/{trial}/pages/{page}/image', 'image')->name('pages.image');
        Route::post('/{trial}/text', 'storeText')->name('text')->middleware('throttle:60,1,ai-trial-text');
        Route::post('/{trial}/start', 'start')->name('start')->middleware('throttle:20,1,ai-trial-start');
        Route::post('/{trial}/marks', 'mark')->name('marks');
        Route::get('/{trial}/scores.csv', 'scores')->name('scores');
        Route::delete('/{trial}', 'destroy')->name('destroy');
    });
});
