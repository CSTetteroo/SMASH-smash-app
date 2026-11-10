<?php

use App\Actions\Matches\SaveMatch;
use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Programme;
use App\Models\BadmintonMatch;
use App\Models\User;
use App\Services\Rating\Leaderboard;
use App\Services\Rating\LeaderboardRow;
use App\Services\Rating\RatingEngine;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * /rankings (pages::rankings.index). Helpers are prefixed with "rankings" because Pest
 * test-file functions are global.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 18:00:00'));
});

function rankingsPlayer(string $name, Programme $programme = Programme::Games, array $attributes = []): User
{
    return User::factory()->create(['name' => $name, 'programme' => $programme, ...$attributes]);
}

/**
 * A completed friendly; side A's points first.
 *
 * @param  list<User>  $sideA
 * @param  list<User>  $sideB
 * @param  list<array{0: int, 1: int}>  $games
 */
function rankingsMatch(array $sideA, array $sideB, array $games, string $playedAt): BadmintonMatch
{
    return SaveMatch::handle([
        'discipline' => count($sideA) > 1 ? Discipline::Doubles : Discipline::Singles,
        'side_a' => array_map(fn (User $user): int => $user->id, $sideA),
        'side_b' => array_map(fn (User $user): int => $user->id, $sideB),
        'status' => MatchStatus::Completed,
        'played_at' => $playedAt,
        'games' => array_map(fn (array $game): array => ['a' => $game[0], 'b' => $game[1]], $games),
    ]);
}

/**
 * Four singles players with a clear order (Wei > Lotte > Sam > Priya) and one doubles match
 * between four other players.
 *
 * @return array<string, User>
 */
function rankingsWorld(): array
{
    $p = [
        'wei' => rankingsPlayer('Wei Chen', Programme::DataScienceAi),
        'lotte' => rankingsPlayer('Lotte Jansen', Programme::Games),
        'sam' => rankingsPlayer('Sam de Vries', Programme::Games),
        'priya' => rankingsPlayer('Priya Raman', Programme::Hotel),
        'noor' => rankingsPlayer('Noor van Dijk', Programme::LeisureEvents),
        'daan' => rankingsPlayer('Daan Visser', Programme::Logistics),
        'mei' => rankingsPlayer('Mei Ling Tan', Programme::Tourism),
        'erik' => rankingsPlayer('Erik Lindqvist', Programme::Tourism),
    ];

    RatingEngine::deferred(function () use ($p): void {
        $win = [[21, 15], [21, 17]];
        rankingsMatch([$p['wei']], [$p['lotte']], $win, '2026-10-20 20:00');
        rankingsMatch([$p['wei']], [$p['sam']], $win, '2026-10-21 20:00');
        rankingsMatch([$p['wei']], [$p['priya']], $win, '2026-10-22 20:00');
        rankingsMatch([$p['lotte']], [$p['sam']], $win, '2026-10-23 20:00');
        rankingsMatch([$p['lotte']], [$p['priya']], $win, '2026-10-24 20:00');
        rankingsMatch([$p['sam']], [$p['priya']], $win, '2026-10-25 20:00');
        rankingsMatch([$p['noor'], $p['daan']], [$p['mei'], $p['erik']], $win, '2026-10-26 20:00');
    });

    return array_map(fn (User $user): User => $user->refresh(), $p);
}

/**
 * Markers of the rows in the full table (the podium does not use them).
 *
 * @param  list<User>  $users
 * @return list<string>
 */
function rankingsRowIds(array $users): array
{
    return array_map(fn (User $user): string => 'id="player-'.$user->id.'"', $users);
}

test('guests, members and admins can open the rankings', function () {
    $p = rankingsWorld();

    $this->get(route('rankings.index'))
        ->assertOk()
        ->assertSee('Rankings')
        ->assertSee('Wei Chen')
        ->assertSee('Biggest movers')
        ->assertSee('Hottest streaks')
        ->assertSee('Most active')
        ->assertSee('Compare two players');

    $this->actingAs(User::factory()->create())->get(route('rankings.index'))->assertOk()->assertSee('Wei Chen');
    $this->actingAs(User::factory()->admin()->create())->get(route('rankings.index', ['discipline' => 'doubles']))->assertOk()->assertSee('Noor van Dijk');
});

