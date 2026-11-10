<?php

use App\Enums\MatchStatus;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use App\Services\Tournament\DrawGenerator;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function deskFor(Tournament $tournament, ?User $admin = null): Testable
{
    return Livewire::actingAs($admin ?? User::factory()->admin()->create())
        ->test('pages::tournaments.show', ['tournament' => $tournament]);
}

test('admins open registration for a draft, and only for a draft', function () {
    $draft = Tournament::factory()->draft()->create();

    deskFor($draft)->call('openRegistration')->assertHasNoErrors();
    expect($draft->fresh()->status)->toBe(TournamentStatus::Open);

    deskFor($draft->fresh())->call('openRegistration')->assertHasErrors('status');
});

test('admins make a knockout draw with every round and the byes', function () {
    $tournament = Tournament::factory()->open()->singles()->knockout()->withEntries(6)->create();

    deskFor($tournament)
        ->assertSee('Make the draw')
        ->call('generateDraw')
        ->assertHasNoErrors()
        ->assertSet('tab', 'draw')
        ->assertSee('Bye');

    $tournament->refresh();

    // Bracket of 8: 4 + 2 + 1 matches, two of them byes (walkovers without an opponent).
    expect($tournament->status)->toBe(TournamentStatus::InProgress)
        ->and($tournament->matches()->count())->toBe(7)
        ->and($tournament->matches()->where('round', 1)->count())->toBe(4)
        ->and($tournament->matches()->where('round', 3)->count())->toBe(1)
        ->and($tournament->matches()->where('status', MatchStatus::Walkover->value)->count())->toBe(2)
        ->and($tournament->entries()->whereNull('seed')->count())->toBe(0);
});

test('admins make a round robin draw', function () {
    $tournament = Tournament::factory()->open()->doubles()->roundRobin()->withEntries(4)->create();

    deskFor($tournament)
        ->call('generateDraw')
        ->assertHasNoErrors()
        ->assertSet('tab', 'standings')
        ->assertSee('Matchday 3');

    expect($tournament->matches()->count())->toBe(6);
});

test('the draw needs at least two entries', function () {
    $tournament = Tournament::factory()->open()->withEntries(1)->create();

    deskFor($tournament)->call('generateDraw')->assertHasErrors('draw');

    expect($tournament->matches()->count())->toBe(0)
        ->and($tournament->fresh()->status)->toBe(TournamentStatus::Open);
});

test('a draw cannot be made twice', function () {
    $tournament = Tournament::factory()->open()->withEntries(4)->create();
    DrawGenerator::generate($tournament);

    deskFor($tournament->fresh())->call('generateDraw')->assertHasErrors('draw');

    expect($tournament->matches()->count())->toBe(3);
});

test('admins reset the draw, optionally clearing the seeds', function () {
    $tournament = Tournament::factory()->open()->withEntries(4)->create();
    DrawGenerator::generate($tournament);

    deskFor($tournament->fresh())
        ->assertSee('Reset draw')
        ->call('resetDraw')
        ->assertHasNoErrors()
        ->assertSet('tab', 'entries');

    expect($tournament->matches()->count())->toBe(0)
        ->and($tournament->fresh()->status)->toBe(TournamentStatus::Open)
        ->and($tournament->entries()->whereNotNull('seed')->count())->toBe(4);

    DrawGenerator::generate($tournament->fresh());

    deskFor($tournament->fresh())->set('clearSeeds', true)->call('resetDraw');

    expect($tournament->entries()->whereNotNull('seed')->count())->toBe(0);

    deskFor($tournament->fresh())->call('resetDraw')->assertHasErrors('draw');
});

test('admins add an entry on behalf of players', function () {
    $singles = Tournament::factory()->open()->singles()->create();
    $player = User::factory()->create();

    deskFor($singles)
        ->set('addPlayerId', (string) $player->id)
        ->call('addEntry')
        ->assertHasNoErrors();

    expect($singles->entries()->sole()->player_id)->toBe($player->id);

    // Entering the same player again fails with the action's message.
    deskFor($singles)
        ->set('addPlayerId', (string) $player->id)
        ->call('addEntry')
        ->assertHasErrors('add.tournament');

    $doubles = Tournament::factory()->open()->doubles()->create();
    [$sam, $lotte] = User::factory()->count(2)->create();

    deskFor($doubles)->set('addPlayerId', (string) $sam->id)->call('addEntry')->assertHasErrors('add.partner');
    deskFor($doubles)->set('addPlayerId', '')->call('addEntry')->assertHasErrors('add.player');

    deskFor($doubles)
        ->set('addPlayerId', (string) $sam->id)
        ->set('addPartnerId', (string) $lotte->id)
        ->call('addEntry')
        ->assertHasNoErrors();

    expect($doubles->entries()->sole())->player_id->toBe($sam->id)->partner_id->toBe($lotte->id);
});

