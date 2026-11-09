<?php

use App\Http\Controllers\OfflinePlan\OfflineDeviceController;
use Illuminate\Support\Facades\Route;

// The offline plan's page: plan status, the phones holding a licence and the upgrade prompt.
// Anyone signed in can open it, because OfflinePlanGate sends every other page here. Only billing managers free phones.
Route::get('/offline/devices', [OfflineDeviceController::class, 'index'])->name('offline.devices.index');
Route::post('/offline/devices/{deviceLicence}/free', [OfflineDeviceController::class, 'free'])->whereNumber('deviceLicence')->middleware('can:billing.manage')->name('offline.devices.free');
