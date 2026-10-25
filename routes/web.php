<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\ErrorLogController;
use App\Http\Controllers\Admin\PaymentSmsController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\TrashController;
use App\Http\Controllers\Admin\UpdateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Agency\AdRunController;
use App\Http\Controllers\Agency\AgencySettingsController;
use App\Http\Controllers\Agency\AppSettingsController;
use App\Http\Controllers\Agency\CampaignController;
use App\Http\Controllers\Agency\NotificationController;
use App\Http\Controllers\Agency\PilotAccountController;
use App\Http\Controllers\Agency\RenewalController;
use App\Http\Controllers\Agency\TaskController;
use App\Http\Controllers\Agency\TickController;
use App\Http\Controllers\AppCrashController;
use App\Http\Controllers\AppDeviceController;
use App\Http\Controllers\AppReleaseController;
use App\Http\Controllers\AppSmsController;
use App\Http\Controllers\Books\BdtEntryController;
use App\Http\Controllers\Books\ClientController;
use App\Http\Controllers\Books\DashboardController;
use App\Http\Controllers\Books\DollarController;
use App\Http\Controllers\Books\FxBatchController;
use App\Http\Controllers\Books\MetaReceiptController;
use App\Http\Controllers\Books\MoneyInOutController;
use App\Http\Controllers\Books\PayrollController;
use App\Http\Controllers\Books\PlatformController;
use App\Http\Controllers\Books\QuickEntryController;
use App\Http\Controllers\Books\ReconcileController;
use App\Http\Controllers\Books\ReportController;
use App\Http\Controllers\Books\UsdEntryController;
use App\Http\Controllers\Books\WalletController;
use App\Http\Controllers\Invoices\InvoiceController;
use App\Http\Controllers\Invoices\PaymentSettingsController;
use App\Http\Controllers\Invoices\PublicInvoiceController;
use App\Http\Controllers\Invoices\PublicRenewalController;
use App\Http\Controllers\Invoices\SmsHookController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Settings\LocaleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

// An old or mistyped address (the Crater days' /admin, /home …) opens the sign-in page, or the
// dashboard when signed in, instead of a 404. Files (build/, storage/) and hooks still 404.
Route::fallback(function (Request $request) {
    abort_if($request->expectsJson() || $request->is('build/*', 'storage/*', 'hooks/*', 'api/*') || str_contains($request->path(), '.'), 404);

    return redirect()->route($request->user() ? 'dashboard' : 'login');
});

Route::post('locale', LocaleController::class)->middleware('throttle:20,1')->name('locale.update');

// For cron-job.org: the token in Settings → Reminders makes the URL secret.
Route::get('agency/tick/{token}', TickController::class)->middleware('throttle:30,1')->name('agency.tick');

// The wallet phone's SMS forwarder (token in Settings → Payment; CSRF-exempt in bootstrap/app.php).
Route::post('hooks/sms/{token}', SmsHookController::class)->middleware('throttle:120,1')->name('hooks.sms');
Route::get('app/get', [AppReleaseController::class, 'get'])->middleware('throttle:10,1')->name('app.get');
Route::get('app/download', [AppReleaseController::class, 'download'])->middleware(['signed', 'throttle:10,1'])->name('app.download');
Route::post('hooks/app-crash', AppCrashController::class)->middleware('throttle:20,1')->name('hooks.app-crash');
Route::post('hooks/app-forget', [AppDeviceController::class, 'forget'])->middleware('throttle:10,1')->name('app.device.forget');
Route::post('hooks/app-release/{token}', [AppReleaseController::class, 'upload'])->middleware('throttle:10,1')->name('hooks.app-release');

