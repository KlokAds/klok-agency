<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\TrashController;
use App\Http\Controllers\Admin\UpdateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Books\BdtEntryController;
use App\Http\Controllers\Books\ClientController;
use App\Http\Controllers\Books\DashboardController;
use App\Http\Controllers\Books\FxBatchController;
use App\Http\Controllers\Books\PayrollController;
use App\Http\Controllers\Books\PlatformController;
use App\Http\Controllers\Books\QuickEntryController;
use App\Http\Controllers\Books\ReconcileController;
use App\Http\Controllers\Books\ReportController;
use App\Http\Controllers\Books\UsdEntryController;
use App\Http\Controllers\Settings\LocaleController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::post('locale', LocaleController::class)->middleware('throttle:20,1')->name('locale.update');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->middleware('can:dashboard.view')->name('dashboard');

    Route::post('quick/{type}', QuickEntryController::class)->middleware('throttle:60,1')->name('quick.store');

    Route::get('usd', [UsdEntryController::class, 'index'])->middleware('can:usd.view')->name('usd.index');
    Route::post('usd', [UsdEntryController::class, 'store'])->middleware('can:usd.create')->name('usd.store');
    Route::put('usd/{usd}', [UsdEntryController::class, 'update'])->middleware('can:usd.edit')->name('usd.update');
    Route::delete('usd/{usd}', [UsdEntryController::class, 'destroy'])->middleware('can:usd.delete')->name('usd.destroy');

    Route::get('bdt', [BdtEntryController::class, 'index'])->middleware('can:bdt.view')->name('bdt.index');
    Route::post('bdt', [BdtEntryController::class, 'store'])->middleware('can:bdt.create')->name('bdt.store');
    Route::put('bdt/{bdt}', [BdtEntryController::class, 'update'])->middleware('can:bdt.edit')->name('bdt.update');
    Route::delete('bdt/{bdt}', [BdtEntryController::class, 'destroy'])->middleware('can:bdt.delete')->name('bdt.destroy');

    Route::get('fx', [FxBatchController::class, 'index'])->middleware('can:fx.view')->name('fx.index');
    Route::put('fx/{fx}', [FxBatchController::class, 'update'])->middleware('can:fx.edit')->name('fx.update');
    Route::delete('fx/{fx}', [FxBatchController::class, 'destroy'])->middleware('can:fx.delete')->name('fx.destroy');

    Route::get('clients', [ClientController::class, 'index'])->middleware('can:clients.view')->name('clients.index');
    Route::get('clients/{client}', [ClientController::class, 'show'])->middleware('can:clients.view')->name('clients.show');
    Route::post('clients', [ClientController::class, 'store'])->middleware('can:clients.create')->name('clients.store');
    Route::put('clients/{client}', [ClientController::class, 'update'])->middleware('can:clients.edit')->name('clients.update');
    Route::delete('clients/{client}', [ClientController::class, 'destroy'])->middleware('can:clients.delete')->name('clients.destroy');

    Route::get('platforms', PlatformController::class)->middleware('can:platforms.view')->name('platforms.index');

    Route::get('payroll', [PayrollController::class, 'index'])->middleware('can:payroll.view')->name('payroll.index');
    Route::post('payroll/employees', [PayrollController::class, 'storeEmployee'])->middleware('can:payroll.create')->name('payroll.employees.store');
    Route::put('payroll/employees/{employee}', [PayrollController::class, 'updateEmployee'])->middleware('can:payroll.edit')->name('payroll.employees.update');
    Route::delete('payroll/employees/{employee}', [PayrollController::class, 'destroyEmployee'])->middleware('can:payroll.delete')->name('payroll.employees.destroy');
    Route::post('payroll/pay', [PayrollController::class, 'pay'])->middleware('can:payroll.create')->name('payroll.pay');
    Route::put('payroll/payments/{payment}', [PayrollController::class, 'assign'])->middleware('can:payroll.edit')->name('payroll.payments.assign');
    Route::delete('payroll/payments/{payment}', [PayrollController::class, 'destroyPayment'])->middleware('can:payroll.delete')->name('payroll.payments.destroy');

    Route::get('reports', [ReportController::class, 'index'])->middleware('can:reports.view')->name('reports.index');
    Route::get('reports/export/{book}', [ReportController::class, 'export'])->middleware(['can:reports.export', 'throttle:20,1'])->name('reports.export');

    Route::get('reconcile', [ReconcileController::class, 'index'])->middleware('can:reconcile.view')->name('reconcile.index');
    Route::post('reconcile', [ReconcileController::class, 'store'])->middleware('can:reconcile.create')->name('reconcile.store');

    Route::get('trash', [TrashController::class, 'index'])->middleware('can:trash.view')->name('trash.index');
    Route::post('trash/{type}/{id}', [TrashController::class, 'restore'])->middleware('can:trash.restore')->whereNumber('id')->name('trash.restore');

    Route::get('activity', [ActivityController::class, 'index'])->middleware('can:activity.view')->name('activity.index');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [UserController::class, 'index'])->middleware('can:users.view')->name('users.index');
        Route::post('users', [UserController::class, 'store'])->middleware('can:users.create')->name('users.store');
        Route::put('users/{user}', [UserController::class, 'update'])->middleware('can:users.edit')->name('users.update');
        Route::post('users/{user}/reset-2fa', [UserController::class, 'resetTwoFactor'])->middleware('can:users.edit')->name('users.reset-2fa');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('can:users.delete')->name('users.destroy');

        Route::get('roles', [RoleController::class, 'index'])->middleware('can:roles.view')->name('roles.index');
        Route::post('roles', [RoleController::class, 'store'])->middleware('can:roles.create')->name('roles.store');
        Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('can:roles.edit')->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('can:roles.delete')->name('roles.destroy');

        Route::get('settings', [SettingController::class, 'index'])->middleware('can:settings.view')->name('settings.index');
        Route::put('settings', [SettingController::class, 'update'])->middleware('can:settings.edit')->name('settings.update');
        Route::get('update/status', [UpdateController::class, 'status'])->middleware('throttle:20,1')->name('update.status');
        Route::post('update', [UpdateController::class, 'update'])->middleware('throttle:5,10')->name('update.run');
        Route::post('settings/heads', [SettingController::class, 'storeHead'])->middleware('can:settings.edit')->name('settings.heads.store');
        Route::put('settings/heads/{head}', [SettingController::class, 'updateHead'])->middleware('can:settings.edit')->name('settings.heads.update');
        Route::post('settings/accounts', [SettingController::class, 'storeAccount'])->middleware('can:settings.edit')->name('settings.accounts.store');
        Route::put('settings/accounts/{account}', [SettingController::class, 'updateAccount'])->middleware('can:settings.edit')->name('settings.accounts.update');
    });
});

require __DIR__.'/settings.php';
