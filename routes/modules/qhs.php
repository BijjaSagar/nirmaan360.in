<?php

use App\Http\Controllers\Qhs\AttachmentController;
use App\Http\Controllers\Qhs\ChecklistController;
use App\Http\Controllers\Qhs\IncidentController;
use App\Http\Controllers\Qhs\InspectionController;
use App\Http\Controllers\Qhs\NcrController;
use App\Http\Controllers\Qhs\QualityTestController;
use App\Http\Controllers\Qhs\RfiController;
use App\Http\Controllers\Qhs\ToolboxTalkController;
use Illuminate\Support\Facades\Route;

// Quality, safety and site checks (field_ops). Already inside the signed-in, active-user group. Plans: see config/modules/qhs.php.
Route::middleware('feature:qhs')->group(function () {
    // NCRs
    Route::get('/projects/{project}/ncrs', [NcrController::class, 'index'])->middleware('can:qhs.view')->name('ncrs.index');
    Route::post('/projects/{project}/ncrs', [NcrController::class, 'store'])->middleware('can:qhs.manage')->name('ncrs.store');
    Route::get('/ncrs/{ncr}', [NcrController::class, 'show'])->middleware('can:qhs.view')->name('ncrs.show');
    Route::patch('/ncrs/{ncr}', [NcrController::class, 'update'])->middleware('can:qhs.manage')->name('ncrs.update');

    // Quality test register
    Route::get('/projects/{project}/quality-tests', [QualityTestController::class, 'index'])->middleware('can:qhs.view')->name('quality-tests.index');
    Route::post('/projects/{project}/quality-tests', [QualityTestController::class, 'store'])->middleware('can:qhs.manage')->name('quality-tests.store');
    Route::get('/quality-tests/{test}', [QualityTestController::class, 'show'])->middleware('can:qhs.view')->name('quality-tests.show');
    Route::post('/quality-tests/{test}/specimens', [QualityTestController::class, 'addSpecimen'])->middleware('can:qhs.manage')->name('quality-tests.specimens.store');
    Route::patch('/quality-test-results/{result}', [QualityTestController::class, 'record'])->middleware('can:qhs.manage')->name('quality-tests.results.update');

    // Safety: incidents and near misses, toolbox talks
    Route::get('/projects/{project}/safety', [IncidentController::class, 'index'])->middleware('can:safety.view')->name('incidents.index');
    Route::post('/projects/{project}/safety', [IncidentController::class, 'store'])->middleware('can:safety.manage')->name('incidents.store');
    Route::get('/safety/{incident}', [IncidentController::class, 'show'])->middleware('can:safety.view')->name('incidents.show');
    Route::patch('/safety/{incident}', [IncidentController::class, 'update'])->middleware('can:safety.manage')->name('incidents.update');
    Route::get('/projects/{project}/safety/toolbox-talks', [ToolboxTalkController::class, 'index'])->middleware('can:safety.view')->name('incidents.toolbox.index');
    Route::post('/projects/{project}/safety/toolbox-talks', [ToolboxTalkController::class, 'store'])->middleware('can:safety.manage')->name('incidents.toolbox.store');

    // Checklist templates and inspections
    Route::get('/checklists', [ChecklistController::class, 'index'])->middleware('can:checklist.view')->name('checklists.index');
    Route::post('/checklists', [ChecklistController::class, 'store'])->middleware('can:checklist.manage')->name('checklists.store');
    Route::post('/checklists/standard', [ChecklistController::class, 'standard'])->middleware('can:checklist.manage')->name('checklists.standard');
    Route::patch('/checklists/{template}', [ChecklistController::class, 'update'])->middleware('can:checklist.manage')->name('checklists.update');
    Route::get('/projects/{project}/inspections', [InspectionController::class, 'index'])->middleware('can:checklist.view')->name('inspections.index');
    Route::post('/projects/{project}/inspections', [InspectionController::class, 'store'])->middleware('can:checklist.fill')->name('inspections.store');
    Route::get('/inspections/{inspection}', [InspectionController::class, 'show'])->middleware('can:checklist.view')->name('inspections.show');
    Route::patch('/inspections/{inspection}', [InspectionController::class, 'update'])->middleware('can:checklist.fill')->name('inspections.update');

    // RFIs, approved through the approval engine
    Route::get('/projects/{project}/rfis', [RfiController::class, 'index'])->middleware('can:rfi.view')->name('rfis.index');
    Route::get('/projects/{project}/rfis/create', [RfiController::class, 'create'])->middleware('can:rfi.manage')->name('rfis.create');
    Route::post('/projects/{project}/rfis', [RfiController::class, 'store'])->middleware('can:rfi.manage')->name('rfis.store');
    Route::get('/rfis/{rfi}', [RfiController::class, 'show'])->middleware('can:rfi.view')->name('rfis.show');
    Route::get('/rfis/{rfi}/edit', [RfiController::class, 'edit'])->middleware('can:rfi.manage')->name('rfis.edit');
    Route::patch('/rfis/{rfi}', [RfiController::class, 'update'])->middleware('can:rfi.manage')->name('rfis.update');
    Route::post('/rfis/{rfi}/submit', [RfiController::class, 'submit'])->middleware('can:rfi.manage')->name('rfis.submit');
    Route::post('/rfis/{rfi}/respond', [RfiController::class, 'respond'])->middleware('can:rfi.manage')->name('rfis.respond');
    Route::post('/rfis/{rfi}/close', [RfiController::class, 'close'])->middleware('can:rfi.manage')->name('rfis.close');

    // Files on the records above. Permission is checked per record type inside the controller.
    Route::post('/qhs-files/{type}/{id}', [AttachmentController::class, 'store'])->name('qhs-files.store');
    Route::get('/qhs-files/{fieldAttachment}', [AttachmentController::class, 'show'])->name('qhs-files.show');
});
