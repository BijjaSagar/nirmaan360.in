<?php

use App\Http\Controllers\Notify\AnnouncementController;
use App\Http\Controllers\Notify\CompanyDataController;
use App\Http\Controllers\Notify\IntegrationController;
use App\Http\Controllers\Notify\NotificationPreferenceController;
use App\Http\Controllers\Notify\SelfieAttendanceController;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Services\Notify\Integrations;
use Illuminate\Support\Facades\Route;

// Each person's own channels, so no permission is needed beyond being signed in.
Route::get('/notification-settings', [NotificationPreferenceController::class, 'edit'])->name('notify.preferences');
Route::patch('/notification-settings', [NotificationPreferenceController::class, 'update'])->name('notify.preferences.update');

// The same page for a contractor login. Its gate lets through only routes named contractor-portal.*, so it lives there.
Route::middleware(['feature:contractor_portal', 'can:contractor_portal.view'])->prefix('contractor-portal')->name('contractor-portal.')->group(function () {
    Route::get('/notification-settings', [NotificationPreferenceController::class, 'edit'])->name('notifications');
    Route::patch('/notification-settings', [NotificationPreferenceController::class, 'update'])->name('notifications.update');
});

Route::prefix('admin')->name('admin.')->middleware(EnsurePlatformAdmin::class)->controller(IntegrationController::class)->group(function () {
    Route::get('/integrations', 'index')->name('integrations');
    Route::patch('/integrations/{integration}', 'update')->whereIn('integration', Integrations::NAMES)->name('integrations.update');
    Route::post('/integrations/{integration}/test', 'test')->whereIn('integration', Integrations::NAMES)->name('integrations.test')->middleware('throttle:10,1');
    // A real push to the admin's own phones, to prove the whole path once the dry run passes.
    Route::post('/integrations/fcm/send-to-me', 'sendTestPush')->name('integrations.push-test')->middleware('throttle:6,1');
    // Reloading a test result, or a browser restoring the tab, asks for these addresses with GET. Send it back to the page.
    Route::get('/integrations/{integration}/test', 'backToPage')->whereIn('integration', Integrations::NAMES)->name('integrations.test.back');
    Route::get('/integrations/fcm/send-to-me', 'backToPage')->name('integrations.push-test.back');
});

// Broadcasts and reminders. Platform staff write to any company; the form posts a file, so it is a plain POST.
Route::prefix('admin')->name('admin.')->middleware(EnsurePlatformAdmin::class)->controller(AnnouncementController::class)->group(function () {
    Route::get('/announcements', 'index')->name('announcements');
    Route::post('/announcements', 'store')->name('announcements.store')->middleware('throttle:20,1');
    Route::post('/announcements/{announcement}/cancel', 'cancel')->whereNumber('announcement')->name('announcements.cancel');
    Route::post('/announcements/{announcement}/retry', 'retry')->whereNumber('announcement')->name('announcements.retry')->middleware('throttle:20,1');
});

// A company's own admin writes to their own company only. The controller checks each announcement is theirs.
Route::middleware('can:settings.manage')->controller(AnnouncementController::class)->group(function () {
    Route::get('/announcements', 'index')->name('notify.announcements');
    Route::post('/announcements', 'store')->name('notify.announcements.store')->middleware('throttle:20,1');
    Route::post('/announcements/{announcement}/cancel', 'cancel')->whereNumber('announcement')->name('notify.announcements.cancel');
    Route::post('/announcements/{announcement}/retry', 'retry')->whereNumber('announcement')->name('notify.announcements.retry')->middleware('throttle:20,1');
});

// Selfies are personal data, so viewing one needs the manage permission and not just attendance.view.
Route::middleware(['feature:labour', 'can:attendance.manage'])->group(function () {
    Route::get('/projects/{project}/attendance/selfie', [SelfieAttendanceController::class, 'index'])->name('notify.selfie.index');
    Route::post('/projects/{project}/attendance/selfie', [SelfieAttendanceController::class, 'store'])->name('notify.selfie.store');
    Route::get('/attendance-selfies/{selfie}/photo', [SelfieAttendanceController::class, 'photo'])->name('notify.selfie.photo');
    Route::delete('/attendance-selfies/{selfie}', [SelfieAttendanceController::class, 'destroy'])->name('notify.selfie.destroy');
});

// Owner-only actions are enforced in the controller, because owner and admin share the '*' permission.
Route::middleware('can:settings.manage')->group(function () {
    Route::get('/company-data', [CompanyDataController::class, 'index'])->name('notify.data.index');
    Route::post('/company-data/export', [CompanyDataController::class, 'export'])->name('notify.data.export')->middleware('throttle:5,60');
    Route::get('/company-data/exports/{export}', [CompanyDataController::class, 'download'])->name('notify.data.download');
    Route::post('/company-data/deletion', [CompanyDataController::class, 'requestDeletion'])->name('notify.data.delete')->middleware('throttle:10,1');
    Route::delete('/company-data/deletion', [CompanyDataController::class, 'cancelDeletion'])->name('notify.data.cancel');
});
