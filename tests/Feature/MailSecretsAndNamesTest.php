<?php

use App\Models\Campaign;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Renewal;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\EmailChangeLink;
use App\Support\Access;
use App\Support\MailSettings;
use Database\Seeders\AccessSeeder;
use Database\Seeders\BooksSeeder;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Crypt;

const EVIL_NAME = '[Your invoice is overdue](https://evil.example/pay) ![](https://evil.example/t.png) <https://evil.example/x>';

beforeEach(function () {
    $this->seed([AccessSeeder::class, BooksSeeder::class]);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(Access::OWNER);

    Setting::put('smtp_host', 'smtp.hostinger.com');
    Setting::put('smtp_port', '465');
    Setting::put('smtp_username', 'billing@klokads.com');
    Setting::put('smtp_password', Crypt::encryptString('smtp-secret'));
    Setting::put('receipts_host', 'imap.hostinger.com');
    Setting::put('receipts_username', 'receipts@klokads.com');
    Setting::put('receipts_password', Crypt::encryptString('imap-secret'));
});

function mailForm(array $changes = []): array
{
    return array_merge([
        'host' => 'smtp.hostinger.com', 'port' => 465, 'username' => 'billing@klokads.com', 'password' => '',
        'from_name' => 'Klok Ads', 'notify_email' => 'adsklok@gmail.com',
        'receipts_host' => 'imap.hostinger.com', 'receipts_username' => 'receipts@klokads.com', 'receipts_password' => '',
    ], $changes);
}

function savedPassword(string $key): ?string
{
    $stored = Setting::get($key);

    return blank($stored) ? null : Crypt::decryptString($stored);
}

it('keeps the saved passwords when the servers stay the same', function () {
    $this->actingAs($this->owner)->put('/admin/settings/mail', mailForm(['host' => 'SMTP.hostinger.com', 'from_name' => 'Klok']))
        ->assertSessionHasNoErrors();

    expect(savedPassword('smtp_password'))->toBe('smtp-secret')
        ->and(savedPassword('receipts_password'))->toBe('imap-secret');
});

it('drops the saved SMTP password when a settings.edit user moves the host or port without typing one', function () {
    $editor = User::factory()->create();
    $editor->givePermissionTo(['settings.view', 'settings.edit']);

    $this->actingAs($editor)->put('/admin/settings/mail', mailForm(['host' => 'smtp.attacker.example']))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', __('সংরক্ষণ হয়েছে। Server বদলেছে, তাই আগের password মুছে দেওয়া হয়েছে — নতুন server-এর password আবার দিন।'));

    // Nothing is left for the test mail to send to the new host.
    expect(Setting::get('smtp_host'))->toBe('smtp.attacker.example')
        ->and(savedPassword('smtp_password'))->toBeNull()
        ->and(MailSettings::ready())->toBeFalse()
        ->and(savedPassword('receipts_password'))->toBe('imap-secret');

    // A port change alone does the same.
    Setting::put('smtp_host', 'smtp.hostinger.com');
    Setting::put('smtp_password', Crypt::encryptString('smtp-secret'));
    $this->actingAs($editor)->put('/admin/settings/mail', mailForm(['port' => 587]))->assertSessionHasNoErrors();
    expect(savedPassword('smtp_password'))->toBeNull();
});

it('drops the saved IMAP password when the receipts host changes without a new one', function () {
    $this->actingAs($this->owner)->put('/admin/settings/mail', mailForm(['receipts_host' => 'imap.attacker.example']))
        ->assertSessionHasNoErrors();

    expect(savedPassword('receipts_password'))->toBeNull()
        ->and(MailSettings::receipts())->toBeNull()
        ->and(savedPassword('smtp_password'))->toBe('smtp-secret');
});

it('takes a new server with the new password typed along with it', function () {
    $this->actingAs($this->owner)->put('/admin/settings/mail', mailForm([
        'host' => 'smtp.gmail.com', 'port' => 587, 'password' => 'new-smtp',
        'receipts_host' => 'imap.gmail.com', 'receipts_password' => 'new-imap',
    ]))->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', __('Email-এর settings সংরক্ষণ হয়েছে।'));

    expect(savedPassword('smtp_password'))->toBe('new-smtp')
        ->and(savedPassword('receipts_password'))->toBe('new-imap');
});

it('shows the asking name in the email-change link as text, never a link or image', function () {
    $html = (string) (new EmailChangeLink('https://billing.klokads.com/settings/email/verify?sig=1', EVIL_NAME))
        ->toMail(new AnonymousNotifiable)->render();

    expect($html)->not->toContain('href="https://evil.example')
        ->and($html)->not->toContain('src="https://evil.example')
        ->and($html)->toContain('[Your invoice is overdue](https://evil.example/pay)')
        ->and($html)->toContain('&lt;https://evil.example/x&gt;')
        ->and($html)->not->toContain('&amp;lt;');
});

it('shows client and campaign names as text in the renewal and invoice emails', function () {
    $client = new Client(['name' => EVIL_NAME]);
    $campaign = new Campaign;
    $campaign->forceFill(['name' => '**Boost** [click](https://evil.example/c)']);

    $renewal = (new Renewal)->forceFill(['days' => 7, 'ends_on' => '2026-11-01', 'new_end' => '2026-11-08']);
    $renewal->setRelation('client', $client)->setRelation('campaign', $campaign)->setRelation('invoice', null);

    $invoice = (new Invoice)->forceFill(['number' => 'INV-1', 'issue_date' => '2026-10-27', 'due_date' => null]);
    $invoice->setRelation('client', $client);

    $markdown = app(Markdown::class);
    $views = [];
    foreach (['paid', 'extended', 'declined'] as $event) {
        $views[] = (string) $markdown->render('mail.renewal', ['renewal' => $renewal, 'event' => $event, 'total' => null, 'usd' => null, 'url' => 'https://billing.klokads.com/renewals']);
    }
    $views[] = (string) $markdown->render('mail.invoice', ['invoice' => $invoice, 'business' => ['name' => 'Klok Ads', 'phone' => null], 'total' => '৳100', 'due' => '৳100', 'url' => 'https://billing.klokads.com/i/x']);

    foreach ($views as $html) {
        expect($html)->not->toContain('href="https://evil.example')
            ->and($html)->not->toContain('src="https://evil.example')
            ->and($html)->toContain('[Your invoice is overdue](https://evil.example/pay)');
    }
    expect($views[0])->toContain('**Boost** [click](https://evil.example/c)');
});
