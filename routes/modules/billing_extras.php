<?php

use App\Http\Controllers\BillingExtras\BillNoteController;
use App\Http\Controllers\BillingExtras\ContractPositionController;
use App\Http\Controllers\BillingExtras\FinalBillController;
use App\Http\Controllers\BillingExtras\IssuePhotoController;
use App\Http\Controllers\BillingExtras\MeasurementAttachmentController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:site')->group(function () {
    Route::post('/issues/{issue}/photos', [IssuePhotoController::class, 'store'])->middleware('can:issue.manage')->name('issue-photos.store');
    Route::get('/issue-photos/{issuePhoto}', [IssuePhotoController::class, 'show'])->middleware('can:issue.view')->name('issue-photos.show');
    Route::delete('/issue-photos/{issuePhoto}', [IssuePhotoController::class, 'destroy'])->middleware('can:issue.manage')->name('issue-photos.destroy');
});

Route::middleware('feature:measurement')->group(function () {
    Route::post('/measurements/{measurement}/attachments', [MeasurementAttachmentController::class, 'store'])->middleware('can:measurement.create')->name('measurement-attachments.store');
    Route::get('/measurement-attachments/{attachment}', [MeasurementAttachmentController::class, 'show'])->middleware('can:measurement.view')->name('measurement-attachments.show');
    Route::delete('/measurement-attachments/{attachment}', [MeasurementAttachmentController::class, 'destroy'])->middleware('can:measurement.create')->name('measurement-attachments.destroy');
});

Route::middleware('feature:billing')->group(function () {
    Route::get('/projects/{project}/contract-position', ContractPositionController::class)->middleware('can:ra_bill.view')->name('billing-extras.position');

    Route::get('/projects/{project}/final-bills/create', [FinalBillController::class, 'create'])->middleware('can:ra_bill.generate')->name('billing-extras.final-bills.create');
    Route::post('/projects/{project}/final-bills', [FinalBillController::class, 'store'])->middleware('can:ra_bill.generate')->name('billing-extras.final-bills.store');

    Route::get('/ra-bills/{bill}/notes/create', [BillNoteController::class, 'create'])->middleware('can:bill_note.manage')->name('bill-notes.create');
    Route::post('/ra-bills/{bill}/notes', [BillNoteController::class, 'store'])->middleware('can:bill_note.manage')->name('bill-notes.store');
    Route::get('/bill-notes/{note}', [BillNoteController::class, 'show'])->middleware('can:bill_note.view')->name('bill-notes.show');
    Route::get('/bill-notes/{note}/pdf', [BillNoteController::class, 'pdf'])->middleware('can:bill_note.view')->name('bill-notes.pdf');
    Route::post('/bill-notes/{note}/submit', [BillNoteController::class, 'submit'])->middleware('can:bill_note.manage')->name('bill-notes.submit');
    Route::post('/bill-notes/{note}/cancel', [BillNoteController::class, 'cancel'])->middleware('can:bill_note.manage')->name('bill-notes.cancel');
});
