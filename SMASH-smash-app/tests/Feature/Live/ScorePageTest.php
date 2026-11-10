<?php

use App\Actions\Matches\SaveMatch;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournament\DrawGenerator;
use App\Support\Badminton\Scoring;
use App\Support\Live\LiveScoreStore;
use App\Support\Live\Umpire;
use Livewire\Livewire;

/**
 * A scheduled singles friendly between two fresh members.
 */
function umpireMatch(?User $a = null, ?User $b = null): BadmintonMatch
{
    return BadmintonMatch::factory()->singles()->scheduled()
        ->withPlayers($a ?? User::factory()->create(), $b ?? User::factory()->create())
        ->create();
}

function playerOn(BadmintonMatch $match, Side $side): User
{
    return $match->fresh()->playersOn($side)->first();
}

/**
 * Score rallies straight through the umpire service ("ab" = A, then B): quick set-up for tests
 * that are about what happens next.
 */
function umpireRallies(BadmintonMatch $match, User $actor, string $winners): void
{
    foreach (str_split($winners) as $winner) {
        Umpire::point($match, Side::from($winner), $actor);
    }
}

/**
 * Rallies that take a game from 0–0 to $a–$b without ending it on the way.
 */
function gameTo(int $a, int $b): string
{
    $shared = min($a, $b);

    return str_repeat('ab', $shared).str_repeat('a', $a - $shared).str_repeat('b', $b - $shared);
}

/**
 * @return list<array{a: int, b: int}>
 */
function storedGames(BadmintonMatch $match): array
{
    return Scoring::fromModels($match->fresh()->games);
}

// ---------------------------------------------------------------------------------------------
// Who may score
// ---------------------------------------------------------------------------------------------

test('guests are sent to the login page', function () {
    $this->get(route('matches.score', umpireMatch()))->assertRedirect(route('login'));
});

test('members who do not play in the match may not score it', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('matches.score', umpireMatch()))
        ->assertForbidden();
});

test('unverified players are asked to verify their email first', function () {
    $player = User::factory()->unverified()->create();

    $this->actingAs($player)
        ->get(route('matches.score', umpireMatch($player)))
        ->assertRedirect(route('verification.notice'));
});

test('a player in the match can open the umpire console', function () {
    $match = umpireMatch();

    $this->actingAs(playerOn($match, Side::B))
        ->get(route('matches.score', $match))
        ->assertOk()
        ->assertSee('Who serves first?')
        ->assertSee('Start match')
        ->assertSee($match->sideLabel(Side::A));
});

test('admins can score any match, also one they do not play in', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('matches.score', umpireMatch()))
        ->assertOk()
        ->assertSee('Umpire mode');
});

test('a finished match can no longer be scored by its players, and admins only see the result', function () {
    $match = umpireMatch();
    SaveMatch::handle(['status' => MatchStatus::Completed, 'games' => [[21, 15], [21, 18]]], $match);

    $this->actingAs(playerOn($match, Side::A))
        ->get(route('matches.score', $match))
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('matches.score', $match))
        ->assertOk()
        ->assertSee('This match is finished')
        ->assertDontSee('Start match');
});

test('a cancelled match cannot be scored by its players', function () {
    $match = umpireMatch();
    $match->update(['status' => MatchStatus::Cancelled]);

    $this->actingAs(playerOn($match, Side::A))
        ->get(route('matches.score', $match))
        ->assertForbidden();
});

test('the page shows no player email addresses', function () {
    $match = umpireMatch();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('matches.score', $match))
        ->assertOk()
        ->assertDontSee(playerOn($match, Side::A)->email)
        ->assertDontSee(playerOn($match, Side::B)->email);
});

test('a player who lost access mid-match cannot keep calling the actions directly', function () {
    $match = umpireMatch();
    $player = playerOn($match, Side::A);

    Umpire::start($match, Side::A, $player);

    // Three consoles open on the player's phone (a 403 ends a test component's life).
    $consoles = collect(range(1, 3))->map(fn () => Livewire::actingAs($player)->test('pages::matches.score', ['match' => $match]));

    // The committee enters the result elsewhere: the match is finished, so the player is out.
    SaveMatch::handle(['status' => MatchStatus::Completed, 'games' => [[21, 3], [21, 4]]], $match->fresh());

    $consoles[0]->call('point', 'a')->assertForbidden();
    $consoles[1]->call('undo')->assertForbidden();
    $consoles[2]->call('finish')->assertForbidden();

    expect(storedGames($match))->toBe([['a' => 21, 'b' => 3], ['a' => 21, 'b' => 4]]);
});

