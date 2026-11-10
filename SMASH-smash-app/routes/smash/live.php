<?php

use Illuminate\Support\Facades\Route;

// Umpire / live scoring mode. Authorised (admin or participant) inside the component.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('matches/{match}/score', 'pages::matches.score')->name('matches.score')->whereNumber('match');
});

// Read-only preview of the umpire console in a given scoring situation (nothing is saved), for
// design review without scoring a match in the dev database. Local only, committee only.
if (app()->environment('local', 'testing')) {
    Route::middleware(['auth', 'can:manage'])->group(function () {
        Route::livewire('_dev/live-preview/{match}/{scenario?}', 'live.preview')
            ->name('dev.live-preview')
            ->whereNumber('match');
    });
}
