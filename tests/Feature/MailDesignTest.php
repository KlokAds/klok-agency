<?php

use App\Models\User;
use App\Notifications\AgencyAlert;

it('sends every email in KlokOne\'s own design, not Laravel\'s', function () {
    $user = User::factory()->make(['locale' => 'bn']);
    $alert = new AgencyAlert('client_paid', 'পেমেন্ট পাওয়া গেছে — ধন্যবাদ!', 'Invoice {number} · {amount}', ['number' => 'KA-0004', 'amount' => '৳2,100'], ['url' => '/portal']);
    $alert->id = 'a1b2c3d4-0000-4000-8000-000000000000';
    $html = (string) $alert->toMail($user)->render();

    expect($html)->toContain('>K</td>')
        ->toContain('KlokOne')
        ->toContain('Klok Ads')
        ->toContain('#0f766e')
        ->toContain('Hind Siliguri')
        ->toContain('Button কাজ না করলে')
        ->not->toContain('laravel.com/img')
        ->not->toContain('Regards')
        ->not->toContain('All rights reserved');
});
