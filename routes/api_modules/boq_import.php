<?php

use App\Http\Controllers\Api\BoqImportApiController;
use Illuminate\Support\Facades\Route;

// A BOQ file from the field app, through the website's column check (routes/modules/boq_import.php): the same plan
// feature, permission and limits a minute. Each limit has its own counter, outside the one all requests share.
// Already inside /api/v1 with Sanctum. See docs/API.md, "BOQ file import".

Route::middleware(['feature:reports', 'can:boq.manage'])
    ->prefix('projects/{project}/boq-imports')
    ->name('api.boq-imports.')
    ->controller(BoqImportApiController::class)
    ->group(function () {
        Route::post('/', 'store')->middleware('throttle:20,1,boq-import-upload')->name('store');
        Route::get('/{token}', 'show')->name('show');
        Route::patch('/{token}', 'update')->middleware('throttle:60,1,boq-import-choose')->name('update');
        Route::post('/{token}/confirm', 'confirm')->middleware('throttle:20,1,boq-import-confirm')->name('confirm');
        Route::delete('/{token}', 'destroy')->middleware('throttle:20,1,boq-import-discard')->name('destroy');
    });
