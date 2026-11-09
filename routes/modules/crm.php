<?php

use App\Http\Controllers\Crm\ActivityController;
use App\Http\Controllers\Crm\CompanyController;
use App\Http\Controllers\Crm\CustomFieldController;
use App\Http\Controllers\Crm\DashboardController;
use App\Http\Controllers\Crm\LeadController;
use App\Http\Controllers\Crm\MessageController;
use App\Http\Controllers\Crm\ReasonController;
use App\Http\Controllers\Crm\ReportController;
use App\Http\Controllers\Crm\SetupController;
use App\Http\Controllers\Crm\TaskController;
use App\Http\Controllers\Crm\TemplateController;
use App\Http\Controllers\Crm\TenderBoqController;
use App\Http\Controllers\Crm\TenderController;
use App\Http\Controllers\Crm\TenderConvertController;
use App\Http\Controllers\Crm\TenderDocumentController;
use App\Http\Controllers\Crm\TenderPaymentController;
use Illuminate\Support\Facades\Route;

// CRM and tenders (FR-CRM-01, 02, 03, 05). Loaded inside the signed-in, active-user group in routes/web.php.
Route::middleware('feature:crm')->prefix('crm')->name('crm.')->group(function () {
    // Writing comes first so /tenders/create is not read as a tender id.
    Route::middleware('can:crm.manage')->group(function () {
        Route::get('/tenders/create', [TenderController::class, 'create'])->name('tenders.create');
        Route::post('/tenders', [TenderController::class, 'store'])->name('tenders.store');
        Route::get('/tenders/{tender}/edit', [TenderController::class, 'edit'])->name('tenders.edit');
        Route::patch('/tenders/{tender}', [TenderController::class, 'update'])->name('tenders.update');
        Route::delete('/tenders/{tender}', [TenderController::class, 'destroy'])->name('tenders.destroy');
        Route::post('/tenders/{tender}/move', [TenderController::class, 'move'])->name('tenders.move');
        Route::post('/tenders/{tender}/close', [TenderController::class, 'close'])->name('tenders.close');
        Route::post('/tenders/{tender}/reopen', [TenderController::class, 'reopen'])->name('tenders.reopen');

        Route::post('/tenders/{tender}/boq', [TenderBoqController::class, 'store'])->name('boq.store');
        Route::post('/tenders/{tender}/boq/import', [TenderBoqController::class, 'import'])->name('boq.import');
        Route::patch('/boq-lines/{line}', [TenderBoqController::class, 'update'])->name('boq.update');
        Route::delete('/boq-lines/{line}', [TenderBoqController::class, 'destroy'])->name('boq.destroy');

        Route::post('/tenders/{tender}/documents', [TenderDocumentController::class, 'store'])->name('documents.store');
        Route::delete('/documents/{document}', [TenderDocumentController::class, 'destroy'])->name('documents.destroy');

        Route::get('/leads/create', [LeadController::class, 'create'])->name('leads.create');
        Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
        Route::get('/leads/{lead}/edit', [LeadController::class, 'edit'])->name('leads.edit');
        Route::patch('/leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
        Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');
        Route::post('/leads/{lead}/status', [LeadController::class, 'status'])->name('leads.status');
        Route::post('/leads/{lead}/convert', [LeadController::class, 'convert'])->name('leads.convert');

        Route::get('/companies/create', [CompanyController::class, 'create'])->name('companies.create');
        Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::get('/companies/{party}/edit', [CompanyController::class, 'edit'])->name('companies.edit');
        Route::patch('/companies/{party}', [CompanyController::class, 'update'])->name('companies.update');

        Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
        Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
        Route::post('/tasks/{task}/done', [TaskController::class, 'complete'])->name('tasks.done');
        Route::post('/tasks/{task}/reopen', [TaskController::class, 'reopen'])->name('tasks.reopen');
        Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');

        Route::post('/activities', [ActivityController::class, 'store'])->name('activities.store');
        Route::post('/messages/log', [MessageController::class, 'log'])->name('messages.log');

        Route::post('/setup/templates', [TemplateController::class, 'store'])->name('templates.store');
        Route::patch('/setup/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
        Route::delete('/setup/templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
        Route::post('/setup/reasons', [ReasonController::class, 'store'])->name('reasons.store');
        Route::patch('/setup/reasons/{reason}', [ReasonController::class, 'update'])->name('reasons.update');
        Route::post('/setup/fields', [CustomFieldController::class, 'store'])->name('fields.store');
        Route::patch('/setup/fields/{field}', [CustomFieldController::class, 'update'])->name('fields.update');
        Route::get('/setup', SetupController::class)->name('setup');
    });

    // Turning a won tender into a project also needs the right to create projects.
    Route::middleware(['can:crm.convert', 'can:project.manage'])->group(function () {
        Route::get('/tenders/{tender}/convert', [TenderConvertController::class, 'show'])->name('tenders.convert');
        Route::post('/tenders/{tender}/convert', [TenderConvertController::class, 'store'])->name('tenders.convert.store');
    });

    // EMD and fees are recorded by accounts as well as the tender team.
    Route::middleware('can:crm.emd')->group(function () {
        Route::post('/tenders/{tender}/payments', [TenderPaymentController::class, 'store'])->name('payments.store');
        Route::patch('/payments/{payment}', [TenderPaymentController::class, 'update'])->name('payments.update');
        Route::post('/payments/{payment}/settle', [TenderPaymentController::class, 'settle'])->name('payments.settle');
        Route::post('/payments/{payment}/undo', [TenderPaymentController::class, 'undo'])->name('payments.undo');
        Route::delete('/payments/{payment}', [TenderPaymentController::class, 'destroy'])->name('payments.destroy');
    });

    Route::middleware('can:crm.view')->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('/tenders', [TenderController::class, 'index'])->name('tenders.index');
        Route::get('/tenders/{tender}', [TenderController::class, 'show'])->name('tenders.show');
        Route::get('/documents/{document}', [TenderDocumentController::class, 'download'])->name('documents.download');
        Route::get('/projects/{project}/tender-file', [TenderDocumentController::class, 'project'])->name('project-file');

        Route::get('/payments', [TenderPaymentController::class, 'index'])->name('payments.index');

        Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
        Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('leads.show');

        Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::get('/companies/{party}', [CompanyController::class, 'show'])->name('companies.show');

        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');

        Route::get('/messages/compose', [MessageController::class, 'compose'])->name('messages.compose');

        Route::get('/reports/win-loss', [ReportController::class, 'winLoss'])->name('reports.win-loss');
    });
});
