<?php

use App\Enums\Discipline;
use App\Enums\Programme;
use App\Enums\SkillLevel;
use App\Models\User;
use Livewire\Livewire;

test('guests can browse the player directory', function () {
    User::factory()->create(['name' => 'Sam de Vries', 'programme' => Programme::Games]);
    User::factory()->create(['name' => 'Lotte Jansen', 'programme' => Programme::Hotel]);

    $this->get(route('players.index'))
        ->assertOk()
        ->assertSee('Sam de Vries')
        ->assertSee('Lotte Jansen')
        ->assertSee('Games')
        ->assertDontSee(route('players.create'));
});

test('the directory never shows email addresses', function () {
    User::factory()->create(['name' => 'Sam de Vries', 'email' => 'sam.secret@smash.test']);

    $this->get(route('players.index'))->assertOk()->assertDontSee('sam.secret@smash.test');

    Livewire::test('pages::players.index')->assertDontSee('sam.secret@smash.test');
});

test('inactive players are hidden from guests and members', function () {
    User::factory()->create(['name' => 'Active Annie']);
    User::factory()->inactive()->create(['name' => 'Retired Rita']);

    $this->get(route('players.index'))->assertOk()->assertSee('Active Annie')->assertDontSee('Retired Rita');

    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test('pages::players.index')
        ->set('showInactive', true)
        ->assertSee('Active Annie')
        ->assertDontSee('Retired Rita');
});

test('admins can choose to list inactive players', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->inactive()->create(['name' => 'Retired Rita']);

    Livewire::actingAs($admin)
        ->test('pages::players.index')
        ->assertDontSee('Retired Rita')
        ->set('showInactive', true)
        ->assertSee('Retired Rita')
        ->assertSee('Inactive');
});

test('only admins see the new player button', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('players.index'))
        ->assertOk()
        ->assertDontSee(route('players.create'));

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('players.index'))
        ->assertOk()
        ->assertSee(route('players.create'));
});

test('the directory can be searched by name', function () {
    User::factory()->create(['name' => 'Sam de Vries']);
    User::factory()->create(['name' => 'Lotte Jansen']);

    Livewire::test('pages::players.index')
        ->set('search', 'vries')
        ->assertSee('Sam de Vries')
        ->assertDontSee('Lotte Jansen')
        ->set('search', 'nobody-by-that-name')
        ->assertSee('No players match those filters');
});

test('the directory filters by programme and level', function () {
    User::factory()->programme(Programme::Games)->level(SkillLevel::Beginner)->create(['name' => 'Gamer Beginner']);
    User::factory()->programme(Programme::Games)->level(SkillLevel::Advanced)->create(['name' => 'Gamer Advanced']);
    User::factory()->programme(Programme::Hotel)->level(SkillLevel::Beginner)->create(['name' => 'Hotel Beginner']);

    Livewire::test('pages::players.index')
        ->set('programme', 'games')
        ->assertSee('Gamer Beginner')
        ->assertSee('Gamer Advanced')
        ->assertDontSee('Hotel Beginner')
        ->set('level', 'beginner')
        ->assertSee('Gamer Beginner')
        ->assertDontSee('Gamer Advanced')
        ->assertDontSee('Hotel Beginner');
});

test('unknown filter values are ignored instead of failing', function () {
    User::factory()->create(['name' => 'Sam de Vries']);

    $this->get(route('players.index', ['programme' => 'astrology', 'level' => 'godlike', 'sort' => 'shoe-size']))
        ->assertOk()
        ->assertSee('Sam de Vries');
});

test('the directory sorts by rating, unrated players last', function () {
    User::factory()->withRating(Discipline::Singles, 1620, 12)->create(['name' => 'Bea Second']);
    User::factory()->withRating(Discipline::Singles, 1710, 12)->create(['name' => 'Ada First']);
    User::factory()->create(['name' => 'Aaron Unrated']);

    Livewire::test('pages::players.index')
        ->set('sort', 'singles')
        ->assertSeeInOrder(['Ada First', 'Bea Second', 'Aaron Unrated']);

    Livewire::test('pages::players.index')
        ->assertSeeInOrder(['Aaron Unrated', 'Ada First', 'Bea Second']);
});

test('newest sort lists recent members first and marks them as new', function () {
    User::factory()->create(['name' => 'Old Timer', 'created_at' => now()->subYear()]);
    User::factory()->create(['name' => 'Fresh Face', 'created_at' => now()->subDays(3)]);

    Livewire::test('pages::players.index')
        ->set('sort', 'newest')
        ->assertSeeInOrder(['Fresh Face', 'Old Timer'])
        ->assertSeeHtml('data-player-card');

    $html = Livewire::test('pages::players.index')->html();

    expect(substr_count($html, 'data-test="new-marker"'))->toBe(1);
});

test('the directory paginates long lists', function () {
    User::factory()->count(30)->create();

    $html = Livewire::test('pages::players.index')->html();
    expect(substr_count($html, 'wire:key="player-'))->toBe(24);

    $page2 = Livewire::withQueryParams(['page' => 2])->test('pages::players.index')->html();
    expect(substr_count($page2, 'wire:key="player-'))->toBe(6);
});
