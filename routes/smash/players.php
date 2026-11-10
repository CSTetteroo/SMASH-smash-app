<?php

use Illuminate\Support\Facades\Route;

Route::livewire('players', 'pages::players.index')->name('players.index');

Route::middleware(['auth', 'verified', 'can:manage'])->group(function () {
    Route::livewire('players/create', 'pages::players.create')->name('players.create');
    Route::livewire('players/{user}/edit', 'pages::players.edit')->name('players.edit')->whereNumber('user');
});

Route::livewire('players/{user}', 'pages::players.show')->name('players.show')->whereNumber('user');

Route::middleware(['auth'])->group(function () {
    Route::livewire('settings/player', 'pages::settings.player')->name('player-profile.edit');
});
