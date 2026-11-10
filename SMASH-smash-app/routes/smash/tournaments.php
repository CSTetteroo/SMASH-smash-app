<?php

use Illuminate\Support\Facades\Route;

Route::livewire('tournaments', 'pages::tournaments.index')->name('tournaments.index');

Route::middleware(['auth', 'verified', 'can:manage'])->group(function () {
    Route::livewire('tournaments/create', 'pages::tournaments.create')->name('tournaments.create');
    Route::livewire('tournaments/{tournament}/edit', 'pages::tournaments.edit')->name('tournaments.edit');
});

// {tournament} binds by slug (Tournament::getRouteKeyName()).
Route::livewire('tournaments/{tournament}', 'pages::tournaments.show')->name('tournaments.show');
