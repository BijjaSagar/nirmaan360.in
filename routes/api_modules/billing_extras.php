<?php

use App\Http\Controllers\BillingExtras\Api\IssuePhotoApiController;
use App\Http\Controllers\BillingExtras\Api\MeasurementAttachmentApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:site')->group(function () {
    Route::get('/issues/{issue}/photos', [IssuePhotoApiController::class, 'index'])->middleware('can:issue.view');
    Route::post('/issues/{issue}/photos', [IssuePhotoApiController::class, 'store'])->middleware('can:issue.manage');
    Route::get('/issue-photos/{issuePhoto}', [IssuePhotoApiController::class, 'show'])->middleware('can:issue.view');
    Route::delete('/issue-photos/{issuePhoto}', [IssuePhotoApiController::class, 'destroy'])->middleware('can:issue.manage');
});

Route::middleware('feature:measurement')->group(function () {
    Route::get('/measurements/{measurement}/attachments', [MeasurementAttachmentApiController::class, 'index'])->middleware('can:measurement.view');
    Route::post('/measurements/{measurement}/attachments', [MeasurementAttachmentApiController::class, 'store'])->middleware('can:measurement.create');
    Route::get('/measurement-attachments/{attachment}', [MeasurementAttachmentApiController::class, 'show'])->middleware('can:measurement.view');
    Route::delete('/measurement-attachments/{attachment}', [MeasurementAttachmentApiController::class, 'destroy'])->middleware('can:measurement.create');
});
