<?php

use App\Actions\Matches\SaveMatch;
use App\Enums\MatchStatus;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournament\DrawGenerator;
use Livewire\Features\SupportComputed\CannotCallComputedDirectlyException;
use Livewire\Livewire;

test('guests see public tournaments on the index but not drafts', function () {
    Tournament::factory()->open()->create(['name' => 'Winter Doubles Showdown']);
    Tournament::factory()->inProgress()->create(['name' => 'Halloween Smash']);
    Tournament::factory()->completed()->create(['name' => 'Welcome Week Open']);
    Tournament::factory()->draft()->create(['name' => 'Secret Ladder Draft']);

    $this->get(route('tournaments.index'))
        ->assertOk()
        ->assertSee('Winter Doubles Showdown')
        ->assertSee('Halloween Smash')
        ->assertSee('Welcome Week Open')
        ->assertSee('Open for registration')
        ->assertDontSee('Secret Ladder Draft')
        ->assertDontSee('New tournament');
});

test('members do not see drafts, admins see them in their own section', function () {
    Tournament::factory()->draft()->create(['name' => 'Secret Ladder Draft']);

    $this->actingAs(User::factory()->create())
        ->get(route('tournaments.index'))
        ->assertOk()
        ->assertDontSee('Secret Ladder Draft');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('tournaments.index'))
        ->assertOk()
        ->assertSee('Secret Ladder Draft')
        ->assertSee('Drafts')
        ->assertSee('New tournament');
});

test('the index shows the champion of a completed knockout', function () {
    $tournament = Tournament::factory()->open()->singles()->knockout()->withEntries(2)->create(['name' => 'Mini Open']);
    DrawGenerator::generate($tournament);
    $final = $tournament->matches()->sole();
    SaveMatch::handle(['status' => MatchStatus::Completed, 'games' => [[21, 15], [21, 18]]], $final);

    $champion = $tournament->fresh()->champion();

    expect($tournament->fresh()->status->value)->toBe('completed');

    $this->get(route('tournaments.index'))
        ->assertOk()
        ->assertSee('Champion')
        ->assertSee($champion->name());
});

test('a draft tournament page is only visible to admins', function () {
    $draft = Tournament::factory()->draft()->create();

    $this->get(route('tournaments.show', $draft))->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('tournaments.show', $draft))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('tournaments.show', $draft))
        ->assertOk()
        ->assertSee('Committee desk')
        ->assertSee('Open registration');
});

test('every tab renders for guests, members and admins', function (string $tab) {
    $tournament = Tournament::factory()->open()->singles()->knockout()->withEntries(5)->create();
    DrawGenerator::generate($tournament);
    $member = $tournament->entries()->first()->player;

    foreach ([null, $member, User::factory()->admin()->create()] as $viewer) {
        $viewer ? $this->actingAs($viewer) : auth()->logout();

        $this->get(route('tournaments.show', ['tournament' => $tournament, 'tab' => $tab]))
            ->assertOk()
            ->assertSee($tournament->name);
    }
})->with(['overview', 'draw', 'entries', 'matches']);

test('a knockout draw renders with byes and highlights the viewer', function () {
    $tournament = Tournament::factory()->open()->singles()->knockout()->withEntries(6)->create();
    DrawGenerator::generate($tournament);
    $player = $tournament->entries()->first()->player;

    Livewire::actingAs($player)
        ->test('pages::tournaments.show', ['tournament' => $tournament])
        ->set('tab', 'draw')
        ->assertSet('tab', 'draw')
        ->assertSeeHtml('data-smash-bracket')
        ->assertSee('Bye')
        ->assertSee('Semi-finals')
        ->assertSee('Final')
        ->assertSee('2 byes');
});

test('a round robin shows the standings table and fixtures by matchday', function () {
    $tournament = Tournament::factory()->open()->singles()->roundRobin()->withEntries(4)->create();
    DrawGenerator::generate($tournament);

    $first = $tournament->matches()->orderBy('round')->orderBy('id')->first();
    SaveMatch::handle(['status' => MatchStatus::Completed, 'games' => [[21, 10], [21, 12]]], $first);
    $winner = $first->fresh()->winningEntry();

    Livewire::test('pages::tournaments.show', ['tournament' => $tournament->fresh()])
        ->set('tab', 'draw')
        ->assertSet('tab', 'standings')
        ->assertSee('Table')
        ->assertSee('Matchday 1')
        ->assertSee('Matchday 3')
        ->assertSee($winner->name())
        ->assertSee('1–0');
});

test('the draw tab before the draw explains when it is made', function () {
    $tournament = Tournament::factory()->open()->knockout()->withEntries(3)->create();

    $this->get(route('tournaments.show', ['tournament' => $tournament, 'tab' => 'draw']))
        ->assertOk()
        ->assertSee('The draw is made when registration closes');
});

test('unknown tabs fall back to the overview', function () {
    $tournament = Tournament::factory()->open()->create();

    Livewire::test('pages::tournaments.show', ['tournament' => $tournament])
        ->set('tab', 'nonsense')
        ->assertSet('tab', 'overview');
});

test('public pages never expose player email addresses', function () {
    $tournament = Tournament::factory()->open()->singles()->withEntries(3)->create();
    $emails = $tournament->entries()->with('player')->get()->pluck('player.email');

    $response = $this->get(route('tournaments.show', ['tournament' => $tournament, 'tab' => 'entries']))->assertOk();

    foreach ($emails as $email) {
        $response->assertDontSee($email);
    }
});

test('computed data with player models cannot be pulled through a direct Livewire call', function () {
    $tournament = Tournament::factory()->open()->singles()->withEntries(2)->create();

    expect(fn () => Livewire::test('pages::tournaments.show', ['tournament' => $tournament])->call('entries'))
        ->toThrow(CannotCallComputedDirectlyException::class);
});
