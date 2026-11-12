<?php

use App\Http\Controllers\Access\AccountDeletionController;
use App\Http\Controllers\Access\CustomRoleController;
use App\Http\Controllers\Access\DeviceController;
use App\Http\Controllers\Access\InvitationController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

// Anyone signed in can see and sign out their own devices.
Route::get('/devices', [DeviceController::class, 'mine'])->name('devices.index');
Route::delete('/devices', [DeviceController::class, 'revokeAllMine'])->name('devices.destroy-all');
Route::delete('/devices/{token}', [DeviceController::class, 'revokeMine'])->whereNumber('token')->name('devices.destroy');

// Anyone signed in can delete their own account, contractor logins and the offline plan included.
Route::get('/account/delete', [AccountDeletionController::class, 'show'])->name('account.delete');
Route::post('/account/delete', [AccountDeletionController::class, 'destroy'])->middleware('throttle:5,1,account-deletion')->name('account.destroy');

Route::middleware('can:user.manage')->group(function () {
    Route::post('/team/invitations/{invitation}/reissue', [InvitationController::class, 'reissue'])->whereNumber('invitation')->middleware('throttle:20,1')->name('team.invitations.reissue');
    Route::delete('/team/invitations/{invitation}', [InvitationController::class, 'destroy'])->whereNumber('invitation')->name('team.invitations.destroy');

    Route::get('/team/roles', [CustomRoleController::class, 'index'])->name('team.roles.index');
    Route::get('/team/roles/create', [CustomRoleController::class, 'create'])->name('team.roles.create');
    Route::post('/team/roles', [CustomRoleController::class, 'store'])->name('team.roles.store');
    Route::get('/team/roles/{customRole}/edit', [CustomRoleController::class, 'edit'])->whereNumber('customRole')->name('team.roles.edit');
    Route::patch('/team/roles/{customRole}', [CustomRoleController::class, 'update'])->whereNumber('customRole')->name('team.roles.update');
    Route::delete('/team/roles/{customRole}', [CustomRoleController::class, 'destroy'])->whereNumber('customRole')->name('team.roles.destroy');

    Route::get('/team/{user}/access', [TeamController::class, 'edit'])->whereNumber('user')->name('team.access');
    Route::delete('/team/{user}/devices', [DeviceController::class, 'revokeAll'])->whereNumber('user')->name('team.devices.destroy-all');
    Route::delete('/team/{user}/devices/{token}', [DeviceController::class, 'revoke'])->whereNumber(['user', 'token'])->name('team.devices.destroy');
});
