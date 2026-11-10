<?php

use App\Enums\Discipline;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\BadmintonMatch;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use App\Services\Tournament\DrawGenerator;
use Livewire\Livewire;

/**
 * @return array<string, mixed>
 */
function tournamentForm(array $overrides = []): array
{
    return array_merge([
        'name' => 'Spring Singles Open',
        'description' => 'A one-day singles knockout for every level.',
        'discipline' => 'singles',
        'format' => 'single_elimination',
        'venue' => 'BUas Sports Hall',
        'starts_at' => '2027-03-13T10:00',
        'ends_at' => '2027-03-13T16:00',
        'registration_closes_at' => '2027-03-11T23:00',
        'max_entries' => '16',
        'best_of' => '3',
        'points_per_game' => '21',
        'is_rated' => true,
    ], $overrides);
}

test('only admins reach the create and edit pages', function () {
    $tournament = Tournament::factory()->open()->create();

    $this->get(route('tournaments.create'))->assertRedirect(route('login'));
    $this->get(route('tournaments.edit', $tournament))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create());
    $this->get(route('tournaments.create'))->assertForbidden();
    $this->get(route('tournaments.edit', $tournament))->assertForbidden();

    $this->actingAs(User::factory()->admin()->create());
    $this->get(route('tournaments.create'))->assertOk()->assertSee('New tournament');
    $this->get(route('tournaments.edit', $tournament))->assertOk()->assertSee($tournament->name);
});

test('members cannot use the form components directly', function () {
    $member = User::factory()->create();
    $tournament = Tournament::factory()->open()->create();

    Livewire::actingAs($member)->test('pages::tournaments.create')->assertForbidden();
    Livewire::actingAs($member)->test('pages::tournaments.edit', ['tournament' => $tournament])->assertForbidden();
});

test('admins create a draft tournament', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::tournaments.create')
        ->set('form', tournamentForm())
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('tournaments.show', 'spring-singles-open'));

    $tournament = Tournament::query()->where('slug', 'spring-singles-open')->sole();

    expect($tournament)
        ->status->toBe(TournamentStatus::Draft)
        ->discipline->toBe(Discipline::Singles)
        ->format->toBe(TournamentFormat::SingleElimination)
        ->max_entries->toBe(16)
        ->created_by->toBe($admin->id)
        ->and($tournament->starts_at->format('Y-m-d H:i'))->toBe('2027-03-13 10:00')
        ->and($tournament->registration_closes_at->format('Y-m-d H:i'))->toBe('2027-03-11 23:00');
});

test('admins can open registration straight away and leave optional fields empty', function () {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::tournaments.create')
        ->set('form', tournamentForm([
            'name' => 'Open Doubles Night',
            'discipline' => 'doubles',
            'format' => 'round_robin',
            'ends_at' => '',
            'registration_closes_at' => '',
            'max_entries' => '',
            'venue' => '',
            'description' => '',
        ]))
        ->set('openNow', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(Tournament::query()->where('name', 'Open Doubles Night')->sole())
        ->status->toBe(TournamentStatus::Open)
        ->format->toBe(TournamentFormat::RoundRobin)
        ->ends_at->toBeNull()
        ->registration_closes_at->toBeNull()
        ->max_entries->toBeNull()
        ->venue->toBeNull();
});

test('the form validates dates, sizes and formats', function (array $overrides, string $error) {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::tournaments.create')
        ->set('form', tournamentForm($overrides))
        ->call('save')
        ->assertHasErrors($error);

    expect(Tournament::query()->count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'form.name'],
    'finish before the start' => [['ends_at' => '2027-03-13T09:00'], 'form.ends_at'],
    'deadline after the start' => [['registration_closes_at' => '2027-03-14T10:00'], 'form.registration_closes_at'],
    'room for one entry' => [['max_entries' => '1'], 'form.max_entries'],
    'unknown discipline' => [['discipline' => 'mixed'], 'form.discipline'],
    'unknown format' => [['format' => 'swiss'], 'form.format'],
    'best of four' => [['best_of' => '4'], 'form.best_of'],
    'games to 25' => [['points_per_game' => '25'], 'form.points_per_game'],
    'no start' => [['starts_at' => ''], 'form.starts_at'],
]);

test('admins edit a tournament', function () {
    $tournament = Tournament::factory()->open()->singles()->knockout()->create(['name' => 'Old Name']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::tournaments.edit', ['tournament' => $tournament])
        ->assertSet('form.name', 'Old Name')
        ->set('form.name', 'New Name')
        ->set('form.format', 'round_robin')
        ->set('form.max_entries', '8')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('tournaments.show', $tournament->slug));

    expect($tournament->fresh())
        ->name->toBe('New Name')
        ->slug->toBe($tournament->slug)
        ->format->toBe(TournamentFormat::RoundRobin)
        ->max_entries->toBe(8);
});

test('the maximum cannot drop below the entries already in', function () {
    $tournament = Tournament::factory()->open()->withEntries(5)->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::tournaments.edit', ['tournament' => $tournament])
        ->set('form.max_entries', '4')
        ->call('save')
        ->assertHasErrors('form.max_entries');
});

test('discipline is locked once players registered, format and scoring once a draw exists', function () {
    $tournament = Tournament::factory()->open()->singles()->knockout()->withEntries(4)->create(['best_of' => 3]);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::tournaments.edit', ['tournament' => $tournament])
        ->assertSee('Players have registered, so the discipline is fixed.')
        ->set('form.discipline', 'doubles')
        ->call('save')
        ->assertHasNoErrors();

    expect($tournament->fresh()->discipline)->toBe(Discipline::Singles);

    DrawGenerator::generate($tournament->fresh());

    Livewire::actingAs($admin)
        ->test('pages::tournaments.edit', ['tournament' => $tournament->fresh()])
        ->assertSee('The draw is made')
        ->set('form.format', 'round_robin')
        ->set('form.best_of', '5')
        ->set('form.is_rated', false)
        ->set('form.name', 'Renamed During Play')
        ->call('save')
        ->assertHasNoErrors();

    expect($tournament->fresh())
        ->format->toBe(TournamentFormat::SingleElimination)
        ->best_of->toBe(3)
        ->is_rated->toBeTrue()
        ->name->toBe('Renamed During Play');
});

test('admins delete a tournament with its draw', function () {
    $tournament = Tournament::factory()->open()->withEntries(4)->create();
    DrawGenerator::generate($tournament);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::tournaments.edit', ['tournament' => $tournament->fresh()])
        ->call('delete')
        ->assertRedirect(route('tournaments.index'));

    expect(Tournament::query()->count())->toBe(0)
        ->and(BadmintonMatch::query()->count())->toBe(0)
        ->and(TournamentEntry::query()->count())->toBe(0);
});
