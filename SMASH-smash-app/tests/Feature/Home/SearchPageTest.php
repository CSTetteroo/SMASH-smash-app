<?php

use App\Models\ClubNight;
use App\Models\Tournament;
use App\Models\User;
use Livewire\Livewire;

require_once __DIR__.'/HomeHelpers.php';

test('the search page shows popular links before anything is typed', function () {
    $this->get(route('search'))
        ->assertOk()
        ->assertSee('Go straight to')
        ->assertSee('Singles and doubles rankings')
        ->assertSee('Results appear as you type.');
});

test('search finds players, tournaments, club nights and matches', function () {
    $lotte = User::factory()->create(['name' => 'Lotte Jansen', 'email' => 'lotte.secret@smash.test']);
    $wei = User::factory()->create(['name' => 'Wei Chen']);
    homePlay([$lotte], [$wei]);
    Tournament::factory()->open()->create(['name' => 'Halloween Smash']);
    ClubNight::factory()->upcoming()->create(['title' => 'Beginners clinic']);

    $this->get(route('search', ['q' => 'lotte']))
        ->assertOk()
        ->assertSee('Lotte Jansen')
        ->assertDontSee('lotte.secret@smash.test');

    Livewire::test('pages::search')
        ->set('q', 'Jansen')
        ->assertSee('Lotte Jansen')
        ->assertSee('Players')
        ->assertSee('Matches')
        ->assertDontSee('lotte.secret@smash.test')
        ->set('q', 'halloween')
        ->assertSee('Halloween Smash')
        ->set('q', 'clinic')
        ->assertSee('Beginners clinic')
        ->set('q', 'wei lotte')
        ->assertSee('Wei Chen');
});

test('search never matches or reveals email addresses', function () {
    User::factory()->create(['name' => 'Priya Raman', 'email' => 'zebra-unique@smash.test']);

    Livewire::test('pages::search')
        ->set('q', 'zebra-unique')
        ->assertSee('Nothing found')
        ->assertDontSee('Priya Raman')
        ->set('q', 'Priya')
        ->assertSee('Priya Raman')
        ->assertDontSee('zebra-unique@smash.test');
});

test('draft tournaments are only found by admins', function () {
    Tournament::factory()->draft()->create(['name' => 'New Year Ladder Classic']);

    Livewire::test('pages::search')->set('q', 'ladder classic')->assertDontSee('New Year Ladder Classic');

    $this->actingAs(User::factory()->member()->create());
    Livewire::test('pages::search')->set('q', 'ladder classic')->assertDontSee('New Year Ladder Classic');

    $this->actingAs(User::factory()->admin()->create());
    Livewire::test('pages::search')->set('q', 'ladder classic')->assertSee('New Year Ladder Classic');
});

test('a one-letter search waits for more input and a miss shows the empty state', function () {
    User::factory()->create(['name' => 'Anna Bakker']);

    Livewire::test('pages::search')
        ->set('q', 'a')
        ->assertSee('Keep typing')
        ->assertDontSee('Anna Bakker')
        ->set('q', 'qqxyz')
        ->assertSee('Nothing found for')
        ->call('clear')
        ->assertSet('q', '');
});

test('choosing a result type shows only that type and ignores unknown types', function () {
    User::factory()->create(['name' => 'Smash Fan']);
    Tournament::factory()->open()->create(['name' => 'Smash Cup']);

    Livewire::test('pages::search')
        ->set('q', 'smash')
        ->call('show', 'tournaments')
        ->assertSet('type', 'tournaments')
        ->assertSee('Smash Cup')
        ->assertDontSee('Smash Fan')
        ->call('show', 'emails')
        ->assertSet('type', '')
        ->set('type', 'emails')
        ->assertSet('type', '')
        ->assertOk();
});

test('a result type without matches says so', function () {
    User::factory()->create(['name' => 'Smash Fan']);

    Livewire::test('pages::search')
        ->set('q', 'smash fan')
        ->call('show', 'tournaments')
        ->assertSee('No tournaments match')
        ->assertSee('Show all results');
});
