<?php

use App\Http\Controllers\OwnerPack\OwnerPackAiController;
use App\Http\Controllers\OwnerPack\OwnerUpdateController;
use App\Http\Controllers\OwnerPack\OwnerUpdateShareController;
use App\Http\Controllers\OwnerPack\QuotationController;
use App\Http\Controllers\OwnerPack\QuotationShareController;
use App\Http\Controllers\OwnerPack\QuotationStageController;
use App\Http\Controllers\OwnerPack\QuotationTranslationController;
use App\Http\Controllers\OwnerPack\SharedPackController;
use App\Http\Middleware\RequireAiReader;
use Illuminate\Support\Facades\Route;

// Home-owner pack. This file is included inside the signed-in, active-user group of routes/web.php.
//
// Three routes send something to the AI model and carry its guards: the AI plan, agents.use, RequireAiReader and
// the "ai-text" throttle, a counter of its own. Writing the two sentences of a cover note or an update sends only
// what each sentence is for and the names of the figures around it; translating sends the descriptions of a
// quotation's lines, which the company wrote for its own bill of quantities. Neither sends what staff wrote in
// daily reports, so both are ':text'. Accepting a translation calls no model and carries none of these guards.

// A quotation is nothing but money, so every route needs money.view beside the module's own permission.
Route::middleware(['feature:owner_pack', 'can:money.view'])->group(function () {
    Route::get('/projects/{project}/quotations', [QuotationController::class, 'index'])->middleware('can:owner_pack.view')->name('quotations.index');
    Route::get('/projects/{project}/quotations/create', [QuotationController::class, 'create'])->middleware('can:owner_pack.manage')->name('quotations.create');
    Route::post('/projects/{project}/quotations', [QuotationController::class, 'store'])->middleware('can:owner_pack.manage')->name('quotations.store');
    Route::get('/quotations/{quotation}', [QuotationController::class, 'show'])->middleware('can:owner_pack.view')->name('quotations.show');

    Route::middleware('can:owner_pack.manage')->group(function () {
        Route::patch('/quotations/{quotation}', [QuotationController::class, 'update'])->name('quotations.update');
        Route::post('/quotations/{quotation}/issue', [QuotationController::class, 'issue'])->name('quotations.issue');
        Route::post('/quotations/{quotation}/revise', [QuotationController::class, 'revise'])->name('quotations.revise');

        Route::post('/quotations/{quotation}/stages', [QuotationStageController::class, 'store'])->name('quotations.stages.store');
        Route::patch('/quotation-stages/{stage}', [QuotationStageController::class, 'update'])->name('quotation-stages.update');
        Route::delete('/quotation-stages/{stage}', [QuotationStageController::class, 'destroy'])->name('quotation-stages.destroy');
        Route::post('/quotation-stages/{stage}/reached', [QuotationStageController::class, 'reached'])->name('quotation-stages.reached');

        Route::post('/quotations/{quotation}/share', [QuotationShareController::class, 'share'])->name('quotations.share');
        Route::post('/quotations/{quotation}/share/revoke', [QuotationShareController::class, 'revoke'])->name('quotations.share.revoke');

        // A translation of a line's description is printed only once a person has accepted it, beside the description.
        Route::scopeBindings()->group(function () {
            Route::post('/quotations/{quotation}/items/{item}/translation/accept', [QuotationTranslationController::class, 'accept'])->name('quotations.translation.accept');
            Route::post('/quotations/{quotation}/items/{item}/translation/withdraw', [QuotationTranslationController::class, 'withdraw'])->name('quotations.translation.withdraw');
        });

        Route::post('/quotations/{quotation}/draft', [OwnerPackAiController::class, 'quotation'])->middleware(['feature:ai_agents', 'can:agents.use', RequireAiReader::class.':text', 'throttle:20,1,ai-text'])->name('quotations.draft');
        Route::post('/quotations/{quotation}/translate', [OwnerPackAiController::class, 'translate'])->middleware(['feature:ai_agents', 'can:agents.use', RequireAiReader::class.':text', 'throttle:20,1,ai-text'])->name('quotations.translate');
    });
});

// The weekly update. A site engineer may prepare one that carries no money (owner_pack.progress); the
// service refuses the payment position, and any update that states one, to a person without money.view.
// Nothing is sent to a client from here: approving and making the link both need a named person who
// manages the pack and may see money.
Route::middleware('feature:owner_pack')->group(function () {
    Route::middleware('can:owner_pack.progress')->group(function () {
        Route::get('/projects/{project}/owner-updates', [OwnerUpdateController::class, 'index'])->name('owner-updates.index');
        Route::get('/projects/{project}/owner-updates/create', [OwnerUpdateController::class, 'create'])->name('owner-updates.create');
        Route::post('/projects/{project}/owner-updates', [OwnerUpdateController::class, 'store'])->name('owner-updates.store');
        Route::get('/owner-updates/{update}', [OwnerUpdateController::class, 'show'])->name('owner-updates.show');
        Route::patch('/owner-updates/{update}', [OwnerUpdateController::class, 'update'])->name('owner-updates.update');
        Route::post('/owner-updates/{update}/draft', [OwnerPackAiController::class, 'update'])->middleware(['feature:ai_agents', 'can:agents.use', RequireAiReader::class.':text', 'throttle:20,1,ai-text'])->name('owner-updates.draft');
    });

    Route::middleware(['can:owner_pack.manage', 'can:money.view'])->group(function () {
        Route::post('/owner-updates/{update}/approve', [OwnerUpdateController::class, 'approve'])->name('owner-updates.approve');
        Route::post('/owner-updates/{update}/share', [OwnerUpdateShareController::class, 'share'])->name('owner-updates.share');
        Route::post('/owner-updates/{update}/share/revoke', [OwnerUpdateShareController::class, 'revoke'])->name('owner-updates.share.revoke');
    });
});

// A shared quotation is opened by a client with no account, so this one leaves the sign-in group.
// The signature proves the address was made here. The controller checks the token, the date, the
// withdrawal, the status and the company's plan itself. The throttle has its own counter, so opening
// a shared page never uses up the sign-in tries of that address.
Route::get('/shared/quotations/{id}/{token}', [SharedPackController::class, 'quotation'])
    ->whereNumber('id')
    ->where('token', '[A-Za-z0-9]{40}')
    ->withoutMiddleware(['auth', 'active'])
    ->middleware(['signed', 'throttle:30,1,owner-share'])
    ->name('quotations.shared');

// The shared weekly update, on the same terms. Its photos have a counter of their own, so a page with
// a dozen photos uses up neither the page's tries nor the sign-in tries of that address. A photo is
// served only while the same token is live and the update names that photo.
Route::get('/shared/owner-updates/{id}/{token}', [SharedPackController::class, 'update'])
    ->whereNumber('id')
    ->where('token', '[A-Za-z0-9]{40}')
    ->withoutMiddleware(['auth', 'active'])
    ->middleware(['signed', 'throttle:30,1,owner-share'])
    ->name('owner-updates.shared');

Route::get('/shared/owner-updates/{id}/{token}/photos/{photo}', [SharedPackController::class, 'photo'])
    ->whereNumber(['id', 'photo'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->withoutMiddleware(['auth', 'active'])
    ->middleware(['signed', 'throttle:120,1,owner-share-photo'])
    ->name('owner-updates.shared.photo');
