<?php

use App\Http\Controllers\DocumentReading\AiDocumentSettingsController;
use Illuminate\Support\Facades\Route;

// The company's own switch for sending documents to the AI service, and its spending limits.
// settings.manage is held by owners and admins only; a project manager cannot switch it.
Route::middleware(['feature:ai_agents', 'can:settings.manage'])->controller(AiDocumentSettingsController::class)->group(function () {
    Route::get('/settings/ai-documents', 'edit')->name('ai-documents.edit');
    Route::post('/settings/ai-documents', 'update')->name('ai-documents.update')->middleware('throttle:30,1,ai-documents');
});
