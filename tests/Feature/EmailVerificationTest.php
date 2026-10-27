<?php

use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Providers\FortifyServiceProvider;
use App\Support\Access;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    Http::fake();
    // The new page is in the next asset build; these tests are about the server side.
    $this->withoutVite();

    $this->owner = User::factory()->create(['name' => 'Owner']);
    $this->owner->assignRole(Access::OWNER);
    $this->client = Client::create(['name' => 'GFFS', 'type' => 'client', 'status' => 'active']);
});

function portalSignUp(string $email = 'owner@gffs.test'): User
{
    test()->post('/portal/register', [
        'name' => 'Rahim', 'company' => 'GFFS Ltd', 'phone' => '01712345678', 'email' => $email,
        'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
    ])->assertRedirect('/portal');

    return User::where('email', $email)->sole();
}

it('has email checks switched on', function () {
    expect(Features::enabled(Features::emailVerification()))->toBeTrue();
});

it('holds a portal sign-up at the confirm page until the link is opened, then lets it be linked', function () {
    Notification::fake();
    $user = portalSignUp();

    expect($user->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmail::class);

    // The portal waits for the email: the confirm page shows which address the link went to.
    $this->actingAs($user)->get('/portal')->assertRedirect('/email/verify');
    $this->actingAs($user)->get('/email/verify')
        ->assertInertia(fn ($page) => $page->component('auth/VerifyEmail')->where('email', 'owner@gffs.test'));

    // Not linkable yet.
    $this->actingAs($this->owner)->post("/clients/{$this->client->id}/portal-users", ['user_id' => $user->id])->assertSessionHasErrors('user_id');

    // The signed link from the mail confirms it.
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->actingAs($user)->get($url)->assertRedirect();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    $this->actingAs($this->owner)->post("/clients/{$this->client->id}/portal-users", ['user_id' => $user->id])->assertSessionHasNoErrors();
    expect($user->fresh()->client_id)->toBe($this->client->id);
});

it('does not confirm an email from a link made for another address', function () {
    Notification::fake();
    $user = portalSignUp();

    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('someone@else.test')]);
    $this->actingAs($user)->get($url)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('sends the confirm link in the app design, through the saved mailbox', function () {
    Setting::put('smtp_username', 'billing@klokads.test');
    Setting::put('smtp_password', Crypt::encryptString('mailbox-pass'));
    Notification::fake();
    $user = portalSignUp();

    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $n) use ($user) {
        $mail = $n->toMail($user);

        return $mail->subject === FortifyServiceProvider::VERIFY_TITLE
            && $mail->markdown === 'notifications::email'
            && str_contains((string) $mail->actionUrl, '/email/verify/'.$user->id.'/');
    });

    // The mail goes out from the mailbox set in Settings, as every other mail does.
    app('mail.manager');
    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.from.address'))->toBe('billing@klokads.test');

    // And it renders in the app's mail design.
    $html = (string) (new VerifyEmail)->toMail($user)->render();
    expect($html)->toContain(FortifyServiceProvider::VERIFY_TITLE);
});

it('sends the link again when asked, a few times only', function () {
    Notification::fake();
    $user = portalSignUp();

    $this->actingAs($user)->post('/email/verification-notification')->assertSessionHas('status', 'verification-link-sent');
    Notification::assertSentToTimes($user, VerifyEmail::class, 2);

    $this->actingAs($user)->post('/email/verification-notification');
    $this->actingAs($user)->post('/email/verification-notification');
    $this->actingAs($user)->post('/email/verification-notification')->assertStatus(429);
    Notification::assertSentToTimes($user, VerifyEmail::class, 4);
});

it('never holds team accounts at the confirm page', function () {
    // Made by the Owner: confirmed at once.
    $this->actingAs($this->owner)->post('/admin/users', [
        'name' => 'New Staff', 'email' => 'staff@klokads.test', 'role' => 'Accountant', 'password' => 'Secret-pass-123!',
    ])->assertSessionHasNoErrors();
    expect(User::where('email', 'staff@klokads.test')->sole()->hasVerifiedEmail())->toBeTrue();

    // An older team account without the mark goes straight on, and is marked.
    $old = User::factory()->unverified()->create();
    $old->assignRole('Accountant');
    $this->actingAs($old)->get('/dashboard')->assertRedirect('/email/verify');
    $this->actingAs($old)->get('/email/verify')->assertRedirect('/dashboard');
    expect($old->fresh()->hasVerifiedEmail())->toBeTrue();
    expect((string) $this->actingAs($old)->get('/dashboard')->headers->get('Location'))->not->toContain('/email/verify');
});

it('keeps an unconfirmed portal sign-up on the confirm page', function () {
    Notification::fake();
    $user = portalSignUp();

    $this->actingAs($user)->get('/email/verify')->assertOk();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('leaves client people out of the Manager picker and refuses them as Manager', function () {
    $staff = User::factory()->create(['name' => 'Staff']);
    $staff->assignRole('Accountant');
    $waiting = User::factory()->create(['name' => 'Waiting Sign-up']);
    $waiting->assignRole(Access::CLIENT);
    $linked = User::factory()->create(['name' => 'Linked Person', 'client_id' => $this->client->id]);
    $linked->assignRole(Access::CLIENT);

    $this->actingAs($this->owner)->get("/clients/{$this->client->id}")
        ->assertInertia(fn ($page) => $page->where('options.users', fn ($users) => collect($users)->pluck('value')->contains($staff->id)
            && ! collect($users)->pluck('value')->contains($waiting->id)
            && ! collect($users)->pluck('value')->contains($linked->id)));

    $form = ['name' => 'GFFS', 'status' => 'active'];
    $this->actingAs($this->owner)->put("/clients/{$this->client->id}", [...$form, 'manager_id' => $waiting->id])->assertSessionHasErrors('manager_id');
    $this->actingAs($this->owner)->put("/clients/{$this->client->id}", [...$form, 'manager_id' => $linked->id])->assertSessionHasErrors('manager_id');
    expect($this->client->fresh()->manager_id)->toBeNull();

    $this->actingAs($this->owner)->put("/clients/{$this->client->id}", [...$form, 'manager_id' => $staff->id])->assertSessionHasNoErrors();
    expect($this->client->fresh()->manager_id)->toBe($staff->id);
});
