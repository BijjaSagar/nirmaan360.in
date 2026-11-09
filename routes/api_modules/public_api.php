<?php

use App\Http\Controllers\PublicApi\PublicApiController;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Support\Facades\Route;

// The public API, at /api/v1/public. Read only, signed in with a company API key instead of a user token.
//
// This file is loaded inside the Sanctum group in routes/api.php, so the group's checks are removed here
// by name: Sanctum sign-in, the active-user check, locale from the user, and the per-user rate limit.
// AuthenticateApiKey replaces them and rate limits each key. If that group in routes/api.php changes,
// tests/Feature/PublicApi/PublicApiTest.php fails.
Route::prefix('public')
    ->withoutMiddleware(['auth:sanctum', 'active', 'locale', 'throttle:120,1'])
    ->group(function () {
        Route::get('/projects', [PublicApiController::class, 'projects'])->middleware(AuthenticateApiKey::class.':projects:read');
        Route::get('/projects/{projectId}', [PublicApiController::class, 'showProject'])->middleware(AuthenticateApiKey::class.':projects:read')->whereNumber('projectId');
        Route::get('/projects/{projectId}/boq', [PublicApiController::class, 'boq'])->middleware(AuthenticateApiKey::class.':boq:read')->whereNumber('projectId');
        Route::get('/projects/{projectId}/dprs', [PublicApiController::class, 'dprs'])->middleware(AuthenticateApiKey::class.':dpr:read')->whereNumber('projectId');
        Route::get('/dprs/{dprId}', [PublicApiController::class, 'showDpr'])->middleware(AuthenticateApiKey::class.':dpr:read')->whereNumber('dprId');
        Route::get('/bills', [PublicApiController::class, 'bills'])->middleware(AuthenticateApiKey::class.':bills:read');
        Route::get('/bills/{billId}', [PublicApiController::class, 'showBill'])->middleware(AuthenticateApiKey::class.':bills:read')->whereNumber('billId');
    });
