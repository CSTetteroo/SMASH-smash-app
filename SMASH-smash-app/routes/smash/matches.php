<?php

use Illuminate\Support\Facades\Route;

Route::livewire('matches', 'pages::matches.index')->name('matches.index');

// Members may log friendlies they played in; admins may create any match.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('matches/create', 'pages::matches.create')->name('matches.create');
});

Route::middleware(['auth', 'verified', 'can:manage'])->group(function () {
    Route::livewire('matches/{match}/edit', 'pages::matches.edit')->name('matches.edit')->whereNumber('match');
});

Route::livewire('matches/{match}', 'pages::matches.show')->name('matches.show')->whereNumber('match');
