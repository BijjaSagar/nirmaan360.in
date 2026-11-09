<?php

use App\Http\Controllers\Workforce\QrAttendanceApiController;
use Illuminate\Support\Facades\Route;

// POST /api/v1/projects/{project}/attendance/scan. Loaded inside the Sanctum group in routes/api.php.
Route::post('/projects/{project}/attendance/scan', [QrAttendanceApiController::class, 'store'])
    ->middleware(['feature:labour', 'can:attendance.manage']);
