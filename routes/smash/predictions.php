<?php

use Illuminate\Support\Facades\Route;

Route::livewire('predictor', 'pages::predictions.predictor')->name('predictor');
// Public leaderboard; placing predictions requires login (enforced in the component).
Route::livewire('predictions', 'pages::predictions.index')->name('predictions.index');
Route::livewire('how-it-works', 'pages::predictions.how-it-works')->name('how-it-works');