test('the rankings never show player email addresses', function () {
    $p = rankingsWorld();

    $response = $this->get(route('rankings.index'));

    foreach ($p as $user) {
        $response->assertDontSee($user->email);
    }
});

test('the table follows the leaderboard order with real positions', function () {
    $p = rankingsWorld();

    $expected = Leaderboard::for(Discipline::Singles)->map(fn (LeaderboardRow $row): int => $row->user->id)->all();

    expect($expected)->toBe([$p['wei']->id, $p['lotte']->id, $p['sam']->id, $p['priya']->id]);

    Livewire::test('pages::rankings.index')
        ->assertSeeHtmlInOrder(rankingsRowIds([$p['wei'], $p['lotte'], $p['sam'], $p['priya']]))
        ->assertDontSeeHtml('id="player-'.$p['noor']->id.'"')
        ->assertSeeHtml('data-rankings-podium');
});

test('the discipline tabs switch to the doubles ranking', function () {
    $p = rankingsWorld();

    Livewire::test('pages::rankings.index')
        ->set('discipline', 'doubles')
        ->assertSet('discipline', 'doubles')
        ->assertSee('Doubles ranking')
        ->assertSeeHtmlInOrder(rankingsRowIds([$p['daan'], $p['noor']]))
        ->assertDontSeeHtml('id="player-'.$p['wei']->id.'"');

    // The URL value is read on load, and junk falls back to singles.
    Livewire::withQueryParams(['discipline' => 'doubles'])->test('pages::rankings.index')->assertSee('Doubles ranking');
    Livewire::withQueryParams(['discipline' => 'tennis', 'min' => '999', 'programme' => 'nope', 'page' => '42'])
        ->test('pages::rankings.index')
        ->assertOk()
        ->assertSee('Singles ranking')
        ->assertSeeHtml('id="player-'.$p['wei']->id.'"');
});

test('search narrows the table but keeps the real positions', function () {
    $p = rankingsWorld();

    $component = Livewire::test('pages::rankings.index')
        ->set('search', 'sam de')
        ->assertSeeHtml('id="player-'.$p['sam']->id.'"')
        ->assertDontSeeHtml('id="player-'.$p['wei']->id.'"')
        ->assertDontSeeHtml('id="player-'.$p['lotte']->id.'"');

    $rows = $component->instance()->rows;

    expect($rows->total())->toBe(1)
        ->and($rows->first()->position)->toBe(3);

    $component->set('search', 'nobody-by-this-name')
        ->assertSee('No players match')
        ->assertSee('Clear filters')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSeeHtml('id="player-'.$p['wei']->id.'"');
});

test('the programme filter only lists players from that programme', function () {
    $p = rankingsWorld();

    Livewire::test('pages::rankings.index')
        ->set('programme', Programme::Games->value)
        ->assertSeeHtmlInOrder(rankingsRowIds([$p['lotte'], $p['sam']]))
        ->assertDontSeeHtml('id="player-'.$p['wei']->id.'"')
        ->assertDontSeeHtml('id="player-'.$p['priya']->id.'"');
});

