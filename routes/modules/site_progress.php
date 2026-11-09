<?php

use App\Http\Controllers\SiteProgress\EntryController;
use App\Http\Controllers\SiteProgress\FloorController;
use App\Http\Controllers\SiteProgress\PhotoController;
use App\Http\Controllers\SiteProgress\ProgressController;
use App\Http\Controllers\SiteProgress\StageController;
use Illuminate\Support\Facades\Route;

// Site progress and the plumbing and electrical stage tracker. Already inside the signed-in, active-user group.
// No screen here shows a rate, an amount or a budget. Plans: see config/modules/site_progress.php.
Route::middleware('feature:site_control')->group(function () {
    // Overview, pending list, weekly view
    Route::get('/projects/{project}/progress', [ProgressController::class, 'index'])->middleware('can:progress.view')->name('site-progress.index');
    Route::patch('/projects/{project}/progress/time-frame', [ProgressController::class, 'schedule'])->middleware('can:progress.manage')->name('site-progress.schedule');
    Route::get('/projects/{project}/progress/pending', [ProgressController::class, 'pending'])->middleware('can:progress.view')->name('site-progress.pending');
    Route::get('/projects/{project}/progress/week', [ProgressController::class, 'week'])->middleware('can:progress.view')->name('site-progress.week');

    // Daily entries. There is no edit and no delete: a mistake is a correction entry.
    Route::get('/projects/{project}/progress/entries', [EntryController::class, 'index'])->middleware('can:progress.view')->name('site-progress.entries');
    Route::post('/projects/{project}/progress/entries', [EntryController::class, 'store'])->middleware('can:progress.create')->name('site-progress.entries.store');
    Route::post('/progress/entries/{progressEntry}/correct', [EntryController::class, 'correct'])->middleware('can:progress.create')->name('site-progress.entries.correct');
    Route::post('/progress/entries/{progressEntry}/resolve', [EntryController::class, 'resolve'])->middleware('can:progress.create')->name('site-progress.entries.resolve');
    Route::get('/progress/photos/{progressPhoto}', [PhotoController::class, 'show'])->middleware('can:progress.view')->name('site-progress.photos.show');

    // Floors and the quantity planned on each
    Route::get('/projects/{project}/progress/floors', [FloorController::class, 'index'])->middleware('can:progress.view')->name('site-progress.floors.index');
    Route::post('/projects/{project}/progress/floors', [FloorController::class, 'store'])->middleware('can:progress.manage')->name('site-progress.floors.store');
    Route::post('/projects/{project}/progress/floors/split', [FloorController::class, 'split'])->middleware('can:progress.manage')->name('site-progress.floors.split');
    Route::get('/progress/floors/{floor}', [FloorController::class, 'show'])->middleware('can:progress.view')->name('site-progress.floors.show');
    Route::patch('/progress/floors/{floor}', [FloorController::class, 'update'])->middleware('can:progress.manage')->name('site-progress.floors.update');
    Route::delete('/progress/floors/{floor}', [FloorController::class, 'destroy'])->middleware('can:progress.manage')->name('site-progress.floors.destroy');
    Route::put('/progress/floors/{floor}/target', [FloorController::class, 'target'])->middleware('can:progress.manage')->name('site-progress.floors.target');

    // Plumbing and electrical stages
    Route::get('/projects/{project}/stages', [StageController::class, 'index'])->middleware('can:progress.view')->name('site-stages.index');
    Route::post('/projects/{project}/stages/cells', [StageController::class, 'update'])->middleware('can:progress.create')->name('site-stages.update');
    Route::get('/progress/floors/{floor}/before-finishing', [StageController::class, 'checklist'])->middleware('can:progress.view')->name('site-stages.checklist');
});
