<?php

use App\Http\Controllers\Sso\SsoLoginController;
use App\Http\Controllers\Sso\SsoSettingsController;
use App\Http\Middleware\EnsurePlatformAdmin;
use Illuminate\Support\Facades\Route;

// This file is loaded inside the signed-in group in routes/web.php. Signing in happens before anyone is
// signed in, so the two provider routes step out of the "auth" and "active" checks and are guests only.
Route::withoutMiddleware(['auth', 'active'])
    ->middleware(['guest', 'throttle:20,1'])
    ->prefix('auth')
    ->where(['provider' => 'google|microsoft'])
    ->group(function () {
        Route::get('/{provider}/redirect', [SsoLoginController::class, 'redirect'])->name('sso.redirect');
        Route::get('/{provider}/callback', [SsoLoginController::class, 'callback'])->name('sso.callback');
    });

Route::get('/settings/sso', [SsoSettingsController::class, 'show'])->middleware('can:settings.manage')->name('sso.settings');
Route::patch('/admin/sso', [SsoSettingsController::class, 'update'])->middleware(EnsurePlatformAdmin::class)->name('sso.keys.update');
