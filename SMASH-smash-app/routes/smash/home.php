<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::home')->name('home');
Route::livewire('search', 'pages::search')->name('search');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
});

Route::middleware(['auth', 'verified', 'can:manage'])->group(function () {
    Route::livewire('admin', 'pages::admin.index')->name('admin.index');
});
