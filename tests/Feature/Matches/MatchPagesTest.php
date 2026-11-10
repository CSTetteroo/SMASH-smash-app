<?php

use App\Actions\Matches\SaveMatch;
use App\Enums\Discipline;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\Prediction;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournament\DrawGenerator;
use Livewire\Livewire;

/*
| Results & fixtures list and the match page: who can see what, tabs and filters, and every
| match state (finished, upset, scheduled, live, walkover, tournament).
*/

/**
 * A completed, rated friendly saved through the real action (so ratings are computed).
 *
 * @param  list<User>  $sideA
 * @param  list<User>  $sideB
 * @param  list<array{0: int, 1: int}>  $games
 */
function matchPagesResult(array $sideA, array $sideB, array $games = [[21, 15], [21, 18]], array $extra = []): BadmintonMatch
{
    return SaveMatch::handle([
        'discipline' => count($sideA) === 2 ? 'doubles' : 'singles',
        'side_a' => collect($sideA)->pluck('id')->all(),
        'side_b' => collect($sideB)->pluck('id')->all(),
        'status' => 'completed',
        'played_at' => now()->subDay(),
        'games' => $games,
        ...$extra,
    ]);
}

function matchPagesFixture(User $a, User $b, array $extra = []): BadmintonMatch
{
    return SaveMatch::handle([
        'discipline' => 'singles',
        'side_a' => [$a->id],
        'side_b' => [$b->id],
        'status' => 'scheduled',
        'scheduled_at' => now()->addDays(2)->setTime(20, 30),
        'court' => 2,
        ...$extra,
    ]);
}

test('guests can browse results and open a match page', function () {
    $sam = User::factory()->create(['name' => 'Sam de Vries']);
    $lotte = User::factory()->create(['name' => 'Lotte Jansen']);
    $match = matchPagesResult([$sam], [$lotte]);

    $this->get(route('matches.index'))
        ->assertOk()
        ->assertSee('Results &amp; fixtures', false)
        ->assertSee('Sam de Vries')
        ->assertSee('Log in to add your results')
        ->assertDontSee($sam->email);

    $this->get(route('matches.show', $match))
        ->assertOk()
        ->assertSee('Sam de Vries vs Lotte Jansen')
        ->assertSee('Rating changes')
        ->assertSee('Game by game')
        ->assertDontSee($sam->email)
        ->assertDontSee($lotte->email)
        ->assertDontSee('data-test="edit-button"', false);
});

test('logging and editing need the right account', function () {
    $match = matchPagesResult([User::factory()->create()], [User::factory()->create()]);
    $member = User::factory()->create();

    $this->get(route('matches.create'))->assertRedirect(route('login'));
    $this->get(route('matches.edit', $match))->assertRedirect(route('login'));

    $this->actingAs($member)->get(route('matches.create'))->assertOk()->assertSee('Log a match');
    $this->actingAs($member)->get(route('matches.edit', $match))->assertForbidden();
    $this->actingAs(User::factory()->unverified()->create())->get(route('matches.create'))->assertRedirect(route('verification.notice'));

    $this->actingAs(User::factory()->admin()->create())->get(route('matches.edit', $match))->assertOk()->assertSee('Edit match');
});

test('the index shows log a match for members and unknown matches 404', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('matches.index'))
        ->assertSee('Log a match')
        ->assertSee(route('matches.create'), false);

    $this->get('/matches/999999')->assertNotFound();
});

test('tabs split results, fixtures and live matches, with counts', function () {
    [$a, $b, $c, $d] = User::factory()->count(4)->create();
    $result = matchPagesResult([$a], [$b]);
    $fixture = matchPagesFixture($c, $d);
    $live = SaveMatch::handle([
        'discipline' => 'singles', 'side_a' => [$a->id], 'side_b' => [$d->id], 'status' => 'in_progress', 'games' => [['a' => 11, 'b' => 7]],
    ]);

    $page = Livewire::test('pages::matches.index')
        ->assertSet('tab', 'results')
        ->assertSee(route('matches.show', $result), false)
        ->assertDontSee(route('matches.show', $fixture), false);

    expect($page->instance()->counts)->toBe(['results' => 1, 'upcoming' => 1, 'live' => 1]);

    $page->call('showTab', 'upcoming')
        ->assertSet('tab', 'upcoming')
        ->assertSee(route('matches.show', $fixture), false)
        ->assertDontSee(route('matches.show', $result), false);

    $page->call('showTab', 'live')
        ->assertSee(route('matches.show', $live), false)
        ->assertDontSee(route('matches.show', $fixture), false);

    // Unknown tab from the query string falls back to results.
    Livewire::withQueryParams(['tab' => 'nonsense'])->test('pages::matches.index')->assertSet('tab', 'results');
    $page->call('showTab', 'drafts')->assertSet('tab', 'results');
});

