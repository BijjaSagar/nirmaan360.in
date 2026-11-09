<?php

use App\Http\Controllers\AutoBoq\AssumptionController;
use App\Http\Controllers\AutoBoq\AutoBoqController;
use Illuminate\Support\Facades\Route;

// Auto BOQ module: a BOQ worked out from building dimensions. Web only, as BOQ work belongs on a big
// screen. This file is included inside the signed-in, active-user group of routes/web.php.

Route::middleware(['feature:site_control', 'can:boq.manage'])->name('auto-boq.')->group(function () {
    Route::get('/projects/{project}/auto-boq', [AutoBoqController::class, 'show'])->name('show');
    Route::put('/projects/{project}/auto-boq', [AutoBoqController::class, 'save'])->name('save');
    Route::post('/projects/{project}/auto-boq/apply', [AutoBoqController::class, 'apply'])->name('apply');

    // The company's typical figures, shared by every project.
    Route::get('/auto-boq/assumptions', [AssumptionController::class, 'show'])->name('assumptions');
    Route::put('/auto-boq/assumptions', [AssumptionController::class, 'save'])->name('assumptions.save');
});
