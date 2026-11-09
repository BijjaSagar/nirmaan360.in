<?php

use App\Http\Controllers\OfflinePlan\DeviceLicenceApiController;
use Illuminate\Support\Facades\Route;

// Device licences for the offline plan. Loaded inside the Sanctum group in routes/api.php.
// OfflinePlanGate lets these through for a company on the offline plan while it refuses /sync and every write.
Route::prefix('offline')->group(function () {
    // The public key is public: the app fetches it once and verifies every later token on the phone.
    Route::get('/public-key', [DeviceLicenceApiController::class, 'publicKey'])->withoutMiddleware(['auth:sanctum', 'active', 'locale']);
    Route::get('/status', [DeviceLicenceApiController::class, 'status']);
    Route::post('/devices/activate', [DeviceLicenceApiController::class, 'activate'])->middleware('throttle:20,1');
    Route::post('/devices/refresh', [DeviceLicenceApiController::class, 'refresh'])->middleware('throttle:20,1');
    Route::post('/devices/release', [DeviceLicenceApiController::class, 'release'])->middleware('throttle:20,1');
});
