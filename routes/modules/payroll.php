<?php

use App\Http\Controllers\Payroll\AdvanceController;
use App\Http\Controllers\Payroll\StaffClaimController;
use App\Http\Controllers\Payroll\WageRunController;
use Illuminate\Support\Facades\Route;

// Wage runs, advances, payslips, staff leave and claims. Loaded inside the signed-in group in routes/web.php.
Route::middleware('feature:payroll')->prefix('payroll')->group(function () {
    Route::get('/runs', [WageRunController::class, 'index'])->middleware('can:payroll.view')->name('payroll.runs.index');
    Route::post('/runs', [WageRunController::class, 'store'])->middleware('can:payroll.manage')->name('payroll.runs.store');
    Route::patch('/terms', [WageRunController::class, 'updateTerms'])->middleware('can:payroll.manage')->name('payroll.terms.update');
    Route::get('/runs/{run}', [WageRunController::class, 'show'])->middleware('can:payroll.view')->name('payroll.runs.show');
    Route::post('/runs/{run}/recalculate', [WageRunController::class, 'recalculate'])->middleware('can:payroll.manage')->name('payroll.runs.recalculate');
    Route::post('/runs/{run}/approve', [WageRunController::class, 'approve'])->middleware('can:payroll.approve')->name('payroll.runs.approve');
    Route::post('/runs/{run}/paid', [WageRunController::class, 'paid'])->middleware('can:payroll.approve')->name('payroll.runs.paid');
    Route::delete('/runs/{run}', [WageRunController::class, 'destroy'])->middleware('can:payroll.manage')->name('payroll.runs.destroy');
    Route::get('/runs/{run}/payslips', [WageRunController::class, 'payslips'])->middleware('can:payroll.view')->name('payroll.payslips.all');
    Route::get('/runs/{run}/lines/{line}/payslip', [WageRunController::class, 'payslip'])->middleware('can:payroll.view')->name('payroll.payslips.show');

    Route::get('/advances', [AdvanceController::class, 'index'])->middleware('can:payroll.view')->name('payroll.advances.index');
    Route::post('/advances', [AdvanceController::class, 'store'])->middleware('can:payroll.manage')->name('payroll.advances.store');
    Route::delete('/advances/{advance}', [AdvanceController::class, 'destroy'])->middleware('can:payroll.manage')->name('payroll.advances.destroy');

    Route::get('/staff', [StaffClaimController::class, 'index'])->middleware('can:claim.submit')->name('payroll.staff.index');
    Route::post('/staff/leaves', [StaffClaimController::class, 'storeLeave'])->middleware('can:claim.submit')->name('payroll.staff.leaves.store');
    Route::patch('/staff/leaves/{leave}/decide', [StaffClaimController::class, 'decideLeave'])->middleware('can:claim.approve')->name('payroll.staff.leaves.decide');
    Route::post('/staff/leaves/{leave}/cancel', [StaffClaimController::class, 'cancelLeave'])->middleware('can:claim.submit')->name('payroll.staff.leaves.cancel');
    Route::post('/staff/claims', [StaffClaimController::class, 'storeClaim'])->middleware('can:claim.submit')->name('payroll.staff.claims.store');
    Route::patch('/staff/claims/{claim}/decide', [StaffClaimController::class, 'decideClaim'])->middleware('can:claim.approve')->name('payroll.staff.claims.decide');
    Route::post('/staff/claims/{claim}/paid', [StaffClaimController::class, 'payClaim'])->middleware('can:claim.approve')->name('payroll.staff.claims.paid');
    Route::delete('/staff/claims/{claim}', [StaffClaimController::class, 'cancelClaim'])->middleware('can:claim.submit')->name('payroll.staff.claims.cancel');
});
