<?php

use App\Http\Controllers\ClubNightCalendarController;
use Illuminate\Support\Facades\Route;

Route::livewire('club-nights', 'pages::club-nights.index')->name('club-nights.index');

// iCalendar feeds: all club nights (?kind= filters) and a single night ("Add to calendar").
Route::get('club-nights.ics', ClubNightCalendarController::class)->name('club-nights.ics');
Route::get('club-nights/{clubNight}.ics', ClubNightCalendarController::class)->name('club-nights.show.ics')->whereNumber('clubNight');

Route::middleware(['auth', 'verified', 'can:manage'])->group(function () {
    Route::livewire('club-nights/create', 'pages::club-nights.create')->name('club-nights.create');
    Route::livewire('club-nights/{clubNight}/edit', 'pages::club-nights.edit')->name('club-nights.edit')->whereNumber('clubNight');
});

Route::livewire('club-nights/{clubNight}', 'pages::club-nights.show')->name('club-nights.show')->whereNumber('clubNight');
