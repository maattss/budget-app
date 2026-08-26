<?php

use Illuminate\Support\Facades\Route;

// A single-user budget app has no landing page to show. Send visitors to the
// dashboard; the auth middleware bounces them to the login screen if needed.
Route::redirect('/', 'dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    Route::livewire('assets', 'pages::assets.index')->name('assets.index');
    Route::livewire('assets/{asset}', 'pages::assets.show')->name('assets.show');

    Route::livewire('month', 'pages::month.show')->name('month.show');
});

require __DIR__.'/settings.php';
