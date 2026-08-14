<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('assets', 'pages::assets.index')->name('assets.index');

    Route::livewire('month', 'pages::month.show')->name('month.show');
});

require __DIR__.'/settings.php';