test('a member who is not playing cannot use a console another user opened', function () {
    $match = umpireMatch();
    $component = Livewire::actingAs(User::factory()->admin()->create())->test('pages::matches.score', ['match' => $match]);

    $this->actingAs(User::factory()->create());

    $component->set('firstServer', 'a')->call('start')->assertForbidden();

    expect($match->fresh()->status)->toBe(MatchStatus::Scheduled);
});

test('the match id cannot be swapped from the browser', function () {
    $component = Livewire::actingAs(User::factory()->admin()->create())->test('pages::matches.score', ['match' => umpireMatch()]);

    expect(fn () => $component->set('matchId', umpireMatch()->id))->toThrow(Exception::class);
});

// ---------------------------------------------------------------------------------------------
// Starting and scoring
// ---------------------------------------------------------------------------------------------

test('starting asks for the first server, then takes the match live', function () {
    $match = umpireMatch();
    $player = playerOn($match, Side::A);

    $component = Livewire::actingAs($player)->test('pages::matches.score', ['match' => $match])
        ->assertSet('firstServer', '')
        ->call('start')
        ->assertHasErrors('firstServer');

    expect($match->fresh()->status)->toBe(MatchStatus::Scheduled);

    $component->set('firstServer', 'b')
        ->call('start')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $state = $component->instance()->state;

    expect($match->fresh()->status)->toBe(MatchStatus::InProgress)
        ->and($component->instance()->phase)->toBe('rally')
        ->and($state->serving())->toBe(Side::B)
        ->and($state->serviceCourt())->toBe('right')
        ->and(LiveScoreStore::cached($match))->not->toBeNull();
});

test('a tournament match whose opponent is not known yet cannot be started', function () {
    $tournament = Tournament::factory()->open()->knockout()->withEntries(4)->create();
    DrawGenerator::generate($tournament);
    $final = $tournament->matches()->where('round', 2)->sole();

    Livewire::actingAs(User::factory()->admin()->create())->test('pages::matches.score', ['match' => $final])
        ->assertSee('Both sides must be known before this match can start')
        ->set('firstServer', 'a')
        ->call('start')
        ->assertDispatched('toast-show');

    expect($final->fresh()->status)->toBe(MatchStatus::Scheduled);
});

test('every rally is saved on the match so the public match page shows it', function () {
    $match = umpireMatch();
    $admin = User::factory()->admin()->create();

    $component = Livewire::actingAs($admin)->test('pages::matches.score', ['match' => $match])
        ->set('firstServer', 'a')
        ->call('start');

    $component->call('point', 'a')->call('point', 'a')->call('point', 'b');

    $state = $component->instance()->state;

    expect(storedGames($match))->toBe([['a' => 2, 'b' => 1]])
        ->and($match->fresh()->status)->toBe(MatchStatus::InProgress)
        ->and($state->serving())->toBe(Side::B)
        ->and($state->serviceCourt())->toBe('left')
        ->and($component->get('version'))->toBe($state->version());
});

test('only side A or side B can win a rally', function () {
    $match = umpireMatch();

    Livewire::actingAs(User::factory()->admin()->create())->test('pages::matches.score', ['match' => $match])
        ->set('firstServer', 'a')
        ->call('start')
        ->call('point', 'c')
        ->assertHasErrors('side');

    expect(storedGames($match))->toBe([['a' => 0, 'b' => 0]]);
});

test('a game ends at 21–19 and the winner serves first in the next game', function () {
    $match = umpireMatch();
    $admin = User::factory()->admin()->create();
    Umpire::start($match, Side::B, $admin);
    umpireRallies($match, $admin, gameTo(20, 19));

    $component = Livewire::actingAs($admin)->test('pages::matches.score', ['match' => $match])
        ->assertSee('Game point')
        ->call('point', 'a')
        ->assertSee('Game 1 to');

    $state = $component->instance()->state;

    expect(storedGames($match))->toBe([['a' => 21, 'b' => 19], ['a' => 0, 'b' => 0]])
        ->and($state->gameNumber())->toBe(2)
        ->and($state->serving())->toBe(Side::A)
        ->and($state->serviceCourt())->toBe('right');
});

test('20–20 carries on, 22–20 ends the game', function () {
    $match = umpireMatch();
    $admin = User::factory()->admin()->create();
    Umpire::start($match, Side::A, $admin);
    umpireRallies($match, $admin, gameTo(20, 20));

    $component = Livewire::actingAs($admin)->test('pages::matches.score', ['match' => $match])
        ->assertSee('Setting')
        ->call('point', 'b')
        ->call('point', 'a');

    expect(storedGames($match))->toBe([['a' => 21, 'b' => 21]]);

    $component->call('point', 'a')->call('point', 'a');

    expect(storedGames($match))->toBe([['a' => 23, 'b' => 21], ['a' => 0, 'b' => 0]]);
});

