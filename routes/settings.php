<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('settings/notifications', [ProfileController::class, 'notifications'])->name('profile.notifications');
    // A new sign-in email: a link to the new address, opened while signed in.
    Route::post('settings/email', [ProfileController::class, 'requestEmail'])->middleware('throttle:5,1')->name('profile.email.request');
    // The password is asked for again before the link takes effect: a signed-in session alone,
    // on someone else's desk or phone, cannot move the account to a new address.
    Route::get('settings/email/verify/{hash}', [ProfileController::class, 'verifyEmail'])->middleware(['signed', RequirePassword::class])->name('profile.email.verify');
});

// Fortify's own routes for these have no limit; the same routes again, with one. Registered
// after Fortify's, so these replace them (same address, same name).
Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware(['guest:'.config('fortify.guard'), 'throttle:password-reset'])
    ->name('password.email');
Route::post('reset-password', [NewPasswordController::class, 'store'])
    ->middleware(['guest:'.config('fortify.guard'), 'throttle:password-reset'])
    ->name('password.update');
Route::post('user/confirm-password', [ConfirmablePasswordController::class, 'store'])
    ->middleware(['auth:'.config('fortify.guard'), 'throttle:password-confirm'])
    ->name('password.confirm.store');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
