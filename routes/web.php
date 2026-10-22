<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\TrashController;
use App\Http\Controllers\Admin\UpdateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Agency\AdRunController;
use App\Http\Controllers\Agency\AgencySettingsController;
use App\Http\Controllers\Agency\NotificationController;
use App\Http\Controllers\Agency\PilotAccountController;
use App\Http\Controllers\Agency\TaskController;
use App\Http\Controllers\Agency\TickController;
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

// For cron-job.org: the token in Settings → Reminders makes the URL secret.
Route::get('agency/tick/{token}', TickController::class)->middleware('throttle:30,1')->name('agency.tick');

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

    Route::get('runs', [AdRunController::class, 'index'])->middleware('can:runs.view')->name('runs.index');
    Route::post('runs', [AdRunController::class, 'store'])->middleware('can:runs.create')->name('runs.store');
    Route::put('runs/{run}', [AdRunController::class, 'update'])->middleware('can:runs.edit')->name('runs.update');
    Route::put('runs/{run}/result', [AdRunController::class, 'result'])->middleware('can:runs.edit')->name('runs.result');
    Route::post('runs/{run}/renew', [AdRunController::class, 'renew'])->middleware('can:runs.create')->name('runs.renew');
    Route::post('runs/{run}/stop', [AdRunController::class, 'stop'])->middleware('can:runs.edit')->name('runs.stop');
    Route::delete('runs/{run}', [AdRunController::class, 'destroy'])->middleware('can:runs.delete')->name('runs.destroy');

    Route::get('ad-accounts', [PilotAccountController::class, 'index'])->middleware('can:runs.view')->name('ad-accounts.index');
    Route::post('ad-accounts/refresh', [PilotAccountController::class, 'refresh'])->middleware('can:runs.view')->name('ad-accounts.refresh');
    Route::put('ad-accounts/{account}/client', [PilotAccountController::class, 'link'])->middleware('can:clients.edit')->name('ad-accounts.link');

    Route::get('tasks', [TaskController::class, 'index'])->middleware('can:tasks.view')->name('tasks.index');
    Route::post('tasks', [TaskController::class, 'store'])->middleware('can:tasks.create')->name('tasks.store');
    Route::put('tasks/{task}', [TaskController::class, 'update'])->middleware('can:tasks.edit')->name('tasks.update');
    Route::post('tasks/{task}/toggle', [TaskController::class, 'toggle'])->middleware('can:tasks.edit')->name('tasks.toggle');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->middleware('can:tasks.delete')->name('tasks.destroy');

    // Everyone sees their own notifications; no permission needed.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/feed', [NotificationController::class, 'feed'])->middleware('throttle:60,1')->name('notifications.feed');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');

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
        Route::get('agency', [AgencySettingsController::class, 'index'])->middleware('can:settings.view')->name('agency.index');
        Route::put('agency', [AgencySettingsController::class, 'update'])->middleware('can:settings.edit')->name('agency.update');
        Route::put('agency/pilot-token', [AgencySettingsController::class, 'pilotToken'])->middleware('can:settings.edit')->name('agency.pilot-token');
        Route::post('agency/pilot-test', [AgencySettingsController::class, 'pilotTest'])->middleware(['can:settings.edit', 'throttle:10,1'])->name('agency.pilot-test');
        Route::post('agency/tick-token', [AgencySettingsController::class, 'tickToken'])->middleware('can:settings.edit')->name('agency.tick-token');
        Route::post('agency/summary-now', [AgencySettingsController::class, 'summaryNow'])->middleware(['can:settings.edit', 'throttle:3,1'])->name('agency.summary-now');
        Route::get('update/status', [UpdateController::class, 'status'])->middleware('throttle:20,1')->name('update.status');
        Route::post('update', [UpdateController::class, 'update'])->middleware('throttle:5,10')->name('update.run');
        Route::post('settings/heads', [SettingController::class, 'storeHead'])->middleware('can:settings.edit')->name('settings.heads.store');
        Route::put('settings/heads/{head}', [SettingController::class, 'updateHead'])->middleware('can:settings.edit')->name('settings.heads.update');
        Route::post('settings/accounts', [SettingController::class, 'storeAccount'])->middleware('can:settings.edit')->name('settings.accounts.store');
        Route::put('settings/accounts/{account}', [SettingController::class, 'updateAccount'])->middleware('can:settings.edit')->name('settings.accounts.update');
    });
});

require __DIR__.'/settings.php';
