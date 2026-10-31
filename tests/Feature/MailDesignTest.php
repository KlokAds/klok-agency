<?php

use App\Models\User;
use App\Notifications\AgencyAlert;

it('sends every email in KlokOne\'s own design, not Laravel\'s', function () {
    $user = User::factory()->make(['locale' => 'bn']);
    $alert = new AgencyAlert('client_paid', 'পেমেন্ট পাওয়া গেছে — ধন্যবাদ!', 'Invoice {number} · {amount}', ['number' => 'KA-0004', 'amount' => '৳2,100'], ['url' => '/portal']);
    $alert->id = 'a1b2c3d4-0000-4000-8000-000000000000';
    $html = (string) $alert->toMail($user)->render();

    // MAIL_PREVIEW=path writes the email out, to look at it.
    if ($preview = getenv('MAIL_PREVIEW')) {
        file_put_contents($preview, $html);
    }

    expect($html)->toContain('>K</a></td>')
        ->toContain('KlokOne')
        ->toContain('Klok Ads')
        ->toContain('#5a3b82')
        ->toContain('Hind Siliguri')
        ->toContain('Button না খুললে')
        ->toContain('শুভেচ্ছান্তে')
        ->toContain('প্রিয় ')
        ->toContain('class="kicker"')
        ->not->toContain('laravel.com/img')
        ->not->toContain('Regards')
        ->not->toContain('All rights reserved');
});
