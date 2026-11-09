<?php

use App\Http\Controllers\MaterialControl\ConsumptionController;
use App\Http\Controllers\MaterialControl\DeliveryChallanController;
use App\Http\Controllers\MaterialControl\ExtraWorkController;
use App\Http\Controllers\MaterialControl\MaterialCheckController;
use App\Http\Controllers\MaterialControl\StockCheckController;
use App\Http\Controllers\MaterialControl\SupplierController;
use Illuminate\Support\Facades\Route;

Route::middleware('feature:site_control')->name('material_control.')->group(function () {
    // Three-way check
    Route::get('/projects/{project}/material-control', [MaterialCheckController::class, 'index'])->middleware('can:material_control.view')->name('index');
    Route::post('/projects/{project}/material-control/reasons', [MaterialCheckController::class, 'storeReason'])->middleware('can:material_control.reason')->name('reasons.store');

    // Delivery challans with no purchase order
    Route::get('/projects/{project}/material-control/challans', [DeliveryChallanController::class, 'index'])->middleware('can:material_control.view')->name('challans.index');
    Route::get('/projects/{project}/material-control/challans/create', [DeliveryChallanController::class, 'create'])->middleware('can:material_control.challan')->name('challans.create');
    Route::post('/projects/{project}/material-control/challans', [DeliveryChallanController::class, 'store'])->middleware('can:material_control.challan')->name('challans.store');
    Route::get('/delivery-challans/{deliveryChallan}', [DeliveryChallanController::class, 'show'])->middleware('can:material_control.view')->name('challans.show');
    Route::get('/delivery-challans/{deliveryChallan}/photo', [DeliveryChallanController::class, 'photo'])->middleware('can:material_control.view')->name('challans.photo');
    Route::get('/delivery-challans/{deliveryChallan}/bill', [DeliveryChallanController::class, 'bill'])->middleware(['can:material_control.view', 'can:money.view'])->name('challans.bill');
    Route::post('/delivery-challans/{deliveryChallan}/bill', [DeliveryChallanController::class, 'attachBill'])->middleware('can:material_control.bills')->name('challans.bill.store');
    Route::post('/delivery-challans/{deliveryChallan}/short', [DeliveryChallanController::class, 'reportShort'])->middleware('can:material_control.challan')->name('challans.short.store');
    Route::post('/delivery-challans/{deliveryChallan}/reverse', [DeliveryChallanController::class, 'reverse'])->middleware('can:material_control.adjust')->name('challans.reverse');
    Route::post('/material-short-deliveries/{shortDelivery}/reverse', [DeliveryChallanController::class, 'reverseShort'])->middleware('can:material_control.adjust')->name('shorts.reverse');

    // Bill pending list for the accountant
    Route::get('/projects/{project}/material-control/bills', [DeliveryChallanController::class, 'bills'])->middleware('can:material_control.bills')->name('bills.index');

    // Use on BOQ work
    Route::get('/projects/{project}/material-control/use', [ConsumptionController::class, 'index'])->middleware('can:material_control.view')->name('consumption.index');
    Route::post('/projects/{project}/material-control/use', [ConsumptionController::class, 'store'])->middleware('can:material_control.consume')->name('consumption.store');

    // Stock on site and physical counts
    Route::get('/projects/{project}/material-control/stock', [StockCheckController::class, 'index'])->middleware('can:material_control.view')->name('stock.index');
    Route::post('/projects/{project}/material-control/stock', [StockCheckController::class, 'store'])->middleware('can:material_control.count')->name('stock.store');
    Route::post('/material-stock-checks/{stockCheck}/reverse', [StockCheckController::class, 'reverse'])->middleware('can:material_control.adjust')->name('stock.reverse');

    // Extra work
    Route::get('/projects/{project}/material-control/extra-work', [ExtraWorkController::class, 'index'])->middleware('can:material_control.view')->name('extra.index');
    Route::post('/projects/{project}/material-control/extra-work', [ExtraWorkController::class, 'store'])->middleware('can:material_control.extra')->name('extra.store');
    Route::post('/material-extra-works/{extraWork}/reverse', [ExtraWorkController::class, 'reverse'])->middleware('can:material_control.adjust')->name('extra.reverse');

    // Supplier records
    Route::get('/material-control/suppliers', [SupplierController::class, 'index'])->middleware('can:material_control.view')->name('suppliers.index');
    Route::get('/material-control/suppliers/{party}', [SupplierController::class, 'show'])->middleware('can:material_control.view')->name('suppliers.show');
    Route::patch('/material-control/suppliers/{party}', [SupplierController::class, 'update'])->middleware('can:material_control.suppliers')->name('suppliers.update');
});
