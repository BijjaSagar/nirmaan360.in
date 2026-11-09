<?php

use App\Http\Controllers\ContractorPortal\Api\ContractorPortalApiController;
use Illuminate\Support\Facades\Route;

// The field app's contractor portal. GET /api/v1/me says "kind": "contractor" for the logins that use it.
Route::middleware(['feature:contractor_portal', 'can:contractor_portal.view'])->prefix('contractor-portal')->controller(ContractorPortalApiController::class)->group(function () {
    Route::get('/', 'overview');
    Route::get('/work-orders/{workOrder}', 'contract')->whereNumber('workOrder');
    Route::get('/payments', 'payments');
    Route::get('/bills', 'bills');
    Route::get('/bills/{bill}', 'bill')->whereNumber('bill');
});
