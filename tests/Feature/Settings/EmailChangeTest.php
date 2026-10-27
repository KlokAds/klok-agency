<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AccountChanged;
use App\Notifications\EmailChangeLink;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Notification::fake();
    Setting::put('smtp_username', 'mail@klokads.com');
    Setting::put('smtp_password', Crypt::encryptString('secret'));
    $this->user = User::factory()->create(['email' => 'old@example.com']);
});

it('sends a link to the new address and changes nothing until it is opened', function () {
    $this->actingAs($this->user)->post('/settings/email', ['email' => 'New@Example.com'])
        ->assertRedirect(route('profile.edit'))->assertSessionHasNoErrors();

    expect($this->user->fresh()->email)->toBe('old@example.com')
        ->and(Cache::get(ProfileController::pendingKey($this->user->id)))->toBe('new@example.com');
    Notification::assertSentTo(new AnonymousNotifiable, EmailChangeLink::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'new@example.com');

    $this->actingAs($this->user)->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->where('pending_email', 'new@example.com'));
});

it('changes the email when the signed link is opened, and tells the old address', function () {
    Cache::put(ProfileController::pendingKey($this->user->id), 'new@example.com', now()->addHour());
    $url = URL::temporarySignedRoute('profile.email.verify', now()->addHour(), ['hash' => sha1('new@example.com')]);

    // The password is asked again first: a signed-in session alone cannot move the account.
    $this->actingAs($this->user)->get($url)->assertRedirect(route('password.confirm'));
    expect($this->user->fresh()->email)->toBe('old@example.com');

    $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()])->get($url)->assertRedirect(route('profile.edit'));

    expect($this->user->fresh()->email)->toBe('new@example.com')
        ->and(Cache::get(ProfileController::pendingKey($this->user->id)))->toBeNull();
    Notification::assertSentTo(new AnonymousNotifiable, AccountChanged::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'old@example.com');
});

it('ignores a link that was not signed, or that belongs to another address', function () {
    Cache::put(ProfileController::pendingKey($this->user->id), 'new@example.com', now()->addHour());

    $this->actingAs($this->user)->get('/settings/email/verify/'.sha1('new@example.com'))->assertStatus(403);

    $other = URL::temporarySignedRoute('profile.email.verify', now()->addHour(), ['hash' => sha1('someone@example.com')]);
    $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()])->get($other)->assertRedirect(route('profile.edit'));
    expect($this->user->fresh()->email)->toBe('old@example.com');
});

it('refuses an address another account already uses', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($this->user)->post('/settings/email', ['email' => 'taken@example.com'])
        ->assertSessionHasErrors('email');
});
