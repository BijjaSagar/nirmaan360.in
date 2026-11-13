<?php

use App\Http\Controllers\SlipReading\SlipReadController;
use App\Http\Middleware\RequireAiReader;
use Illuminate\Support\Facades\Route;

/*
 * The field app's "Read from photo" on the challan form: the same steps as on the web, under the
 * same paths. A supplier bill is read on the web only. Watching a reading has its own counter,
 * outside the one every request of the app shares, so polling cannot hold up the app's sync.
 */
Route::middleware(['feature:site_control', 'feature:ai_agents'])->name('api.slip-reading.')->controller(SlipReadController::class)->group(function () {
    Route::post('/projects/{project}/slip-reads', 'store')->name('store')
        ->middleware(['can:agents.use', RequireAiReader::class.':consent', 'throttle:20,1,slip-read']);
    Route::get('/slip-reads/{slip}', 'show')->name('show')->middleware('throttle:90,1,slip-status')->withoutMiddleware('throttle:120,1');
    Route::post('/slip-reads/{slip}/recheck', 'recheck')->name('recheck')
        ->middleware(['can:agents.use', RequireAiReader::class.':consent', 'throttle:20,1,slip-read']);
    Route::post('/slip-reads/{slip}/discard', 'discard')->name('discard')->middleware('throttle:60,1,slip-discard');
});
