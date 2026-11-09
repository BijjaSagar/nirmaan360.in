<?php

use App\Http\Controllers\Assets\AssetController;
use App\Http\Controllers\Assets\AssetTransferController;
use App\Http\Controllers\Assets\BreakdownController;
use App\Http\Controllers\Assets\FuelIssueController;
use App\Http\Controllers\Assets\HireBillController;
use App\Http\Controllers\Assets\MachineLogController;
use App\Http\Controllers\Assets\MaintenanceController;
use Illuminate\Support\Facades\Route;

// Assets and plant (field_ops). Already inside the signed-in, active-user group. Plans: see config/modules/assets.php.
Route::middleware('feature:assets')->group(function () {
    // Register and QR tags
    Route::get('/assets', [AssetController::class, 'index'])->middleware('can:asset.view')->name('assets.index');
    Route::get('/assets/create', [AssetController::class, 'create'])->middleware('can:asset.manage')->name('assets.create');
    Route::post('/assets', [AssetController::class, 'store'])->middleware('can:asset.manage')->name('assets.store');
    Route::get('/assets/tags', [AssetController::class, 'tags'])->middleware('can:asset.view')->name('assets.tags');
    Route::get('/a/{tag}', [AssetController::class, 'scan'])->middleware('can:asset.view')->name('assets.scan');
    Route::get('/assets/{asset}', [AssetController::class, 'show'])->middleware('can:asset.view')->name('assets.show');
    Route::get('/assets/{asset}/edit', [AssetController::class, 'edit'])->middleware('can:asset.manage')->name('assets.edit');
    Route::patch('/assets/{asset}', [AssetController::class, 'update'])->middleware('can:asset.manage')->name('assets.update');
    Route::get('/assets/{asset}/tag', [AssetController::class, 'tag'])->middleware('can:asset.view')->name('assets.tag');
    Route::post('/asset-transfers', [AssetTransferController::class, 'store'])->middleware('can:asset.manage')->name('assets.transfers.store');

    // Daily machine log and fuel
    Route::get('/machine-logs', [MachineLogController::class, 'index'])->middleware('can:asset.view')->name('machine-logs.index');
    Route::post('/machine-logs', [MachineLogController::class, 'store'])->middleware('can:asset.log')->name('machine-logs.store');
    Route::delete('/machine-logs/{log}', [MachineLogController::class, 'destroy'])->middleware('can:asset.manage')->name('machine-logs.destroy');
    Route::post('/fuel-issues', [FuelIssueController::class, 'store'])->middleware('can:asset.log')->name('assets.fuel.store');

    // Breakdowns
    Route::post('/asset-breakdowns', [BreakdownController::class, 'store'])->middleware('can:asset.log')->name('assets.breakdowns.store');
    Route::patch('/asset-breakdowns/{breakdown}', [BreakdownController::class, 'update'])->middleware('can:asset.manage')->name('assets.breakdowns.update');

    // Maintenance schedule
    Route::get('/asset-maintenance', [MaintenanceController::class, 'index'])->middleware('can:asset.view')->name('asset-maintenance.index');
    Route::post('/asset-maintenance', [MaintenanceController::class, 'store'])->middleware('can:asset.manage')->name('asset-maintenance.store');
    Route::post('/asset-maintenance/{item}/done', [MaintenanceController::class, 'complete'])->middleware('can:asset.manage')->name('asset-maintenance.done');
    Route::post('/asset-maintenance/{item}/toggle', [MaintenanceController::class, 'toggle'])->middleware('can:asset.manage')->name('asset-maintenance.toggle');

    // Hire bills
    Route::get('/hire-bills', [HireBillController::class, 'index'])->middleware('can:asset.bill')->name('hire-bills.index');
    Route::get('/hire-bills/create', [HireBillController::class, 'create'])->middleware('can:asset.bill')->name('hire-bills.create');
    Route::post('/hire-bills/preview', [HireBillController::class, 'preview'])->middleware('can:asset.bill')->name('hire-bills.preview');
    Route::post('/hire-bills', [HireBillController::class, 'store'])->middleware('can:asset.bill')->name('hire-bills.store');
    Route::get('/hire-bills/{bill}', [HireBillController::class, 'show'])->middleware('can:asset.bill')->name('hire-bills.show');
    Route::post('/hire-bills/{bill}/finalize', [HireBillController::class, 'finalize'])->middleware('can:asset.bill')->name('hire-bills.finalize');
    Route::post('/hire-bills/{bill}/paid', [HireBillController::class, 'paid'])->middleware('can:asset.bill')->name('hire-bills.paid');
    Route::post('/hire-bills/{bill}/cancel', [HireBillController::class, 'cancel'])->middleware('can:asset.bill')->name('hire-bills.cancel');
});
