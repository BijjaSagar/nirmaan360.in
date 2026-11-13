<?php

use App\Http\Controllers\BoqScan\BoqScanController;
use App\Http\Middleware\RequireAiReader;
use App\Services\BoqScan\BoqReadKind;
use Illuminate\Support\Facades\Route;

// Scanned BOQ reader. Included inside the signed-in, active-user group of routes/web.php. See config/modules/boq_scan.php.
// The whole review is money, so every route needs boq.manage and money.view as well as the reports and AI plan features.
// Only creating a read leads to a page being sent, so only that route carries agents.use and the reader guard.
// Uploading pages, the price, starting and the status are the shared routes in routes/modules/document_reading.php.

Route::middleware(['feature:reports', 'feature:ai_agents', 'can:boq.manage', 'can:money.view'])->name('boq-scans.')->group(function () {
    /*
     * The scan screen. Its name is what the website's card, the refusal of a scanned PDF and the offer on
     * the BOQ in Excel page wait for (Route::has('boq-scans.create')), so it is there only while this
     * module's reader kind is registered in config/modules/boq_scan.php: registering the kind is the one
     * switch that puts the reader in front of people. It says why when a scan cannot be started.
     */
    if (in_array(BoqReadKind::class, (array) config('modules.boq_scan.read_kinds', []), true)) {
        Route::get('/projects/{project}/boq-scans/create', [BoqScanController::class, 'create'])->name('create');
    }

    // Its own counter ("read-create"), so starting scans never uses up another screen's budget.
    Route::post('/projects/{project}/boq-scans', [BoqScanController::class, 'store'])
        ->middleware(['can:agents.use', RequireAiReader::class.':consent', 'throttle:10,1,read-create'])->name('store');

    // A row and a page are only ever reached through their own read: the controller checks the row belongs to it (ChecksParent).
    Route::prefix('boq-scans/{read}')->controller(BoqScanController::class)->group(function () {
        // The screen of one read: its upload and price, its progress, then its review.
        Route::get('/', 'show')->name('show');
        Route::get('/rows', 'rows')->name('rows');
        Route::post('/rows', 'add')->name('rows.store');
        Route::patch('/rows/{row}', 'update')->name('rows.update');
        Route::delete('/rows/{row}', 'exclude')->name('rows.exclude');
        Route::post('/rows/{row}/join', 'join')->name('rows.join');
        Route::post('/rows/{row}/confirm', 'confirmRow')->name('rows.confirm');
        Route::post('/rows/{row}/override', 'overrideRow')->name('rows.override');
        Route::post('/pages/{page}/confirm-clean', 'confirmClean')->whereNumber('page')->name('pages.confirm-clean');
        Route::post('/pages/{page}/no-lines', 'noLines')->whereNumber('page')->name('pages.no-lines');
        Route::post('/acks', 'acknowledge')->name('acks.store');
        Route::post('/confirm', 'confirm')->name('confirm');
        Route::post('/discard', 'discard')->name('discard');
    });
});