test('29–29 is a golden point and 30–29 wins the game', function () {
    $match = umpireMatch();
    $admin = User::factory()->admin()->create();
    Umpire::start($match, Side::A, $admin);
    umpireRallies($match, $admin, gameTo(29, 29));

    Livewire::actingAs($admin)->test('pages::matches.score', ['match' => $match])
        ->assertSee('Golden point')
        ->call('point', 'b');

    expect(storedGames($match))->toBe([['a' => 29, 'b' => 30], ['a' => 0, 'b' => 0]]);
});

test('the interval is called at 11, with a change of ends in the deciding game', function () {
    $match = umpireMatch();
    $admin = User::factory()->admin()->create();
    Umpire::start($match, Side::A, $admin);
    umpireRallies($match, $admin, gameTo(10, 6));

    Livewire::actingAs($admin)->test('pages::matches.score', ['match' => $match])
        ->call('point', 'a')
        ->assertSee('Interval')
        ->assertDontSee('Interval · change ends');

    umpireRallies($match, $admin, str_repeat('a', 10).gameTo(6, 21).gameTo(8, 10)); // 21–6, 6–21, 8–10

    Livewire::actingAs($admin)->test('pages::matches.score', ['match' => $match])
        ->call('point', 'b')
        ->assertSee('Interval · change ends')
        ->assertSee('11–8 to');
});

test('undo takes back the last rally, also after a reload', function () {
    $match = umpireMatch();
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('pages::matches.score', ['match' => $match])
        ->set('firstServer', 'a')
        ->call('start')
        ->call('point', 'a')
        ->call('point', 'b');

    // A fresh page (reload) restores the rally log from the cache.
    $reloaded = Livewire::actingAs($admin)->test('pages::matches.score', ['match' => $match]);

    expect($reloaded->instance()->state->serving())->toBe(Side::B);

    $reloaded->call('undo');

    expect(storedGames($match))->toBe([['a' => 1, 'b' => 0]])
        ->and($reloaded->instance()->state->serving())->toBe(Side::A);

    $reloaded->call('undo')->call('undo'); // the rally, then the server choice

    expect(storedGames($match))->toBe([['a' => 0, 'b' => 0]])
        ->and($reloaded->instance()->phase)->toBe('serve');

    $reloaded->call('undo')->assertDispatched('toast-show');
});

test('a tap based on an outdated score is refused when another device scored in between', function () {
    $match = umpireMatch();
    $admin = User::factory()->admin()->create();
    Umpire::start($match, Side::A, $admin);

    $phone = Livewire::actingAs($admin)->test('pages::matches.score', ['match' => $match]);
    $laptop = Livewire::actingAs($admin)->test('pages::matches.score', ['match' => $match]);

    $phone->call('point', 'a');
    $laptop->call('point', 'a')->assertDispatched('toast-show');

    expect(storedGames($match))->toBe([['a' => 1, 'b' => 0]]);

    // The refused tap re-rendered the laptop with the new score, so the next tap counts.
    $laptop->call('point', 'b');

    expect(storedGames($match))->toBe([['a' => 1, 'b' => 1]]);
});

test('a match scored elsewhere is picked up from its saved games and asks who serves', function () {
    $match = umpireMatch();
    SaveMatch::handle(['status' => MatchStatus::InProgress, 'games' => [[21, 14], [7, 12]]], $match);

    $component = Livewire::actingAs(User::factory()->admin()->create())->test('pages::matches.score', ['match' => $match])
        ->assertSee('Who serves next?');

    expect($component->instance()->phase)->toBe('serve');

    $component->call('serve', 'b');

    $state = $component->instance()->state;

    expect($component->instance()->phase)->toBe('rally')
        ->and($state->serving())->toBe(Side::B)
        ->and($state->serviceCourt())->toBe('right')
        ->and(storedGames($match))->toBe([['a' => 21, 'b' => 14], ['a' => 7, 'b' => 12]]);

    $component->call('point', 'a');

    expect(storedGames($match))->toBe([['a' => 21, 'b' => 14], ['a' => 8, 'b' => 12]]);
});

