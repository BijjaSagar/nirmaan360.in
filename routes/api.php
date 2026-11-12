<?php

use App\Http\Controllers\Api\AccountDeletionApiController;
use App\Http\Controllers\Api\ApprovalApiController;
use App\Http\Controllers\Api\AttendanceApiController;
use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\DprApiController;
use App\Http\Controllers\Api\IssueApiController;
use App\Http\Controllers\Api\MeasurementApiController;
use App\Http\Controllers\Api\PettyCashApiController;
use App\Http\Controllers\Api\ProjectApiController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\WorkerApiController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RazorpayWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthApiController::class, 'login'])->middleware('throttle:10,1');
    // Its own counter ("signup"), so sign-ups never use up the sign-in budget a shared mobile IP has.
    Route::post('/auth/register', [AuthApiController::class, 'register'])->middleware('throttle:5,1,signup');
    Route::post('/webhooks/razorpay', RazorpayWebhookController::class)->middleware('throttle:120,1');

    Route::middleware(['auth:sanctum', 'active', 'locale', 'throttle:120,1'])->group(function () {
        Route::post('/auth/logout', [AuthApiController::class, 'logout']);
        Route::get('/me', [AuthApiController::class, 'me']);

        // Deleting one's own account. Under /auth so the offline plan and contractor logins reach it; each step has its own counter.
        Route::get('/auth/account/deletion', [AccountDeletionApiController::class, 'show']);
        Route::post('/auth/account/deletion', [AccountDeletionApiController::class, 'store'])->middleware('throttle:5,1,account-deletion');
        Route::delete('/auth/account/deletion', [AccountDeletionApiController::class, 'destroy'])->middleware('throttle:10,1,account-deletion-cancel');

        Route::get('/projects', [ProjectApiController::class, 'index'])->middleware('can:project.view');
        Route::get('/projects/{project}/image', [ProjectController::class, 'image'])->middleware('can:project.view');
        Route::post('/projects', [ProjectApiController::class, 'store'])->middleware('can:project.manage');
        Route::get('/projects/{project}/boq', [ProjectApiController::class, 'boq'])->middleware('can:boq.view');
        Route::post('/projects/{project}/boq-items', [ProjectApiController::class, 'storeBoqItem'])->middleware('can:boq.manage');

        // Each module adds its own endpoints in routes/api_modules/<name>.php. See docs/MODULES.md.
        foreach (glob(base_path('routes/api_modules/*.php')) ?: [] as $moduleRoutes) {
            require $moduleRoutes;
        }

        Route::middleware('feature:measurement')->group(function () {
            Route::get('/projects/{project}/measurements', [MeasurementApiController::class, 'index'])->middleware('can:measurement.view');
            Route::post('/projects/{project}/measurements', [MeasurementApiController::class, 'store'])->middleware('can:measurement.create');
            Route::patch('/measurements/{measurement}', [MeasurementApiController::class, 'update'])->middleware('can:measurement.create');
            Route::post('/measurements/{measurement}/submit', [MeasurementApiController::class, 'submit'])->middleware('can:measurement.create');
        });

        Route::middleware('feature:site')->group(function () {
            Route::get('/projects/{project}/dprs', [DprApiController::class, 'index'])->middleware('can:dpr.view');
            Route::post('/projects/{project}/dprs', [DprApiController::class, 'store'])->middleware('can:dpr.create');
            Route::get('/dprs/{dpr}', [DprApiController::class, 'show'])->middleware('can:dpr.view');
            Route::patch('/dprs/{dpr}', [DprApiController::class, 'update'])->middleware('can:dpr.create');
            Route::post('/dprs/{dpr}/submit', [DprApiController::class, 'submit'])->middleware('can:dpr.create');
            Route::post('/dprs/{dpr}/photos', [DprApiController::class, 'storePhoto'])->middleware('can:dpr.create');
            Route::get('/dpr-photos/{photo}', [DprApiController::class, 'photo'])->middleware('can:dpr.view');

            Route::get('/projects/{project}/issues', [IssueApiController::class, 'index'])->middleware('can:issue.view');
            Route::post('/projects/{project}/issues', [IssueApiController::class, 'store'])->middleware('can:issue.manage');
            Route::patch('/issues/{issue}', [IssueApiController::class, 'update'])->middleware('can:issue.manage');
        });

        Route::middleware('feature:labour')->group(function () {
            Route::get('/workers', [WorkerApiController::class, 'index'])->middleware('can:worker.view');
            Route::post('/workers', [WorkerApiController::class, 'store'])->middleware('can:worker.manage');

            Route::get('/projects/{project}/attendance', [AttendanceApiController::class, 'index'])->middleware('can:attendance.view');
            Route::post('/projects/{project}/attendance', [AttendanceApiController::class, 'store'])->middleware('can:attendance.manage');
        });

        Route::middleware('feature:petty_cash')->group(function () {
            Route::get('/projects/{project}/petty-cash', [PettyCashApiController::class, 'index'])->middleware('can:petty_cash.view');
            Route::post('/projects/{project}/wallets', [PettyCashApiController::class, 'storeWallet'])->middleware('can:petty_cash.manage');
            Route::post('/petty-wallets/{wallet}/entries', [PettyCashApiController::class, 'storeEntry'])->middleware('can:petty_cash.manage');
            Route::get('/petty-entries/{entry}/bill', [PettyCashApiController::class, 'bill'])->middleware('can:petty_cash.view');
        });

        // canAct decides who may act, per approval.
        Route::get('/approvals', [ApprovalApiController::class, 'index']);
        Route::post('/approvals/{approval}/act', [ApprovalApiController::class, 'act']);

        // Permissions are checked per item, or per upload type.
        Route::post('/sync/push', [SyncController::class, 'push']);
        Route::get('/sync/pull', [SyncController::class, 'pull']);
        Route::post('/sync/upload', [SyncController::class, 'upload']);
    });
});