test('filters narrow the list by discipline, type, player and tournament', function () {
    [$a, $b, $c, $d] = User::factory()->count(4)->create();
    $singles = matchPagesResult([$a], [$b]);
    $doubles = matchPagesResult([$a, $c], [$b, $d]);

    $tournament = Tournament::factory()->open()->knockout()->singles()->withEntries(2)->create(['name' => 'Autumn Open']);
    DrawGenerator::generate($tournament);
    $final = $tournament->matches()->firstOrFail();
    SaveMatch::handle(['status' => 'completed', 'games' => [[21, 10], [21, 12]]], $final);

    $page = Livewire::test('pages::matches.index');

    $page->set('discipline', 'doubles')
        ->assertSee(route('matches.show', $doubles), false)
        ->assertDontSee(route('matches.show', $singles), false);

    $page->set('discipline', '')->set('type', 'tournament')
        ->assertSee(route('matches.show', $final), false)
        ->assertDontSee(route('matches.show', $singles), false);

    $page->set('type', 'friendly')
        ->assertSee(route('matches.show', $singles), false)
        ->assertDontSee(route('matches.show', $final), false);

    $page->set('type', '')->set('player', (string) $c->id)
        ->assertSee(route('matches.show', $doubles), false)
        ->assertDontSee(route('matches.show', $singles), false);

    $page->set('player', '')->set('tournament', $tournament->slug)
        ->assertSee(route('matches.show', $final), false)
        ->assertDontSee(route('matches.show', $doubles), false);

    $page->call('clearFilters')
        ->assertSet('tournament', '')
        ->assertSee(route('matches.show', $singles), false)
        ->assertSee(route('matches.show', $final), false);

    // Junk in the query string is ignored.
    Livewire::withQueryParams(['discipline' => 'mixed', 'type' => 'league', 'player' => 'drop table'])
        ->test('pages::matches.index')
        ->assertSet('discipline', '')
        ->assertSet('type', '')
        ->assertSet('player', '');
});

test('matches of draft tournaments stay hidden from members', function () {
    $draft = Tournament::factory()->draft()->knockout()->singles()->withEntries(2)->create();
    $match = BadmintonMatch::factory()->inTournament($draft)->create(['status' => 'scheduled', 'scheduled_at' => now()->addWeek()]);

    $page = Livewire::actingAs(User::factory()->create())->test('pages::matches.index')->call('showTab', 'upcoming');
    $page->assertDontSee(route('matches.show', $match), false);

    Livewire::actingAs(User::factory()->admin()->create())->test('pages::matches.index')->call('showTab', 'upcoming')
        ->assertSee(route('matches.show', $match), false);
});

test('a finished match shows the pre-match chance and calls out an upset', function () {
    $favourite = User::factory()->withRating(Discipline::Singles, 1800)->create(['name' => 'Wei Chen']);
    $underdog = User::factory()->create(['name' => 'Tom Peeters']);

    // Build up the favourite's rating with real wins first.
    foreach (range(1, 6) as $i) {
        matchPagesResult([$favourite], [User::factory()->create()], [[21, 5], [21, 6]]);
    }

    $upset = matchPagesResult([$favourite], [$underdog], [[15, 21], [18, 21]]);

    $this->get(route('matches.show', $upset))
        ->assertOk()
        ->assertSee('Tom Peeters beat Wei Chen 2–0 in straight games.')
        ->assertSee('Before the match')
        ->assertSee('data-test="upset"', false)
        ->assertSee('Match facts');
});

