<?php

use Illuminate\Support\Facades\Route;

// Admin-only ELO management: audited manual rating adjustments (placements, corrections),
// applied chronologically by the rating replay engine.
Route::middleware(['auth', 'verified', 'can:manage'])->group(function () {
    Route::livewire('admin/ratings', 'pages::ratings.adjustments')->name('rating-adjustments.index');
});
