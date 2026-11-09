<?php

use App\Http\Controllers\PriceWatch\PriceBoardController;
use App\Http\Controllers\PriceWatch\ProjectPriceController;
use App\Http\Controllers\PriceWatch\SharedPriceAdminController;
use App\Http\Middleware\EnsurePlatformAdmin;
use Illuminate\Support\Facades\Route;

// Price watch module. Included inside the signed-in, active-user group of routes/web.php. See config/modules/price_watch.php.

Route::middleware('feature:price_watch')->name('price-watch.')->group(function () {
    // The company price board. Rates are money.
    Route::middleware('can:money.view')->group(function () {
        Route::get('/price-watch', [PriceBoardController::class, 'index'])->name('index');
        Route::get('/price-watch/materials/{watchMaterial}', [PriceBoardController::class, 'show'])->name('materials.show');

        Route::middleware('can:price_watch.manage')->group(function () {
            Route::post('/price-watch/materials', [PriceBoardController::class, 'storeMaterial'])->name('materials.store');
            Route::patch('/price-watch/materials/{watchMaterial}', [PriceBoardController::class, 'updateMaterial'])->name('materials.update');
            Route::post('/price-watch/prices', [PriceBoardController::class, 'storePrice'])->name('prices.store');
            Route::delete('/price-watch/prices/{materialPrice}', [PriceBoardController::class, 'destroyPrice'])->name('prices.destroy');
            Route::get('/price-watch/template', [PriceBoardController::class, 'template'])->name('template');
            Route::post('/price-watch/upload', [PriceBoardController::class, 'upload'])->middleware('throttle:20,1')->name('upload');
            Route::post('/price-watch/adopt', [PriceBoardController::class, 'adopt'])->middleware('throttle:20,1')->name('adopt');
        });
    });

    // What today's prices do to a project's budget. Budget figures, so money.overview.
    Route::middleware('can:money.overview')->group(function () {
        Route::get('/projects/{project}/price-watch', [ProjectPriceController::class, 'show'])->name('project');
        Route::post('/projects/{project}/price-watch/boq-rates', [ProjectPriceController::class, 'saveRates'])->middleware('can:price_watch.manage')->name('project.rates');
    });
});

// Platform staff only: the shared list and the module's limits. Everyone else gets a 404.
Route::prefix('admin/price-watch')->name('price-watch.admin.')->middleware(EnsurePlatformAdmin::class)->controller(SharedPriceAdminController::class)->group(function () {
    Route::get('/', 'edit')->name('edit');
    Route::post('/prices', 'save')->name('prices.save');
    Route::delete('/prices/{sharedPrice}', 'destroy')->name('prices.destroy');
    Route::patch('/settings', 'settings')->name('settings');
});
