<?php

use App\Http\Controllers\Notify\NotifyApiController;
use App\Http\Controllers\Notify\SelfieAttendanceApiController;
use Illuminate\Support\Facades\Route;

Route::post('/devices', [NotifyApiController::class, 'storeDevice']);
Route::delete('/devices', [NotifyApiController::class, 'destroyDevice']);
Route::get('/notification-preferences', [NotifyApiController::class, 'preferences']);
Route::put('/notification-preferences', [NotifyApiController::class, 'updatePreferences']);

Route::middleware(['feature:labour', 'can:attendance.manage'])->group(function () {
    Route::get('/attendance/selfie-consent', [SelfieAttendanceApiController::class, 'consent']);
    Route::post('/projects/{project}/attendance/selfies', [SelfieAttendanceApiController::class, 'store']);
    Route::get('/attendance-selfies/{selfie}/photo', [SelfieAttendanceApiController::class, 'photo']);
});
