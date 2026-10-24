<?php

use App\Models\User;

it('sends an unknown address to the sign-in page, or the dashboard when signed in', function () {
    $this->get('/admin/dashboard')->assertRedirect(route('login'));
    $this->get('/home')->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())->get('/admin')->assertRedirect(route('dashboard'));
});

it('keeps a 404 for missing files and API calls', function () {
    $this->get('/build/assets/gone.js')->assertNotFound();
    $this->get('/favicon-old.png')->assertNotFound();
    $this->getJson('/nothing-here')->assertNotFound();
});