test('an unrated match says it does not count for ELO', function () {
    $match = matchPagesResult([User::factory()->create()], [User::factory()->create()], extra: ['is_rated' => false]);

    $this->get(route('matches.show', $match))
        ->assertOk()
        ->assertSee('Practice match')
        ->assertSee('nobody&#039;s rating moved', false);
});

test('a fixture shows the prediction, the stakes and the pick\'em split', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $fixture = matchPagesFixture($a, $b);
    $fan = User::factory()->create();
    Prediction::factory()->for($fan)->for($fixture, 'match')->picking(Side::A)->create();
    Prediction::factory()->for(User::factory())->for($fixture, 'match')->picking(Side::B)->create();

    $this->actingAs($fan)->get(route('matches.show', $fixture))
        ->assertOk()
        ->assertSee('Who wins?')
        ->assertSee('Likely scores')
        ->assertSee('What&#039;s at stake', false)
        ->assertSee('2 members have made a pick')
        ->assertSee('data-test="my-pick"', false)
        ->assertSee(route('predictions.index').'#match-'.$fixture->id, false);

    // Players in the match cannot pick it.
    $this->actingAs($a)->get(route('matches.show', $fixture))
        ->assertSee('data-test="playing-note"', false)
        ->assertDontSee('data-test="make-pick"', false)
        ->assertSee(route('matches.score', $fixture), false);

    // Outsiders cannot score it.
    $this->actingAs($fan)->get(route('matches.show', $fixture))->assertDontSee(route('matches.score', $fixture), false);
});

test('a live match polls and offers scoring to the players', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $live = SaveMatch::handle([
        'discipline' => 'singles', 'side_a' => [$a->id], 'side_b' => [$b->id], 'status' => 'in_progress',
        'games' => [['a' => 21, 'b' => 17], ['a' => 4, 'b' => 9]],
    ]);

    $this->actingAs($a)->get(route('matches.show', $live))
        ->assertOk()
        ->assertSee('wire:poll.5s', false)
        ->assertSee('Score this match')
        ->assertSee('Win chance right now');
    $this->actingAs(User::factory()->create())->get(route('matches.show', $live))->assertOk()->assertDontSee('Score this match');
    $this->get(route('matches.show', $live))->assertOk()->assertDontSee('Score this match');
});

test('admins get edit and delete on friendlies, a note on tournament matches', function () {
    $admin = User::factory()->admin()->create();
    $friendly = matchPagesResult([User::factory()->create()], [User::factory()->create()]);

    $tournament = Tournament::factory()->open()->knockout()->singles()->withEntries(2)->create(['name' => 'Halloween Smash']);
    DrawGenerator::generate($tournament);
    $final = $tournament->matches()->firstOrFail();

    $this->actingAs($admin)->get(route('matches.show', $friendly))
        ->assertSee('data-test="edit-button"', false)
        ->assertSee('data-test="delete-button"', false);

    $this->actingAs($admin)->get(route('matches.show', $final))
        ->assertSee('data-test="edit-button"', false)
        ->assertDontSee('data-test="delete-button"', false)
        ->assertSee('Managed by the tournament')
        ->assertSee(route('tournaments.show', $tournament), false);

    $this->actingAs(User::factory()->create())->get(route('matches.show', $friendly))
        ->assertDontSee('data-test="edit-button"', false)
        ->assertDontSee('data-test="delete-button"', false);
});

test('walkovers, cancelled matches and open draw slots render', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $walkover = SaveMatch::handle(['discipline' => 'singles', 'side_a' => [$a->id], 'side_b' => [$b->id], 'status' => 'walkover', 'walkover_winner' => 'b']);
    $cancelled = SaveMatch::handle(['discipline' => 'singles', 'side_a' => [$a->id], 'side_b' => [$b->id], 'status' => 'cancelled']);

    $this->get(route('matches.show', $walkover))->assertOk()->assertSee('went through on a walkover');
    $this->get(route('matches.show', $cancelled))->assertOk()->assertSee('called off');

    $tournament = Tournament::factory()->open()->knockout()->singles()->withEntries(4)->create();
    DrawGenerator::generate($tournament);
    $final = $tournament->matches()->where('round', 2)->firstOrFail();

    $this->get(route('matches.show', $final))->assertOk()->assertSee('TBD')->assertSee('data-test="tbd-note"', false);
});
