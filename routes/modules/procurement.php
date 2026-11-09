<?php

use App\Http\Controllers\Procurement\GrnController;
use App\Http\Controllers\Procurement\IndentController;
use App\Http\Controllers\Procurement\MaterialController;
use App\Http\Controllers\Procurement\MaterialIssueController;
use App\Http\Controllers\Procurement\MaterialReturnController;
use App\Http\Controllers\Procurement\PurchaseOrderController;
use App\Http\Controllers\Procurement\ReconciliationController;
use App\Http\Controllers\Procurement\RfqController;
use App\Http\Controllers\Procurement\StockController;
use App\Http\Controllers\Procurement\StockTransferController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:procurement')->name('procurement.')->group(function () {
    // Masters
    Route::get('/procurement/materials', [MaterialController::class, 'index'])->middleware('can:procurement.view')->name('materials.index');
    Route::get('/procurement/materials/{material}', [MaterialController::class, 'show'])->middleware('can:procurement.view')->name('materials.show');
    Route::middleware('can:procurement.masters')->group(function () {
        Route::post('/procurement/materials', [MaterialController::class, 'store'])->name('materials.store');
        Route::patch('/procurement/materials/{material}', [MaterialController::class, 'update'])->name('materials.update');
        Route::post('/procurement/materials/{material}/conversions', [MaterialController::class, 'storeConversion'])->name('materials.conversions.store');
        Route::delete('/procurement/conversions/{conversion}', [MaterialController::class, 'destroyConversion'])->name('materials.conversions.destroy');
        Route::post('/procurement/units', [MaterialController::class, 'storeUnit'])->name('materials.units.store');
    });

    // Stores and stock
    Route::get('/projects/{project}/stock', [StockController::class, 'index'])->middleware('can:procurement.view')->name('stock.index');
    Route::get('/stores/{store}/materials/{material}', [StockController::class, 'card'])->middleware('can:procurement.view')->name('stock.card');
    Route::post('/projects/{project}/stores', [StockController::class, 'storeStore'])->middleware('can:procurement.masters')->name('stock.stores.store');
    Route::patch('/stores/{store}/materials/{material}/reorder', [StockController::class, 'reorder'])->middleware('can:procurement.masters')->name('stock.reorder');
    Route::post('/projects/{project}/stock/adjustments', [StockController::class, 'adjust'])->middleware('can:procurement.adjust')->name('stock.adjustments.store');
    Route::post('/stock-adjustments/{adjustment}/reverse', [StockController::class, 'reverseAdjustment'])->middleware('can:procurement.adjust')->name('stock.adjustments.reverse');

    // Indents
    Route::get('/projects/{project}/indents', [IndentController::class, 'index'])->middleware('can:procurement.view')->name('indents.index');
    Route::get('/projects/{project}/indents/create', [IndentController::class, 'create'])->middleware('can:indent.create')->name('indents.create');
    Route::post('/projects/{project}/indents', [IndentController::class, 'store'])->middleware('can:indent.create')->name('indents.store');
    Route::get('/indents/{indent}', [IndentController::class, 'show'])->middleware('can:procurement.view')->name('indents.show');
    Route::get('/indents/{indent}/edit', [IndentController::class, 'edit'])->middleware('can:indent.create')->name('indents.edit');
    Route::patch('/indents/{indent}', [IndentController::class, 'update'])->middleware('can:indent.create')->name('indents.update');
    Route::post('/indents/{indent}/submit', [IndentController::class, 'submit'])->middleware('can:indent.create')->name('indents.submit');

    // RFQs and quotations
    Route::get('/projects/{project}/rfqs', [RfqController::class, 'index'])->middleware('can:procurement.view')->name('rfqs.index');
    Route::get('/rfqs/{rfq}', [RfqController::class, 'show'])->middleware('can:procurement.view')->name('rfqs.show');
    Route::middleware('can:procurement.purchase')->group(function () {
        Route::post('/indents/{indent}/rfqs', [RfqController::class, 'store'])->name('rfqs.store');
        Route::post('/rfqs/{rfq}/vendors', [RfqController::class, 'addVendor'])->name('rfqs.vendors.store');
        Route::post('/rfqs/{rfq}/quotations', [RfqController::class, 'saveQuotation'])->name('rfqs.quotations.store');
        Route::post('/rfqs/{rfq}/award', [RfqController::class, 'award'])->name('rfqs.award');
        Route::post('/rfqs/{rfq}/close', [RfqController::class, 'close'])->name('rfqs.close');
    });

    // Purchase orders
    Route::get('/projects/{project}/purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('can:procurement.view')->name('purchase-orders.index');
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->middleware('can:procurement.view')->name('purchase-orders.show');
    Route::get('/purchase-orders/{purchaseOrder}/pdf', [PurchaseOrderController::class, 'pdf'])->middleware('can:procurement.view')->name('purchase-orders.pdf');
    Route::middleware('can:procurement.purchase')->group(function () {
        Route::patch('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->name('purchase-orders.update');
        Route::post('/purchase-orders/{purchaseOrder}/issue', [PurchaseOrderController::class, 'issue'])->name('purchase-orders.issue');
        Route::post('/purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])->name('purchase-orders.close');
    });

    // Goods receipts
    Route::get('/projects/{project}/grns', [GrnController::class, 'index'])->middleware('can:procurement.view')->name('grns.index');
    Route::get('/projects/{project}/grns/create', [GrnController::class, 'create'])->middleware('can:procurement.stock')->name('grns.create');
    Route::post('/projects/{project}/grns', [GrnController::class, 'store'])->middleware('can:procurement.stock')->name('grns.store');
    Route::get('/grns/{grn}', [GrnController::class, 'show'])->middleware('can:procurement.view')->name('grns.show');
    Route::post('/grns/{grn}/reverse', [GrnController::class, 'reverse'])->middleware('can:procurement.adjust')->name('grns.reverse');

    // Issues to work
    Route::get('/projects/{project}/material-issues', [MaterialIssueController::class, 'index'])->middleware('can:procurement.view')->name('issues.index');
    Route::get('/projects/{project}/material-issues/create', [MaterialIssueController::class, 'create'])->middleware('can:procurement.stock')->name('issues.create');
    Route::post('/projects/{project}/material-issues', [MaterialIssueController::class, 'store'])->middleware('can:procurement.stock')->name('issues.store');
    Route::get('/material-issues/{materialIssue}', [MaterialIssueController::class, 'show'])->middleware('can:procurement.view')->name('issues.show');
    Route::post('/material-issues/{materialIssue}/reverse', [MaterialIssueController::class, 'reverse'])->middleware('can:procurement.adjust')->name('issues.reverse');

    // Returns
    Route::get('/projects/{project}/material-returns', [MaterialReturnController::class, 'index'])->middleware('can:procurement.view')->name('returns.index');
    Route::get('/material-issues/{materialIssue}/returns/create', [MaterialReturnController::class, 'createFromIssue'])->middleware('can:procurement.stock')->name('returns.create-from-issue');
    Route::post('/material-issues/{materialIssue}/returns', [MaterialReturnController::class, 'storeFromIssue'])->middleware('can:procurement.stock')->name('returns.store-from-issue');
    Route::get('/grns/{grn}/returns/create', [MaterialReturnController::class, 'createFromGrn'])->middleware('can:procurement.stock')->name('returns.create-from-grn');
    Route::post('/grns/{grn}/returns', [MaterialReturnController::class, 'storeFromGrn'])->middleware('can:procurement.stock')->name('returns.store-from-grn');
    Route::get('/material-returns/{materialReturn}', [MaterialReturnController::class, 'show'])->middleware('can:procurement.view')->name('returns.show');
    Route::post('/material-returns/{materialReturn}/reverse', [MaterialReturnController::class, 'reverse'])->middleware('can:procurement.adjust')->name('returns.reverse');

    // Site transfers
    Route::get('/projects/{project}/stock-transfers', [StockTransferController::class, 'index'])->middleware('can:procurement.view')->name('transfers.index');
    Route::get('/projects/{project}/stock-transfers/create', [StockTransferController::class, 'create'])->middleware('can:procurement.stock')->name('transfers.create');
    Route::post('/projects/{project}/stock-transfers', [StockTransferController::class, 'store'])->middleware('can:procurement.stock')->name('transfers.store');
    Route::get('/stock-transfers/{stockTransfer}', [StockTransferController::class, 'show'])->middleware('can:procurement.view')->name('transfers.show');
    Route::post('/stock-transfers/{stockTransfer}/receive', [StockTransferController::class, 'receive'])->middleware('can:procurement.stock')->name('transfers.receive');
    Route::post('/stock-transfers/{stockTransfer}/reverse', [StockTransferController::class, 'reverse'])->middleware('can:procurement.adjust')->name('transfers.reverse');

    // Consumption against the BOQ
    Route::get('/projects/{project}/material-reconciliation', [ReconciliationController::class, 'index'])->middleware('can:procurement.view')->name('reconciliation.index');
    Route::post('/projects/{project}/material-norms', [ReconciliationController::class, 'storeNorm'])->middleware('can:procurement.masters')->name('reconciliation.norms.store');
    Route::delete('/material-norms/{norm}', [ReconciliationController::class, 'destroyNorm'])->middleware('can:procurement.masters')->name('reconciliation.norms.destroy');
});
