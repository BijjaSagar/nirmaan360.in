<?php

use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\BoqController;
use App\Http\Controllers\ClientSignoffController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DprController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MeasurementController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PartyController;
use App\Http\Controllers\PettyCashController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\RaBillController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\WorkerController;
use App\Http\Controllers\WorkOrderController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicController::class, 'home'])->name('home');
Route::get('/calculators', [PublicController::class, 'calculators'])->name('calculators');
Route::get('/locale/{locale}', LocaleController::class)->name('locale');

Route::middleware('signed')->group(function () {
    Route::get('/client/bills/{bill}', [ClientSignoffController::class, 'show'])->name('client.bill');
    Route::post('/client/bills/{bill}', [ClientSignoffController::class, 'act'])->name('client.bill.act')->middleware('throttle:20,1');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::get('/login/otp', [OtpController::class, 'show'])->name('otp.show');
    Route::post('/login/otp/send', [OtpController::class, 'send'])->name('otp.send')->middleware('throttle:5,1');
    Route::post('/login/otp/verify', [OtpController::class, 'verify'])->name('otp.verify')->middleware('throttle:10,1');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/notifications/read', NotificationController::class)->name('notifications.read');

    // Projects
    Route::middleware('can:project.view')->group(function () {
        Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    });
    Route::middleware('can:project.manage')->group(function () {
        Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
        Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
        Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        Route::post('/projects/{project}/sites', [ProjectController::class, 'storeSite'])->name('projects.sites.store');
    });
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->middleware('can:project.view')->name('projects.show');

    // BOQ
    Route::middleware('can:boq.view')->group(function () {
        Route::get('/projects/{project}/boq', [BoqController::class, 'index'])->name('boq.index');
        Route::get('/projects/{project}/boq/export', [BoqController::class, 'export'])->name('boq.export');
    });
    Route::get('/boq-template.csv', [BoqController::class, 'template'])->name('boq.template');
    Route::middleware('can:boq.manage')->group(function () {
        Route::post('/projects/{project}/boq/items', [BoqController::class, 'store'])->name('boq.store');
        Route::post('/projects/{project}/boq/import', [BoqController::class, 'import'])->name('boq.import');
        Route::patch('/boq-items/{item}', [BoqController::class, 'update'])->name('boq.update');
        Route::delete('/boq-items/{item}', [BoqController::class, 'destroy'])->name('boq.destroy');
    });

    // Site execution
    Route::middleware('feature:site')->group(function () {
        Route::get('/projects/{project}/dprs', [DprController::class, 'index'])->middleware('can:dpr.view')->name('dprs.index');
        Route::get('/projects/{project}/dprs/create', [DprController::class, 'create'])->middleware('can:dpr.create')->name('dprs.create');
        Route::post('/projects/{project}/dprs', [DprController::class, 'store'])->middleware('can:dpr.create')->name('dprs.store');
        Route::get('/dprs/{dpr}', [DprController::class, 'show'])->middleware('can:dpr.view')->name('dprs.show');
        Route::post('/dprs/{dpr}/submit', [DprController::class, 'submit'])->middleware('can:dpr.create')->name('dprs.submit');
        Route::get('/dpr-photos/{photo}', [DprController::class, 'photo'])->middleware('can:dpr.view')->name('dprs.photo');

        Route::get('/projects/{project}/issues', [IssueController::class, 'index'])->middleware('can:issue.view')->name('issues.index');
        Route::post('/projects/{project}/issues', [IssueController::class, 'store'])->middleware('can:issue.manage')->name('issues.store');
        Route::patch('/issues/{issue}', [IssueController::class, 'update'])->middleware('can:issue.manage')->name('issues.update');
    });

    // Labour
    Route::middleware('feature:labour')->group(function () {
        Route::get('/workers', [WorkerController::class, 'index'])->middleware('can:worker.view')->name('workers.index');
        Route::post('/workers', [WorkerController::class, 'store'])->middleware('can:worker.manage')->name('workers.store');
        Route::patch('/workers/{worker}', [WorkerController::class, 'update'])->middleware('can:worker.manage')->name('workers.update');

        Route::get('/projects/{project}/attendance', [AttendanceController::class, 'index'])->middleware('can:attendance.view')->name('attendance.index');
        Route::post('/projects/{project}/attendance', [AttendanceController::class, 'store'])->middleware('can:attendance.manage')->name('attendance.store');
    });

    // Petty cash
    Route::middleware('feature:petty_cash')->group(function () {
        Route::get('/projects/{project}/petty-cash', [PettyCashController::class, 'index'])->middleware('can:petty_cash.view')->name('petty.index');
        Route::post('/projects/{project}/petty-cash/wallets', [PettyCashController::class, 'storeWallet'])->middleware('can:petty_cash.manage')->name('petty.wallets.store');
        Route::post('/petty-wallets/{wallet}/entries', [PettyCashController::class, 'storeEntry'])->middleware('can:petty_cash.manage')->name('petty.entries.store');
        Route::get('/petty-entries/{entry}/bill', [PettyCashController::class, 'bill'])->middleware('can:petty_cash.view')->name('petty.bill');
    });

    // Measurement book
    Route::middleware('feature:measurement')->group(function () {
        Route::get('/projects/{project}/measurements', [MeasurementController::class, 'index'])->middleware('can:measurement.view')->name('measurements.index');
        Route::get('/projects/{project}/measurements/create', [MeasurementController::class, 'create'])->middleware('can:measurement.create')->name('measurements.create');
        Route::post('/projects/{project}/measurements', [MeasurementController::class, 'store'])->middleware('can:measurement.create')->name('measurements.store');
        Route::get('/measurements/{measurement}', [MeasurementController::class, 'show'])->middleware('can:measurement.view')->name('measurements.show');
        Route::get('/measurements/{measurement}/edit', [MeasurementController::class, 'edit'])->middleware('can:measurement.create')->name('measurements.edit');
        Route::patch('/measurements/{measurement}', [MeasurementController::class, 'update'])->middleware('can:measurement.create')->name('measurements.update');
        Route::delete('/measurements/{measurement}', [MeasurementController::class, 'destroy'])->middleware('can:measurement.create')->name('measurements.destroy');
        Route::post('/measurements/{measurement}/submit', [MeasurementController::class, 'submit'])->middleware('can:measurement.create')->name('measurements.submit');
        Route::post('/measurements/{measurement}/reverse', [MeasurementController::class, 'reverse'])->middleware('can:measurement.create')->name('measurements.reverse');
    });

    // Approvals
    Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('/approvals/{approval}/act', [ApprovalController::class, 'act'])->name('approvals.act');

    // Billing
    Route::middleware('feature:billing')->group(function () {
        Route::get('/projects/{project}/work-orders', [WorkOrderController::class, 'index'])->middleware('can:work_order.view')->name('work-orders.index');
        Route::get('/projects/{project}/work-orders/create', [WorkOrderController::class, 'create'])->middleware('can:work_order.manage')->name('work-orders.create');
        Route::post('/projects/{project}/work-orders', [WorkOrderController::class, 'store'])->middleware('can:work_order.manage')->name('work-orders.store');
        Route::get('/work-orders/{workOrder}', [WorkOrderController::class, 'show'])->middleware('can:work_order.view')->name('work-orders.show');

        Route::get('/projects/{project}/ra-bills', [RaBillController::class, 'index'])->middleware('can:ra_bill.view')->name('ra-bills.index');
        Route::get('/projects/{project}/ra-bills/create', [RaBillController::class, 'create'])->middleware('can:ra_bill.generate')->name('ra-bills.create');
        Route::post('/projects/{project}/ra-bills', [RaBillController::class, 'store'])->middleware('can:ra_bill.generate')->name('ra-bills.store');
        Route::get('/ra-bills/{bill}', [RaBillController::class, 'show'])->middleware('can:ra_bill.view')->name('ra-bills.show');
        Route::get('/ra-bills/{bill}/pdf', [RaBillController::class, 'pdf'])->middleware('can:ra_bill.view')->name('ra-bills.pdf');
        Route::post('/ra-bills/{bill}/submit', [RaBillController::class, 'submit'])->middleware('can:ra_bill.generate')->name('ra-bills.submit');
        Route::post('/ra-bills/{bill}/cancel', [RaBillController::class, 'cancel'])->middleware('can:ra_bill.generate')->name('ra-bills.cancel');
        Route::post('/ra-bills/{bill}/paid', [RaBillController::class, 'paid'])->middleware('can:ra_bill.approve')->name('ra-bills.paid');
    });

    // Parties
    Route::middleware('feature:parties')->group(function () {
        Route::get('/parties', [PartyController::class, 'index'])->middleware('can:party.view')->name('parties.index');
        Route::post('/parties', [PartyController::class, 'store'])->middleware('can:party.manage')->name('parties.store');
        Route::patch('/parties/{party}', [PartyController::class, 'update'])->middleware('can:party.manage')->name('parties.update');
    });

    // Organisation admin
    Route::middleware('can:user.manage')->group(function () {
        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
        Route::post('/team', [TeamController::class, 'store'])->name('team.store');
        Route::patch('/team/{user}', [TeamController::class, 'update'])->name('team.update');
    });

    Route::middleware('can:settings.manage')->group(function () {
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::patch('/settings/organization', [SettingsController::class, 'updateOrganization'])->name('settings.organization');
        Route::patch('/settings/approvals', [SettingsController::class, 'updateApprovals'])->name('settings.approvals');
    });

    Route::get('/audit', AuditController::class)->middleware('can:audit.view')->name('audit.index');
});
