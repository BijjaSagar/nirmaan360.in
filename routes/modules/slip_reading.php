<?php

use App\Http\Controllers\SlipReading\SlipReadController;
use App\Http\Middleware\RequireAiReader;
use Illuminate\Support\Facades\Route;

/*
 * Reading a photo to fill the challan form, before the form is saved. Every answer is JSON, called
 * by the form's script. Who may read which kind of slip (challan, bill) and who may open a reading
 * (its creator, an owner or an admin) is checked in the controller, because it depends on the kind.
 *
 * The names are not slip-reads.*: the website's tests (tests/Feature/Website/AiPagesTest.php) still
 * hold "slip-reads.store" as the name of a reader not built. The website's card itself follows the
 * reader kinds "slip" and "bill" (AiOffer), so it shows once a reader is registered
 * (see config/modules/slip_reading.php).
 */
Route::middleware(['feature:site_control', 'feature:ai_agents'])->name('slip-reading.')->controller(SlipReadController::class)->group(function () {
    // Only what sends the photo to the AI service carries the reader guard. Looking at a reading and discarding it never depend on it.
    Route::post('/projects/{project}/slip-reads', 'store')->name('store')
        ->middleware(['can:agents.use', RequireAiReader::class.':consent', 'throttle:20,1,slip-read']);
    Route::get('/slip-reads/{slip}', 'show')->name('show');
    Route::post('/slip-reads/{slip}/recheck', 'recheck')->name('recheck')
        ->middleware(['can:agents.use', RequireAiReader::class.':consent', 'throttle:20,1,slip-read']);
    Route::post('/slip-reads/{slip}/discard', 'discard')->name('discard')->middleware('throttle:60,1,slip-discard');
});
