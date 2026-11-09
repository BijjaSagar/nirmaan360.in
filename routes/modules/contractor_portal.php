<?php

use App\Http\Controllers\ContractorPortal\AccessController;
use App\Http\Controllers\ContractorPortal\PortalController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:contractor_portal')->prefix('contractor-portal')->name('contractor-portal.')->group(function () {
    // The portal itself. Only the contractor role holds contractor_portal.view, and ContractorPortalGate
    // keeps that role out of every other route, so these are the only pages a contractor login reaches.
    Route::middleware('can:contractor_portal.view')->controller(PortalController::class)->group(function () {
        Route::get('/', 'home')->name('home');
        Route::get('/work-orders/{workOrder}', 'contract')->whereNumber('workOrder')->name('contracts.show');
        Route::get('/payments', 'payments')->name('payments');
        Route::get('/bills', 'bills')->name('bills');
        Route::get('/bills/{bill}', 'bill')->whereNumber('bill')->name('bills.show');
        Route::get('/bills/{bill}/pdf', 'billPdf')->whereNumber('bill')->name('bills.pdf');
    });

    // Staff: who may sign in for a subcontractor, from that contractor's page.
    Route::middleware('can:contractor_portal.manage')->controller(AccessController::class)->group(function () {
        Route::get('/access/{party}', 'show')->whereNumber('party')->name('access.show');
        Route::post('/access/{party}/invitations', 'store')->whereNumber('party')->middleware('throttle:20,1')->name('access.invite');
        Route::post('/access/{party}/invitations/{invitation}/reissue', 'reissue')->whereNumber(['party', 'invitation'])->middleware('throttle:20,1')->name('access.reissue');
        Route::delete('/access/{party}/invitations/{invitation}', 'cancel')->whereNumber(['party', 'invitation'])->name('access.cancel');
        Route::post('/access/{party}/logins/{user}/revoke', 'revoke')->whereNumber(['party', 'user'])->name('access.revoke');
        Route::post('/access/{party}/logins/{user}/restore', 'restore')->whereNumber(['party', 'user'])->name('access.restore');
    });
});