test('minimum matches and the provisional toggle shrink the pool', function () {
    $p = rankingsWorld();
    $veteran = rankingsPlayer('Emre Yilmaz', Programme::Logistics);
    $sparring = rankingsPlayer('Kacper Nowak', Programme::Games);

    RatingEngine::deferred(function () use ($veteran, $sparring): void {
        for ($i = 0; $i < 10; $i++) {
            rankingsMatch([$veteran], [$sparring], $i % 2 === 0 ? [[21, 10], [21, 12]] : [[15, 21], [21, 19], [21, 18]], '2026-10-0'.($i % 9 + 1).' 1'.$i.':00');
        }
    });

    // Everyone with 1+ singles match.
    $component = Livewire::test('pages::rankings.index')
        ->assertSeeHtml('id="player-'.$p['priya']->id.'"');

    // Wei, Lotte, Sam and Priya have 3 matches each; Emre and Kacper have 10.
    $component->set('min', '5')
        ->assertSeeHtml('id="player-'.$veteran->id.'"')
        ->assertSeeHtml('id="player-'.$sparring->id.'"')
        ->assertDontSeeHtml('id="player-'.$p['wei']->id.'"');

    // Hiding provisional players (fewer than 10 rated matches) keeps only the two veterans.
    $component->set('min', '1')
        ->set('provisional', false)
        ->assertSeeHtml('id="player-'.$veteran->id.'"')
        ->assertDontSeeHtml('id="player-'.$p['priya']->id.'"');

    expect($component->instance()->pool->count())->toBe(2);
});

test('nobody qualifying shows a friendly empty state', function () {
    rankingsWorld();

    Livewire::test('pages::rankings.index')
        ->set('provisional', false)
        ->assertSee('Nobody qualifies yet')
        ->assertSee('Show everyone');
});

test('an empty club shows the empty ranking state', function () {
    User::factory()->count(3)->create();

    $this->get(route('rankings.index'))
        ->assertOk()
        ->assertSee('No one is ranked in singles yet');

    $this->actingAs(User::factory()->create())
        ->get(route('rankings.index'))
        ->assertOk()
        ->assertSee('Log a match');
});

test('the signed-in player sees their position and can jump to their row', function () {
    $p = rankingsWorld();
    $others = User::factory()->count(26)->create();

    // 26 more players beat Priya once each, so she drops to the second page.
    RatingEngine::deferred(function () use ($others, $p): void {
        foreach ($others as $i => $other) {
            rankingsMatch([$other], [$p['priya']], [[21, 10], [21, 12]], '2026-10-27 '.str_pad((string) ($i % 24), 2, '0', STR_PAD_LEFT).':00');
        }
    });

    $priya = $p['priya']->refresh();
    $position = Leaderboard::for(Discipline::Singles)->first(fn (LeaderboardRow $row): bool => $row->user->is($priya))->position;

    expect($position)->toBeGreaterThan(25);

    $this->actingAs($priya);

    Livewire::test('pages::rankings.index')
        ->assertSee('You are')
        ->assertDontSeeHtml('id="player-'.$priya->id.'"')
        ->call('findMe')
        ->assertSeeHtml('id="player-'.$priya->id.'"')
        ->assertSeeHtml('data-current');
});

test('compare opens the head-to-head for two different players', function () {
    $p = rankingsWorld();

    Livewire::test('pages::rankings.index')
        ->set('compareA', (string) $p['wei']->id)
        ->set('compareB', (string) $p['lotte']->id)
        ->call('compare')
        ->assertHasNoErrors()
        ->assertRedirect(route('head-to-head', ['a' => $p['wei']->id, 'b' => $p['lotte']->id]));
});

test('compare rejects the same player twice and unknown players', function () {
    $p = rankingsWorld();

    Livewire::test('pages::rankings.index')
        ->set('compareA', (string) $p['wei']->id)
        ->set('compareB', (string) $p['wei']->id)
        ->call('compare')
        ->assertHasErrors(['compareB' => 'different'])
        ->assertNoRedirect();

    Livewire::test('pages::rankings.index')
        ->set('compareA', '999999')
        ->set('compareB', 'drop table')
        ->call('compare')
        ->assertHasErrors(['compareA' => 'exists', 'compareB'])
        ->assertNoRedirect();

    Livewire::test('pages::rankings.index')
        ->call('compare')
        ->assertHasErrors(['compareA' => 'required', 'compareB' => 'required']);
});

test('the compare picker starts with the signed-in player', function () {
    $member = User::factory()->create();

    $this->actingAs($member);

    Livewire::test('pages::rankings.index')->assertSet('compareA', (string) $member->id);
    Livewire::test('pages::rankings.index')->assertSet('compareB', '');
});
