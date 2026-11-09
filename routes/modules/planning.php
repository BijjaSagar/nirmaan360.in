<?php

use App\Http\Controllers\Planning\ActivityController;
use App\Http\Controllers\Planning\BudgetController;
use App\Http\Controllers\Planning\OverviewController;
use App\Http\Controllers\Planning\ScheduleController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:planning')->group(function () {
    Route::middleware('can:planning.view')->group(function () {
        Route::get('/projects/{project}/planning', [OverviewController::class, 'index'])->name('planning.index');
        Route::get('/projects/{project}/planning/schedule', [ScheduleController::class, 'index'])->name('planning.schedule');
        Route::get('/projects/{project}/planning/budget', [BudgetController::class, 'index'])->name('planning.budget');
        Route::get('/planning/activities/{activity}', [ActivityController::class, 'show'])->name('planning.activities.show');
    });

    Route::middleware('can:planning.manage')->group(function () {
        Route::post('/projects/{project}/planning/baseline', [OverviewController::class, 'baseline'])->name('planning.baseline.store');
        Route::post('/projects/{project}/planning/alerts/scan', [OverviewController::class, 'scan'])->name('planning.alerts.scan')->middleware('throttle:10,1');

        Route::post('/projects/{project}/planning/wbs', [ScheduleController::class, 'storeNode'])->name('planning.wbs.store');
        Route::patch('/planning/wbs/{node}', [ScheduleController::class, 'updateNode'])->name('planning.wbs.update');
        Route::delete('/planning/wbs/{node}', [ScheduleController::class, 'destroyNode'])->name('planning.wbs.destroy');
        Route::post('/projects/{project}/planning/import', [ScheduleController::class, 'import'])->name('planning.import.store')->middleware('throttle:10,1');

        Route::post('/projects/{project}/planning/activities', [ActivityController::class, 'store'])->name('planning.activities.store');
        Route::patch('/planning/activities/{activity}', [ActivityController::class, 'update'])->name('planning.activities.update');
        Route::delete('/planning/activities/{activity}', [ActivityController::class, 'destroy'])->name('planning.activities.destroy');
        Route::post('/planning/activities/{activity}/dependencies', [ActivityController::class, 'storeDependency'])->name('planning.dependencies.store');
        Route::delete('/planning/dependencies/{dependency}', [ActivityController::class, 'destroyDependency'])->name('planning.dependencies.destroy');
        Route::post('/planning/activities/{activity}/boq-links', [ActivityController::class, 'storeBoqLink'])->name('planning.boq-links.store');
        Route::delete('/planning/boq-links/{link}', [ActivityController::class, 'destroyBoqLink'])->name('planning.boq-links.destroy');

        Route::post('/projects/{project}/planning/budget', [BudgetController::class, 'storeLine'])->name('planning.budget.store');
        Route::post('/projects/{project}/planning/costs', [BudgetController::class, 'storeCost'])->name('planning.costs.store');
        Route::delete('/planning/costs/{entry}', [BudgetController::class, 'destroyCost'])->name('planning.costs.destroy');
    });
});
