<?php

use App\Actions\Matches\SaveMatch;
use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Programme;
use App\Models\BadmintonMatch;
use App\Models\User;
use App\Services\Rating\RatingEngine;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * /head-to-head (pages::rankings.head-to-head). Helpers are prefixed with "headToHead"
 * because Pest test-file functions are global.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 18:00:00'));
});

/**
 * A completed friendly; side A's points first.
 *
 * @param  list<User>  $sideA
 * @param  list<User>  $sideB
 * @param  list<array{0: int, 1: int}>  $games
 */
function headToHeadMatch(array $sideA, array $sideB, array $games, string $playedAt, MatchStatus $status = MatchStatus::Completed): BadmintonMatch
{
    return SaveMatch::handle([
        'discipline' => count($sideA) > 1 ? Discipline::Doubles : Discipline::Singles,
        'side_a' => array_map(fn (User $user): int => $user->id, $sideA),
        'side_b' => array_map(fn (User $user): int => $user->id, $sideB),
        'status' => $status,
        'played_at' => $playedAt,
        'games' => $status === MatchStatus::Completed ? array_map(fn (array $game): array => ['a' => $game[0], 'b' => $game[1]], $games) : [],
    ]);
}

/**
 * Lotte and Sam: three singles meetings (Lotte 2–1), one doubles meeting on opposite sides
 * (Sam's pair wins), one doubles match as partners and one scheduled singles match (neither
 * counts), plus matches with other people.
 *
 * @return array{players: array<string, User>, expected: array<string, int>, last: BadmintonMatch}
 */
function headToHeadWorld(): array
{
    $p = [
        'lotte' => User::factory()->create(['name' => 'Lotte Jansen', 'programme' => Programme::Games]),
        'sam' => User::factory()->create(['name' => 'Sam de Vries', 'programme' => Programme::MediaCreativeBusiness]),
        'wei' => User::factory()->create(['name' => 'Wei Chen', 'programme' => Programme::DataScienceAi]),
        'priya' => User::factory()->create(['name' => 'Priya Raman', 'programme' => Programme::Hotel]),
        'newbie' => User::factory()->create(['name' => 'Tom Peeters', 'programme' => Programme::Tourism]),
    ];

    $last = RatingEngine::deferred(function () use ($p): BadmintonMatch {
        headToHeadMatch([$p['lotte']], [$p['sam']], [[21, 17], [21, 19]], '2026-10-01 20:00');
        headToHeadMatch([$p['sam']], [$p['lotte']], [[21, 18], [19, 21], [21, 15]], '2026-10-08 20:00');
        headToHeadMatch([$p['lotte'], $p['wei']], [$p['sam'], $p['priya']], [[15, 21], [18, 21]], '2026-10-15 20:00');
        headToHeadMatch([$p['lotte'], $p['sam']], [$p['wei'], $p['priya']], [[21, 10], [21, 12]], '2026-10-20 20:00');
        headToHeadMatch([$p['wei']], [$p['priya']], [[21, 5], [21, 6]], '2026-10-21 20:00');
        $last = headToHeadMatch([$p['lotte']], [$p['sam']], [[23, 21], [21, 16]], '2026-10-29 20:00');
        headToHeadMatch([$p['lotte']], [$p['sam']], [], '2026-11-10 20:00', MatchStatus::Scheduled);

        return $last;
    });

    return [
        'players' => array_map(fn (User $user): User => $user->refresh(), $p),
        // From Lotte's side: singles 2–1 (games 5–2), doubles 0–1 (games 0–2).
        'expected' => [
            'winsA' => 2, 'winsB' => 2,
            'gamesA' => 2 + 1 + 0 + 2, 'gamesB' => 0 + 2 + 2 + 0,
            'pointsA' => (21 + 21) + (18 + 21 + 15) + (15 + 18) + (23 + 21),
            'pointsB' => (17 + 19) + (21 + 19 + 21) + (21 + 21) + (21 + 16),
            'singlesWinsA' => 2, 'singlesWinsB' => 1,
        ],
        'last' => $last,
    ];
}

test('guests, members and admins can open the head-to-head page', function () {
    ['players' => $p] = headToHeadWorld();

    $this->get(route('head-to-head'))
        ->assertOk()
        ->assertSee('Head-to-head')
        ->assertSee('The most-played match-ups')
        ->assertSee('Lotte Jansen');

    $this->get(route('head-to-head', ['a' => $p['lotte']->id, 'b' => $p['sam']->id]))
        ->assertOk()
        ->assertSee('Every meeting')
        ->assertSee('If they played today');

    $this->actingAs(User::factory()->create())
        ->get(route('head-to-head', ['a' => $p['lotte']->id, 'b' => $p['sam']->id, 'discipline' => 'singles']))
        ->assertOk();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('head-to-head', ['a' => $p['wei']->id, 'b' => $p['priya']->id, 'discipline' => 'doubles']))
        ->assertOk();
});