test('admins remove an entry before the draw, never one from another tournament', function () {
    $tournament = Tournament::factory()->open()->withEntries(3)->create();
    $other = TournamentEntry::factory()->for(Tournament::factory()->open())->create();
    $entry = $tournament->entries()->first();

    deskFor($tournament)
        ->call('confirmRemove', $other->id)
        ->assertSet('removeId', null)
        ->call('removeEntry');

    expect($other->fresh())->not->toBeNull();

    deskFor($tournament)
        ->call('confirmRemove', $entry->id)
        ->assertSet('removeId', $entry->id)
        ->call('removeEntry')
        ->assertHasNoErrors();

    expect($entry->fresh())->toBeNull()
        ->and($tournament->entries()->count())->toBe(2);
});

test('entries cannot be removed once the draw is made', function () {
    $tournament = Tournament::factory()->open()->withEntries(3)->create();
    DrawGenerator::generate($tournament);
    $entry = $tournament->entries()->first();

    deskFor($tournament->fresh())
        ->call('confirmRemove', $entry->id)
        ->call('removeEntry')
        ->assertHasErrors('tournament');

    expect($entry->fresh())->not->toBeNull();
});

test('admins edit seeds before the draw, and the draw keeps them', function () {
    $tournament = Tournament::factory()->open()->singles()->knockout()->withEntries(4)->create();
    [$first, $second] = $tournament->entries()->orderBy('id')->get()->all();

    deskFor($tournament)
        ->set('tab', 'entries')
        ->assertSee('Save seeds')
        ->set("seeds.e{$first->id}", '2')
        ->set("seeds.e{$second->id}", '2')
        ->call('saveSeeds')
        ->assertHasErrors('seeds');

    expect($first->fresh()->seed)->toBeNull();

    deskFor($tournament)
        ->set("seeds.e{$first->id}", '9')
        ->call('saveSeeds')
        ->assertHasErrors("seeds.e{$first->id}");

    deskFor($tournament)
        ->set("seeds.e{$first->id}", '1')
        ->set("seeds.e{$second->id}", '2')
        ->call('saveSeeds')
        ->assertHasNoErrors();

    expect($first->fresh()->seed)->toBe(1)->and($second->fresh()->seed)->toBe(2);

    DrawGenerator::generate($tournament->fresh());

    expect($first->fresh()->seed)->toBe(1)->and($second->fresh()->seed)->toBe(2);

    deskFor($tournament->fresh())->call('saveSeeds')->assertHasErrors('seeds');
});

test('members cannot call the committee actions directly', function (string $method, array $parameters) {
    $tournament = Tournament::factory()->open()->withEntries(4)->create();
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test('pages::tournaments.show', ['tournament' => $tournament])
        ->set('addPlayerId', (string) $member->id)
        ->call($method, ...$parameters)
        ->assertForbidden();

    expect($tournament->fresh()->status)->toBe(TournamentStatus::Open)
        ->and($tournament->matches()->count())->toBe(0)
        ->and($tournament->entries()->count())->toBe(4);
})->with([
    'open registration' => ['openRegistration', []],
    'make the draw' => ['generateDraw', []],
    'reset the draw' => ['resetDraw', []],
    'add an entry' => ['addEntry', []],
    'ask to remove an entry' => ['confirmRemove', [1]],
    'remove an entry' => ['removeEntry', []],
    'save seeds' => ['saveSeeds', []],
]);

test('guests cannot call the committee actions either', function () {
    $tournament = Tournament::factory()->open()->withEntries(4)->create();

    Livewire::test('pages::tournaments.show', ['tournament' => $tournament])
        ->call('generateDraw')
        ->assertForbidden();

    expect($tournament->matches()->count())->toBe(0);
});

test('members do not see the committee desk', function () {
    $tournament = Tournament::factory()->open()->withEntries(4)->create();

    $this->actingAs(User::factory()->create())
        ->get(route('tournaments.show', $tournament))
        ->assertOk()
        ->assertDontSee('Committee desk')
        ->assertDontSee('Make the draw');
});
