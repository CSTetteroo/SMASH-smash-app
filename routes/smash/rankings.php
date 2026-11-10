<?php

use Illuminate\Support\Facades\Route;

Route::livewire('rankings', 'pages::rankings.index')->name('rankings.index');
Route::livewire('head-to-head', 'pages::rankings.head-to-head')->name('head-to-head');
