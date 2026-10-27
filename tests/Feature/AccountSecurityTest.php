<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\EmailChangeLink;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Notification::fake();
    Setting::put('smtp_username', 'mail@klokads.com');
    Setting::put('smtp_password', Crypt::encryptString('secret'));
});

function portalClient(): User
{
    test()->seed(AccessSeeder::class);
    $client = Client::create(['name' => 'GFFS', 'type' => 'client', 'status' => 'active']);
    $user = User::factory()->create(['email' => 'rahim@gffs.test', 'client_id' => $client->id]);
    $user->assignRole(Access::CLIENT);

    return $user->fresh();
}

// Finding 16: a signed-in session alone cannot move an account to a new inbox.
it('does not save a client\'s new email straight away, but sends a link to it', function () {
    $user = portalClient();

    $this->actingAs($user)->patch('/settings/profile', [
        'name' => 'Rahim Uddin', 'email' => 'Thief@Example.com', 'phone' => '01712345678', 'company' => 'GFFS',
    ])->assertRedirect(route('profile.edit'))->assertSessionHasNoErrors();

    expect($user->fresh())->email->toBe('rahim@gffs.test')->name->toBe('Rahim Uddin')
        ->and(Cache::get(ProfileController::pendingKey($user->id)))->toBe('thief@example.com');
    Notification::assertSentTo(new AnonymousNotifiable, EmailChangeLink::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'thief@example.com');
});

it('asks for the password again before the email link takes effect', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    Cache::put(ProfileController::pendingKey($user->id), 'new@example.com', now()->addHour());
    $url = URL::temporarySignedRoute('profile.email.verify', now()->addHour(), ['hash' => sha1('new@example.com')]);

    $this->actingAs($user)->get($url)->assertRedirect(route('password.confirm'));
    expect($user->fresh()->email)->toBe('old@example.com');

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get($url)->assertRedirect(route('profile.edit'));
    expect($user->fresh()->email)->toBe('new@example.com');
});

it('lets a client confirm their new email with the link and the password', function () {
    $user = portalClient();
    $this->actingAs($user)->patch('/settings/profile', ['name' => $user->name, 'email' => 'new@gffs.test']);
    $url = URL::temporarySignedRoute('profile.email.verify', now()->addHour(), ['hash' => sha1('new@gffs.test')]);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get($url)->assertRedirect(route('profile.edit'));

    expect($user->fresh()->email)->toBe('new@gffs.test');
});

// Finding 17: forgot password answers the same whether or not the account exists, and has a limit.
it('answers forgot password the same for a known and an unknown email', function () {
    $user = User::factory()->create();

    $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
    $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.com']);

    $known->assertSessionHasNoErrors()->assertSessionHas('status');
    $unknown->assertSessionHasNoErrors()->assertSessionHas('status', session('status'));
    Notification::assertSentTo($user, ResetPassword::class);
});

it('answers the same when the same address asks again too soon', function () {
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);
    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors()->assertSessionHas('status');
});

it('limits forgot password requests from one place', function () {
    foreach (range(1, 5) as $i) {
        $this->post('/forgot-password', ['email' => "nobody{$i}@example.com"])->assertRedirect();
    }

    $this->post('/forgot-password', ['email' => 'nobody6@example.com'])->assertStatus(429);
});

it('limits new-password attempts from one place', function () {
    foreach (range(1, 5) as $i) {
        $this->post('/reset-password', ['token' => 'x', 'email' => 'a@example.com', 'password' => 'Strong-pass-2026', 'password_confirmation' => 'Strong-pass-2026']);
    }

    $this->post('/reset-password', ['token' => 'x', 'email' => 'a@example.com', 'password' => 'Strong-pass-2026', 'password_confirmation' => 'Strong-pass-2026'])->assertStatus(429);
});

it('still signs in with the right password, and refuses an unknown email', function () {
    $user = User::factory()->create(['password' => Hash::make('Right-pass-2026')]);

    $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'Right-pass-2026']);
    $this->assertGuest();

    $this->post('/login', ['email' => $user->email, 'password' => 'Right-pass-2026']);
    $this->assertAuthenticatedAs($user);
});

// Finding 22: guessing the password on the confirm page is limited.
it('limits password confirmation guesses', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->actingAs($user)->post('/user/confirm-password', ['password' => 'wrong-'.$i])->assertSessionHasErrors('password');
    }

    $this->actingAs($user)->post('/user/confirm-password', ['password' => 'wrong-6'])->assertStatus(429);
});

// Finding 18: changing the password gives a remember cookie only to a device that had one, and
// the cookie never carries the bcrypt hash.
it('does not add a remember cookie on password change when the device had none', function () {
    $user = User::factory()->create(['password' => Hash::make('Old-pass-2026')]);
    $recaller = Auth::guard('web')->getRecallerName();

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->put(route('user-password.update'), [
        'current_password' => 'Old-pass-2026', 'password' => 'New-pass-2026', 'password_confirmation' => 'New-pass-2026',
    ])->assertSessionHasNoErrors()->assertCookieMissing($recaller);
});

it('renews a remember cookie on password change, without the bcrypt hash in it', function () {
    $user = User::factory()->create(['password' => Hash::make('Old-pass-2026')]);
    $recaller = Auth::guard('web')->getRecallerName();

    $response = $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->withCookie($recaller, $user->id.'|old-token|x')
        ->put(route('user-password.update'), [
            'current_password' => 'Old-pass-2026', 'password' => 'New-pass-2026', 'password_confirmation' => 'New-pass-2026',
        ]);

    $response->assertSessionHasNoErrors()->assertCookie($recaller);
    $user->refresh();
    $value = $response->getCookie($recaller)->getValue();

    expect($value)->toBe($user->id.'|'.$user->remember_token.'|'.Auth::guard('web')->hashPasswordForCookie($user->password))
        ->and($value)->not->toContain($user->password);
});