// The client's invoice page: no sign-in, the token is the key.
Route::middleware('throttle:60,1')->group(function () {
    Route::get('i/{token}', [PublicInvoiceController::class, 'show'])->name('invoice.public');
    Route::get('i/{token}/status', [PublicInvoiceController::class, 'status'])->name('invoice.public.status');
    Route::get('i/{token}/qr.svg', [PublicInvoiceController::class, 'qr'])->name('invoice.public.qr');
    Route::get('r/{token}', [PublicRenewalController::class, 'show'])->name('renewal.public');
    Route::get('pay/media/{kind}', [PaymentSettingsController::class, 'media'])->name('pay.media');
});
Route::middleware('throttle:12,1')->group(function () {
    Route::post('i/{token}/pay', [PublicInvoiceController::class, 'start'])->name('invoice.public.start');
    Route::post('r/{token}/choose', [PublicRenewalController::class, 'choose'])->name('renewal.public.choose');
    Route::post('r/{token}/decline', [PublicRenewalController::class, 'decline'])->name('renewal.public.decline');
    Route::post('i/{token}/cancel', [PublicInvoiceController::class, 'cancel'])->name('invoice.public.cancel');
});
Route::post('i/{token}/claim', [PublicInvoiceController::class, 'claim'])->middleware('throttle:6,1')->name('invoice.public.claim');

