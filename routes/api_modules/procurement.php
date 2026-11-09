<?php

use App\Http\Controllers\Procurement\Api\ProcurementApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:procurement')->group(function () {
    Route::get('/projects/{project}/procurement/snapshot', [ProcurementApiController::class, 'snapshot'])->middleware('can:procurement.view');
    Route::get('/projects/{project}/grns', [ProcurementApiController::class, 'grns'])->middleware('can:procurement.view');
    Route::post('/projects/{project}/grns', [ProcurementApiController::class, 'storeGrn'])->middleware('can:procurement.stock');
    Route::get('/projects/{project}/material-issues', [ProcurementApiController::class, 'issues'])->middleware('can:procurement.view');
    Route::post('/projects/{project}/material-issues', [ProcurementApiController::class, 'storeIssue'])->middleware('can:procurement.stock');

    // Permissions are checked per item.
    Route::post('/procurement/push', [ProcurementApiController::class, 'push']);
});