test('swapping ends is remembered for the match on this device', function () {
    $match = umpireMatch();
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('pages::matches.score', ['match' => $match])
        ->assertSet('swapped', false)
        ->call('swap')
        ->assertSet('swapped', true);

    Livewire::actingAs($admin)->test('pages::matches.score', ['match' => $match])->assertSet('swapped', true);
    Livewire::actingAs($admin)->test('pages::matches.score', ['match' => umpireMatch()])->assertSet('swapped', false);
});

// ---------------------------------------------------------------------------------------------
// Finishing
// ---------------------------------------------------------------------------------------------

test('the deciding rally asks for confirmation and is only saved with the result', function () {
    $match = umpireMatch();
    $admin = User::factory()->admin()->create();
    Umpire::start($match, Side::A, $admin);
    umpireRallies($match, $admin, gameTo(21, 10).gameTo(20, 15));

    $component = Livewire::actingAs($admin)->test('pages::matches.score', ['match' => $match])
        ->assertSee('Match point')
        ->call('point', 'a')
        ->assertDispatched('modal-show', name: 'confirm-result')
        ->assertSee('Game, set and match');

    expect($component->instance()->phase)->toBe('decided')
        ->and($match->fresh()->status)->toBe(MatchStatus::InProgress)
        ->and(storedGames($match))->toBe([['a' => 21, 'b' => 10], ['a' => 20, 'b' => 15]]);

    // Second thoughts: undo the deciding rally and play on.
    $component->call('undo');

    expect($component->instance()->phase)->toBe('rally');

    $component->call('point', 'b');

    expect(storedGames($match))->toBe([['a' => 21, 'b' => 10], ['a' => 20, 'b' => 16]]);
});

test('confirming the result completes the match, updates ratings and goes to the match page', function () {
    $match = umpireMatch();
    $winner = playerOn($match, Side::A);
    $loser = playerOn($match, Side::B);
    Umpire::start($match, Side::A, $winner);
    umpireRallies($match, $winner, gameTo(21, 17).gameTo(18, 21).gameTo(20, 18));

    Livewire::actingAs($winner)->test('pages::matches.score', ['match' => $match])
        ->call('point', 'a')
        ->call('finish')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show')
        ->assertRedirect(route('matches.show', $match));

    $match->refresh();
    $winner->refresh();
    $loser->refresh();

    expect($match->status)->toBe(MatchStatus::Completed)
        ->and($match->winning_side)->toBe(Side::A)
        ->and($match->played_at)->not->toBeNull()
        ->and(storedGames($match))->toBe([['a' => 21, 'b' => 17], ['a' => 18, 'b' => 21], ['a' => 21, 'b' => 18]])
        ->and($winner->singles_played)->toBe(1)
        ->and($winner->singles_wins)->toBe(1)
        ->and($winner->singles_rating)->toBeGreaterThan(1500.0)
        ->and($loser->singles_rating)->toBeLessThan(1500.0)
        ->and($match->participantFor($winner)?->rating_after)->not->toBeNull()
        ->and(LiveScoreStore::cached($match))->toBeNull();
});

test('a result cannot be confirmed before the match is decided', function () {
    $match = umpireMatch();
    $admin = User::factory()->admin()->create();
    Umpire::start($match, Side::A, $admin);
    umpireRallies($match, $admin, gameTo(21, 5));

    Livewire::actingAs($admin)->test('pages::matches.score', ['match' => $match])
        ->call('finish')
        ->assertDispatched('toast-show')
        ->assertNoRedirect();

    expect($match->fresh()->status)->toBe(MatchStatus::InProgress);
});

test('finishing a knockout match moves the winner into the next round', function () {
    $tournament = Tournament::factory()->open()->knockout()->withEntries(4)->create();
    DrawGenerator::generate($tournament);
    $semi = $tournament->matches()->where('round', 1)->where('bracket_position', 1)->sole();
    $player = $semi->entryB->player;

    Livewire::actingAs($player)->test('pages::matches.score', ['match' => $semi])
        ->set('firstServer', 'b')
        ->call('start');

    umpireRallies($semi, $player, gameTo(15, 21).gameTo(19, 20));

    Livewire::actingAs($player)->test('pages::matches.score', ['match' => $semi])
        ->call('point', 'b')
        ->assertSee('The winner moves on to the next round of '.e($tournament->name), false)
        ->call('finish')
        ->assertRedirect(route('matches.show', $semi));

    $final = $tournament->matches()->where('round', 2)->sole();

    expect($semi->fresh()->winning_side)->toBe(Side::B)
        ->and($final->entry_a_id)->toBe($semi->entry_b_id)
        ->and($final->entryA?->player_id)->toBe($player->id)
        ->and($final->status)->toBe(MatchStatus::Scheduled);
});