// The client portal: sign-up for anyone, the rest for a signed-in client's own person.
Route::middleware('guest')->group(function () {
    Route::get('portal/register', [PortalController::class, 'register'])->name('portal.register');
    Route::post('portal/register', [PortalController::class, 'signUp'])->middleware('throttle:5,1')->name('portal.signup');
});
Route::middleware(['auth', 'verified'])->prefix('portal')->name('portal.')->group(function () {
    Route::get('/', [PortalController::class, 'home'])->name('home');
    Route::get('campaigns', [PortalController::class, 'campaigns'])->name('campaigns');
    Route::get('campaigns/{campaign}', [PortalController::class, 'campaign'])->name('campaign');
    Route::get('invoices', [PortalController::class, 'invoices'])->name('invoices');
    Route::get('notifications', [PortalController::class, 'notifications'])->name('notifications');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // The Android app: this phone's notifications, and the phone that forwards the wallet SMS.
    Route::post('app/device', [AppDeviceController::class, 'store'])->middleware('throttle:30,1')->name('app.device');
    Route::get('app/latest', [AppReleaseController::class, 'latest'])->middleware('throttle:30,1')->name('app.latest');
    Route::post('app/sms-forwarder', [AppDeviceController::class, 'smsForwarder'])->middleware('throttle:10,1')->name('app.sms-forwarder');
    Route::get('app/sms-center', [AppSmsController::class, 'index'])->middleware('throttle:60,1')->name('app.sms-center');
    Route::post('app/sms-senders', [AppSmsController::class, 'store'])->middleware('throttle:30,1')->name('app.sms-senders.store');
    Route::delete('app/sms-senders', [AppSmsController::class, 'destroy'])->middleware('throttle:30,1')->name('app.sms-senders.destroy');

    Route::get('dashboard', DashboardController::class)->middleware('can:dashboard.view')->name('dashboard');

    Route::get('invoices', [InvoiceController::class, 'index'])->middleware('can:clients.view')->name('invoices.index');
    Route::get('invoices/create', [InvoiceController::class, 'create'])->middleware('can:clients.edit')->name('invoices.create');
    Route::get('invoices/campaigns', [InvoiceController::class, 'campaigns'])->middleware(['can:clients.edit', 'throttle:60,1'])->name('invoices.campaigns');
    Route::post('invoices', [InvoiceController::class, 'store'])->middleware('can:clients.edit')->name('invoices.store');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('can:clients.view')->name('invoices.show');
    Route::get('invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->middleware('can:clients.edit')->name('invoices.edit');
    Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->middleware('can:clients.edit')->name('invoices.update');
    Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])->middleware('can:clients.edit')->name('invoices.send');
    Route::post('invoices/{invoice}/email', [InvoiceController::class, 'email'])->middleware(['can:clients.edit', 'throttle:10,1'])->name('invoices.email');
    Route::post('invoices/{invoice}/partial', [InvoiceController::class, 'partial'])->middleware('can:clients.edit')->name('invoices.partial');
    Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->middleware('can:clients.edit')->name('invoices.void');
    Route::post('invoices/{invoice}/pay', [InvoiceController::class, 'pay'])->middleware('can:bdt.create')->name('invoices.pay');
    Route::post('invoices/{invoice}/payments/{payment}/reject', [InvoiceController::class, 'reject'])->middleware('can:bdt.create')->name('invoices.payments.reject');
    Route::post('invoices/sms/{sms}/assign', [InvoiceController::class, 'assignSms'])->name('invoices.sms.assign');
    Route::post('invoices/sms/{sms}/ignore', [InvoiceController::class, 'ignoreSms'])->name('invoices.sms.ignore');

    Route::post('quick/{type}', QuickEntryController::class)->middleware('throttle:60,1')->name('quick.store');

    Route::get('usd', [UsdEntryController::class, 'index'])->middleware('can:usd.view')->name('usd.index');
    Route::post('usd', [UsdEntryController::class, 'store'])->middleware('can:usd.create')->name('usd.store');
    Route::put('usd/{usd}', [UsdEntryController::class, 'update'])->middleware('can:usd.edit')->name('usd.update');
    Route::delete('usd/{usd}', [UsdEntryController::class, 'destroy'])->middleware('can:usd.delete')->name('usd.destroy');

    Route::get('bdt', [BdtEntryController::class, 'index'])->middleware('can:bdt.view')->name('bdt.index');
    Route::post('bdt', [BdtEntryController::class, 'store'])->middleware('can:bdt.create')->name('bdt.store');
    Route::put('bdt/{bdt}', [BdtEntryController::class, 'update'])->middleware('can:bdt.edit')->name('bdt.update');
    Route::delete('bdt/{bdt}', [BdtEntryController::class, 'destroy'])->middleware('can:bdt.delete')->name('bdt.destroy');

    Route::get('search', SearchController::class)->middleware('throttle:60,1')->name('search');
    Route::get('dollars', [DollarController::class, 'index'])->middleware('can:usd.view')->name('dollars.index');
    Route::get('dollars/receipts', [MetaReceiptController::class, 'index'])->middleware('can:usd.view')->name('dollars.receipts');
    Route::get('dollars/receipts/{receipt}', [MetaReceiptController::class, 'show'])->middleware('can:usd.view')->name('dollars.receipts.show');
    Route::post('dollars/receipts/{receipt}/retry', [MetaReceiptController::class, 'retry'])->middleware(['can:usd.create', 'throttle:20,1'])->name('dollars.receipts.retry');
    Route::post('dollars/receipts/check', [MetaReceiptController::class, 'check'])->middleware(['can:usd.create', 'throttle:6,1'])->name('dollars.receipts.check');
    Route::get('dollars/pending/{account}', [DollarController::class, 'pending'])->middleware(['can:usd.create', 'throttle:60,1'])->name('dollars.pending');
    Route::post('dollars/accounts', [DollarController::class, 'storeAccount'])->middleware('can:usd.edit')->name('dollars.accounts.store');
    Route::put('dollars/accounts/{account}', [DollarController::class, 'updateAccount'])->middleware('can:usd.edit')->name('dollars.accounts.update');
    Route::get('payments', [MoneyInOutController::class, 'payments'])->middleware('can:bdt.view')->name('payments.index');
    Route::get('expenses', [MoneyInOutController::class, 'expenses'])->middleware('can:bdt.view')->name('expenses.index');
    Route::post('expenses/heads', [MoneyInOutController::class, 'storeHead'])->middleware('can:bdt.edit')->name('expenses.heads.store');
    Route::put('expenses/heads/{head}', [MoneyInOutController::class, 'updateHead'])->middleware('can:bdt.edit')->name('expenses.heads.update');

    Route::get('wallets', [WalletController::class, 'index'])->middleware('can:bdt.view')->name('wallets.index');
    Route::put('wallets/tag/{entry}', [WalletController::class, 'tag'])->middleware('can:bdt.edit')->name('wallets.tag');
    Route::put('wallets/tag-rest', [WalletController::class, 'tagRest'])->middleware('can:bdt.edit')->name('wallets.tag-rest');
    Route::post('wallets/places', [WalletController::class, 'storePlace'])->middleware('can:bdt.edit')->name('wallets.places.store');
    Route::put('wallets/places/{wallet}', [WalletController::class, 'updatePlace'])->middleware('can:bdt.edit')->name('wallets.places.update');
    Route::delete('wallets/places/{wallet}', [WalletController::class, 'destroyPlace'])->middleware('can:bdt.edit')->name('wallets.places.destroy');
    Route::put('wallets/opening', [WalletController::class, 'opening'])->middleware('can:bdt.edit')->name('wallets.opening');
    Route::post('wallets/adjust', [WalletController::class, 'adjust'])->middleware('can:bdt.edit')->name('wallets.adjust');
    Route::post('wallets/moves', [WalletController::class, 'storeMove'])->middleware('can:bdt.create')->name('wallets.moves.store');
    Route::delete('wallets/moves/{move}', [WalletController::class, 'destroyMove'])->middleware('can:bdt.delete')->name('wallets.moves.destroy');

    Route::get('fx', [FxBatchController::class, 'index'])->middleware('can:fx.view')->name('fx.index');
    Route::put('fx/{fx}', [FxBatchController::class, 'update'])->middleware('can:fx.edit')->name('fx.update');
    Route::delete('fx/{fx}', [FxBatchController::class, 'destroy'])->middleware('can:fx.delete')->name('fx.destroy');

    Route::get('clients', [ClientController::class, 'index'])->middleware('can:clients.view')->name('clients.index');
    Route::get('clients/{client}', [ClientController::class, 'show'])->middleware('can:clients.view')->name('clients.show');
    Route::post('clients', [ClientController::class, 'store'])->middleware('can:clients.create')->name('clients.store');
    Route::post('clients/{client}/portal-users', [ClientController::class, 'linkPortalUser'])->middleware('can:clients.edit')->name('clients.portal.link');
    Route::delete('clients/{client}/portal-users/{user}', [ClientController::class, 'unlinkPortalUser'])->middleware('can:clients.edit')->name('clients.portal.unlink');
    Route::post('clients/{client}/refund', [ClientController::class, 'refund'])->middleware(['can:bdt.create', 'throttle:10,1'])->name('clients.refund');
    Route::put('clients/{client}', [ClientController::class, 'update'])->middleware('can:clients.edit')->name('clients.update');
    Route::delete('clients/{client}', [ClientController::class, 'destroy'])->middleware('can:clients.delete')->name('clients.destroy');

    Route::get('runs', [AdRunController::class, 'index'])->middleware('can:runs.view')->name('runs.index');
    Route::post('runs', [AdRunController::class, 'store'])->middleware('can:runs.create')->name('runs.store');
    Route::put('runs/{run}', [AdRunController::class, 'update'])->middleware('can:runs.edit')->name('runs.update');
    Route::put('runs/{run}/result', [AdRunController::class, 'result'])->middleware('can:runs.edit')->name('runs.result');
    Route::post('runs/{run}/renew', [AdRunController::class, 'renew'])->middleware('can:runs.create')->name('runs.renew');
    Route::post('runs/{run}/stop', [AdRunController::class, 'stop'])->middleware('can:runs.edit')->name('runs.stop');
    Route::delete('runs/{run}', [AdRunController::class, 'destroy'])->middleware('can:runs.delete')->name('runs.destroy');

    Route::get('campaigns', [CampaignController::class, 'index'])->middleware('can:runs.view')->name('campaigns.index');
    Route::put('campaigns/{campaign}/client', [CampaignController::class, 'link'])->middleware('can:clients.edit')->name('campaigns.link');
    Route::post('campaigns/link-suggested', [CampaignController::class, 'linkSuggested'])->middleware('can:clients.edit')->name('campaigns.link-suggested');
    Route::post('campaigns/sync', [CampaignController::class, 'sync'])->middleware('can:runs.view')->name('campaigns.sync');
    Route::post('campaigns/{campaign}/extend', [RenewalController::class, 'extend'])->middleware(['can:clients.edit', 'throttle:20,1'])->name('campaigns.extend');

    Route::get('renewals', [RenewalController::class, 'index'])->middleware('can:runs.view')->name('renewals.index');
    Route::get('renewals/{renewal}', [RenewalController::class, 'show'])->middleware('can:runs.view')->name('renewals.show');
    Route::post('renewals/{renewal}/approve', [RenewalController::class, 'approve'])->middleware(['can:clients.edit', 'throttle:20,1'])->name('renewals.approve');

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
    Route::post('notifications/clear', [NotificationController::class, 'clear'])->name('notifications.clear');
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

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

        Route::get('sessions', [SessionController::class, 'index'])->middleware('can:users.view')->name('sessions.index');
        Route::delete('sessions/{session}', [SessionController::class, 'destroy'])->middleware('can:users.edit')->name('sessions.destroy');
        Route::get('errors', [ErrorLogController::class, 'index'])->name('errors.index');
        Route::get('sms', [PaymentSmsController::class, 'index'])->name('sms.index');
        Route::get('roles', [RoleController::class, 'index'])->middleware('can:roles.view')->name('roles.index');
        Route::post('roles', [RoleController::class, 'store'])->middleware('can:roles.create')->name('roles.store');
        Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('can:roles.edit')->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('can:roles.delete')->name('roles.destroy');

        Route::get('settings', [SettingController::class, 'index'])->middleware('can:settings.view')->name('settings.index');
        Route::put('settings', [SettingController::class, 'update'])->middleware('can:settings.edit')->name('settings.update');
        Route::put('settings/mail', [SettingController::class, 'mail'])->middleware('can:settings.edit')->name('settings.mail');
        Route::post('settings/mail/test', [SettingController::class, 'testMail'])->middleware(['can:settings.edit', 'throttle:5,1'])->name('settings.mail.test');
        Route::get('payment', [PaymentSettingsController::class, 'index'])->middleware('can:settings.view')->name('payment.index');
        Route::post('payment', [PaymentSettingsController::class, 'update'])->middleware('can:settings.edit')->name('payment.update');
        Route::delete('payment/image/{kind}', [PaymentSettingsController::class, 'removeImage'])->middleware('can:settings.edit')->name('payment.image.destroy');
        Route::post('payment/sms-test', [PaymentSettingsController::class, 'smsTest'])->middleware(['can:settings.view', 'throttle:30,1'])->name('payment.sms-test');
        Route::post('payment/token', [PaymentSettingsController::class, 'token'])->middleware('can:settings.edit')->name('payment.token');
        Route::post('payment/disconnect', [PaymentSettingsController::class, 'disconnect'])->middleware('can:settings.edit')->name('payment.disconnect');
        Route::get('agency', [AgencySettingsController::class, 'index'])->middleware('can:settings.view')->name('agency.index');
        Route::put('agency', [AgencySettingsController::class, 'update'])->middleware('can:settings.edit')->name('agency.update');
        Route::put('agency/pilot-token', [AgencySettingsController::class, 'pilotToken'])->middleware('can:settings.edit')->name('agency.pilot-token');
        Route::post('agency/pilot-test', [AgencySettingsController::class, 'pilotTest'])->middleware(['can:settings.edit', 'throttle:10,1'])->name('agency.pilot-test');
        Route::post('agency/tick-token', [AgencySettingsController::class, 'tickToken'])->middleware('can:settings.edit')->name('agency.tick-token');
        Route::post('agency/summary-now', [AgencySettingsController::class, 'summaryNow'])->middleware(['can:settings.edit', 'throttle:3,1'])->name('agency.summary-now');
        Route::get('app', [AppSettingsController::class, 'index'])->middleware('can:settings.view')->name('app.index');
        Route::put('app/firebase', [AppSettingsController::class, 'firebase'])->middleware('can:settings.edit')->name('app.firebase');
        Route::put('app/card-low', [AppSettingsController::class, 'cardLow'])->middleware('can:settings.edit')->name('app.card-low');
        Route::post('app/test', [AppSettingsController::class, 'test'])->middleware('throttle:10,1')->name('app.test');
        Route::delete('app/devices/{device}', [AppSettingsController::class, 'destroyDevice'])->middleware('can:settings.edit')->name('app.devices.destroy');
        Route::get('update/status', [UpdateController::class, 'status'])->middleware('throttle:20,1')->name('update.status');
        Route::post('update', [UpdateController::class, 'update'])->middleware('throttle:5,10')->name('update.run');
    });
});

require __DIR__.'/settings.php';
