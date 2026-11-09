<?php

use App\Http\Controllers\PublicApi\ApiDocsController;
use App\Http\Controllers\PublicApi\ApiKeyController;
use Illuminate\Support\Facades\Route;

// Managing keys and reading the docs. Loaded inside the signed-in group in routes/web.php.
// The API itself is in routes/api_modules/public_api.php.
Route::middleware(['feature:public_api', 'feature:api', 'can:api_key.manage'])->prefix('developer')->group(function () {
    Route::get('/api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
    Route::post('/api-keys', [ApiKeyController::class, 'store'])->middleware('throttle:20,1')->name('api-keys.store');
    Route::post('/api-keys/{key}/revoke', [ApiKeyController::class, 'revoke'])->name('api-keys.revoke');
    Route::get('/api-docs', ApiDocsController::class)->name('api-docs');
});
