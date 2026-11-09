<?php

use App\Http\Controllers\Qhs\InspectionApiController;
use Illuminate\Support\Facades\Route;

// Mobile API for checklists and inspections (field_ops). Already inside /api/v1 with Sanctum.
Route::middleware('feature:qhs')->group(function () {
    Route::get('/checklist-templates', [InspectionApiController::class, 'templates'])->middleware('can:checklist.view');
    Route::get('/projects/{project}/inspections', [InspectionApiController::class, 'index'])->middleware('can:checklist.view');
    Route::post('/projects/{project}/inspections', [InspectionApiController::class, 'store'])->middleware('can:checklist.fill');
    Route::get('/inspections/{inspection}', [InspectionApiController::class, 'show'])->middleware('can:checklist.view');
    Route::patch('/inspections/{inspection}', [InspectionApiController::class, 'update'])->middleware('can:checklist.fill');
    Route::post('/inspections/{inspection}/complete', [InspectionApiController::class, 'complete'])->middleware('can:checklist.fill');
});
