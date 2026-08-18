<?php

use App\Models\User;

test('the root url sends guests to the login screen', function () {
    $this->get(route('home'))
        ->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

test('the root url sends signed in users to the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});
