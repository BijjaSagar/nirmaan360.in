<?php

use App\Http\Controllers\Finance\BudgetController;
use App\Http\Controllers\Finance\GstInvoiceController;
use App\Http\Controllers\Finance\LedgerController;
use App\Http\Controllers\Finance\ReportController;
use App\Http\Controllers\Finance\SettlementController;
use App\Http\Controllers\Finance\TallyController;
use App\Http\Controllers\Finance\VendorBillController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:finance')->prefix('finance')->name('finance.')->group(function () {
    Route::name('project.')->group(function () {
        Route::middleware('can:finance.view')->group(function () {
            // The menu's way in: the ledger for those who may see the project's whole spend, vendor bills otherwise.
            Route::get('/projects/{project}', [LedgerController::class, 'home'])->name('home');

            // The ledger lays out everything billed, paid and spent on the project, and the budget screen sets
            // budget against actual cost and margin. That is the overall spend, which needs money.overview:
            // the accountant keeps vendor bills, payments, receipts and invoices, but not these two.
            Route::middleware('can:money.overview')->group(function () {
                Route::get('/projects/{project}/ledger', [LedgerController::class, 'index'])->name('ledger');
                Route::get('/projects/{project}/budget', [BudgetController::class, 'index'])->name('budget');
            });

            Route::get('/projects/{project}/vendor-bills', [VendorBillController::class, 'index'])->name('vendor-bills.index');
            Route::get('/projects/{project}/payments', [SettlementController::class, 'payments'])->name('payments');
            Route::get('/projects/{project}/receipts', [SettlementController::class, 'receipts'])->name('receipts');
            Route::get('/projects/{project}/invoices', [GstInvoiceController::class, 'index'])->name('invoices');
            Route::get('/invoices/{invoice}/pdf', [GstInvoiceController::class, 'pdf'])->name('invoices.pdf');
        });

        Route::middleware('can:finance.manage')->group(function () {
            Route::patch('/projects/{project}/budget', [BudgetController::class, 'update'])->middleware('can:money.overview')->name('budget.update');

            Route::get('/projects/{project}/vendor-bills/create', [VendorBillController::class, 'create'])->name('vendor-bills.create');
            Route::post('/projects/{project}/vendor-bills', [VendorBillController::class, 'store'])->name('vendor-bills.store');
            Route::get('/vendor-bills/{vendorBill}/edit', [VendorBillController::class, 'edit'])->name('vendor-bills.edit');
            Route::patch('/vendor-bills/{vendorBill}', [VendorBillController::class, 'update'])->name('vendor-bills.update');
            Route::post('/vendor-bills/{vendorBill}/submit', [VendorBillController::class, 'submit'])->name('vendor-bills.submit');
            Route::post('/vendor-bills/{vendorBill}/cancel', [VendorBillController::class, 'cancel'])->name('vendor-bills.cancel');

            Route::post('/vendor-bills/{vendorBill}/payments', [SettlementController::class, 'payVendorBill'])->name('payments.vendor');
            Route::post('/ra-bills/{raBill}/payments', [SettlementController::class, 'paySubcontractorBill'])->name('payments.subcontractor');
            Route::post('/payments/{payment}/reverse', [SettlementController::class, 'reversePayment'])->name('payments.reverse');
            Route::post('/ra-bills/{raBill}/receipts', [SettlementController::class, 'storeReceipt'])->name('receipts.store');
            Route::post('/receipts/{receipt}/reverse', [SettlementController::class, 'reverseReceipt'])->name('receipts.reverse');

            Route::post('/ra-bills/{raBill}/invoice', [GstInvoiceController::class, 'store'])->name('invoices.store');
            Route::patch('/invoices/{invoice}/irn', [GstInvoiceController::class, 'irn'])->name('invoices.irn');
            Route::post('/invoices/{invoice}/cancel', [GstInvoiceController::class, 'cancel'])->name('invoices.cancel');
        });

        Route::post('/vendor-bills/{vendorBill}/reverse', [VendorBillController::class, 'reverse'])->middleware('can:vendor_bill.approve')->name('vendor-bills.reverse');
        Route::get('/vendor-bills/{vendorBill}', [VendorBillController::class, 'show'])->middleware('can:finance.view')->name('vendor-bills.show');
    });

    Route::middleware('can:finance.report')->prefix('reports')->name('reports.')->group(function () {
        Route::get('/gstr-1', [ReportController::class, 'gstr1'])->name('gstr1');
        Route::get('/gstr-1/excel', [ReportController::class, 'gstr1Excel'])->name('gstr1.excel');
        Route::get('/gstr-1/json', [ReportController::class, 'gstr1Json'])->name('gstr1.json');
        Route::get('/tds', [ReportController::class, 'tds'])->name('tds');
        Route::get('/tds/excel', [ReportController::class, 'tdsExcel'])->name('tds.excel');
        Route::get('/cash-flow', [ReportController::class, 'cashFlow'])->name('cash-flow');
        Route::get('/tally', [TallyController::class, 'index'])->name('tally');
        Route::get('/tally/vouchers', [TallyController::class, 'vouchers'])->name('tally.vouchers');
        Route::get('/tally/ledgers', [TallyController::class, 'ledgers'])->name('tally.ledgers');
    });
});