test('the record counts every meeting on opposite sides, from player A’s side', function () {
    ['players' => $p, 'expected' => $e, 'last' => $last] = headToHeadWorld();

    $component = Livewire::withQueryParams(['a' => $p['lotte']->id, 'b' => $p['sam']->id])
        ->test('pages::rankings.head-to-head');

    $record = $component->instance()->record;

    expect($record->meetings())->toBe(4)
        ->and($record->winsA)->toBe($e['winsA'])
        ->and($record->winsB)->toBe($e['winsB'])
        ->and($record->gamesA)->toBe($e['gamesA'])
        ->and($record->gamesB)->toBe($e['gamesB'])
        ->and($record->pointsA)->toBe($e['pointsA'])
        ->and($record->pointsB)->toBe($e['pointsB'])
        ->and($record->lastMeeting?->id)->toBe($last->id);

    $component
        ->assertSee('All square after 4 meetings')
        ->assertSee('Lotte '.$e['gamesA'].', Sam '.$e['gamesB'])
        ->assertSee('Lotte '.$e['pointsA'].', Sam '.$e['pointsB'])
        ->assertSee('Thursday 29 October')
        ->assertSee('4 matches, newest first');
});

test('the discipline filter limits the meetings', function () {
    ['players' => $p, 'expected' => $e] = headToHeadWorld();

    $component = Livewire::withQueryParams(['a' => $p['lotte']->id, 'b' => $p['sam']->id, 'discipline' => 'singles'])
        ->test('pages::rankings.head-to-head')
        ->assertSee('Lotte leads after 3 meetings');

    expect($component->instance()->record->winsA)->toBe($e['singlesWinsA'])
        ->and($component->instance()->record->winsB)->toBe($e['singlesWinsB']);

    $component->set('discipline', 'doubles')
        ->assertSee('Sam leads after 1 meeting');

    expect($component->instance()->record->meetings())->toBe(1);

    // Junk in the URL is treated as "all".
    $all = Livewire::withQueryParams(['a' => $p['lotte']->id, 'b' => $p['sam']->id, 'discipline' => 'tennis'])
        ->test('pages::rankings.head-to-head')
        ->assertOk();

    expect($all->instance()->record->meetings())->toBe(4);
});

test('swapping the players flips the record', function () {
    ['players' => $p] = headToHeadWorld();

    $component = Livewire::withQueryParams(['a' => $p['lotte']->id, 'b' => $p['sam']->id, 'discipline' => 'singles'])
        ->test('pages::rankings.head-to-head')
        ->call('swap')
        ->assertSet('a', (string) $p['sam']->id)
        ->assertSet('b', (string) $p['lotte']->id);

    $record = $component->instance()->record;

    expect($record->winsA)->toBe(1)->and($record->winsB)->toBe(2);
});

test('the same player twice is refused without a record or prediction', function () {
    ['players' => $p] = headToHeadWorld();

    $component = Livewire::withQueryParams(['a' => $p['lotte']->id, 'b' => $p['lotte']->id])
        ->test('pages::rankings.head-to-head')
        ->assertOk()
        ->assertSee('That is the same player twice')
        ->assertDontSee('If they played today')
        ->assertDontSee('Every meeting');

    expect($component->instance()->ready)->toBeFalse()
        ->and($component->instance()->record)->toBeNull()
        ->and($component->instance()->prediction)->toBeNull();

    // Picking the same player through the select is caught the same way.
    Livewire::withQueryParams(['a' => $p['lotte']->id, 'b' => $p['sam']->id])
        ->test('pages::rankings.head-to-head')
        ->set('b', (string) $p['lotte']->id)
        ->assertSee('That is the same player twice')
        ->assertDontSee('If they played today');
});

test('players who never met still get a prediction and a predictor link', function () {
    ['players' => $p] = headToHeadWorld();

    $url = route('predictor', ['a1' => $p['sam']->id, 'b1' => $p['wei']->id, 'd' => 'singles']);

    Livewire::withQueryParams(['a' => $p['sam']->id, 'b' => $p['wei']->id, 'discipline' => 'singles'])
        ->test('pages::rankings.head-to-head')
        ->assertSee('No singles meetings yet.')
        ->assertSee('If they played today')
        ->assertSee('Open in predictor')
        ->assertSeeHtml(e($url));

    Livewire::withQueryParams(['a' => $p['newbie']->id, 'b' => $p['wei']->id])
        ->test('pages::rankings.head-to-head')
        ->assertSee('Tom and Wei have never met')
        ->assertSee('If they played today');
});

test('unknown or malformed player ids are handled calmly', function () {
    headToHeadWorld();

    Livewire::withQueryParams(['a' => '999999', 'b' => 'abc'])
        ->test('pages::rankings.head-to-head')
        ->assertOk()
        ->assertSee('We could not find that player')
        ->assertDontSee('If they played today');

    $this->get('/head-to-head?a[]=1&b=%27%3B--')->assertOk();
});

test('one player picked suggests opponents', function () {
    ['players' => $p] = headToHeadWorld();

    Livewire::withQueryParams(['a' => $p['lotte']->id])
        ->test('pages::rankings.head-to-head')
        ->assertSee('Who should Lotte face?')
        ->assertSee('Most frequent opponents')
        ->assertSee('Sam de Vries');

    // A newcomer gets players with a rated history first.
    Livewire::withQueryParams(['b' => $p['newbie']->id])
        ->test('pages::rankings.head-to-head')
        ->assertSee('Similar rating')
        ->assertSee('Lotte Jansen');
});

test('the head-to-head page never shows email addresses', function () {
    ['players' => $p] = headToHeadWorld();

    $response = $this->get(route('head-to-head', ['a' => $p['lotte']->id, 'b' => $p['sam']->id]));

    foreach ($p as $user) {
        $response->assertDontSee($user->email);
    }

    $this->get(route('head-to-head'))->assertDontSee($p['lotte']->email);
});
