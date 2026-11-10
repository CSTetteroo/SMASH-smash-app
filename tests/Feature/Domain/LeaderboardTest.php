<?php

use App\Enums\Discipline;
use App\Enums\Programme;
use App\Models\User;
use App\Services\Rating\Leaderboard;
use App\Services\Rating\LeaderboardRow;
use Carbon\CarbonImmutable;
use Tests\Feature\Domain\Support\Play;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-03 12:00:00');
    Play::reset('2026-10-20 18:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @return list<string>
 */
function leaderboardNames(iterable $rows): array
{
    $names = [];

    foreach ($rows as $row) {
        /** @var LeaderboardRow $row */
        $names[] = $row->user->name;
    }

    return $names;
}

test('active players with rated matches are ranked by rating with positions 1..n', function () {
    $ann = User::factory()->create(['name' => 'Ann Admiraal']);
    $ben = User::factory()->create(['name' => 'Ben Bos']);
    $cas = User::factory()->create(['name' => 'Cas Claes']);
    $dee = User::factory()->inactive()->create(['name' => 'Dee Dijkstra']);
    User::factory()->create(['name' => 'Eva Eerens']);

    Play::win($ann, $ben);
    Play::win($ann, $cas);
    Play::win($ben, $cas);
    Play::win($dee, $cas);

    $rows = Leaderboard::for(Discipline::Singles);

    expect(leaderboardNames($rows))->toBe(['Ann Admiraal', 'Ben Bos', 'Cas Claes'])
        ->and($rows->pluck('position')->all())->toBe([1, 2, 3])
        ->and($rows->first()?->rating)->toBe($ann->refresh()->rating(Discipline::Singles))
        ->and($rows->first()?->played)->toBe(2)
        ->and($rows->first()?->wins)->toBe(2)
        ->and($rows->first()?->losses)->toBe(0)
        ->and($rows->first()?->winRate)->toBe(1.0)
        ->and($rows->first()?->provisional)->toBeTrue()
        ->and($rows->last()?->form)->toBe(['L', 'L', 'L'])
        ->and(Leaderboard::for(Discipline::Doubles))->toBeEmpty();
});

test('equal ratings are ordered by name and positions are never shared', function () {
    User::factory()->withRating(Discipline::Singles, 1600, 5)->create(['name' => 'Zed Zwart']);
    User::factory()->withRating(Discipline::Singles, 1600, 5)->create(['name' => 'Abe Aarts']);

    $rows = Leaderboard::for(Discipline::Singles);

    expect(leaderboardNames($rows))->toBe(['Abe Aarts', 'Zed Zwart'])
        ->and($rows->pluck('position')->all())->toBe([1, 2])
        ->and($rows->first()?->change30d)->toBe(0.0)
        ->and($rows->first()?->movement)->toBeNull()
        ->and($rows->first()?->form)->toBe([]);
});

test('form shows the last five results, most recent first', function () {
    [$ann, $ben] = User::factory()->count(2)->create();

    foreach (['W', 'W', 'L', 'W', 'L', 'L', 'W'] as $result) {
        $result === 'W' ? Play::win($ann, $ben) : Play::win($ben, $ann);
    }

    $row = Leaderboard::for(Discipline::Singles)->firstWhere('user.id', $ann->id);

    expect($row?->form)->toBe(['W', 'L', 'L', 'W', 'L'])
        ->and($row?->played)->toBe(7);
});

test('the 30-day change and the movement compare with the table of 30 days ago', function () {
    $ann = User::factory()->create(['name' => 'Ann Admiraal']);
    $ben = User::factory()->create(['name' => 'Ben Bos']);
    $cas = User::factory()->create(['name' => 'Cas Claes']);

    // September: Ann ahead of Ben; Cas has not played yet.
    Play::reset('2026-09-10 19:00:00');
    Play::win($ann, $ben);
    Play::win($ann, $ben);
    $annThen = $ann->refresh()->singles_rating;

    // Last fortnight: Ben turns it around, Cas debuts.
    Play::reset('2026-10-25 19:00:00');
    Play::win($ben, $ann);
    Play::win($ben, $ann);
    Play::win($ben, $ann);
    Play::win($cas, $ann);

    $rows = Leaderboard::for(Discipline::Singles)->keyBy('user.id');

    expect($rows[$ben->id]->position)->toBe(1)
        ->and($rows[$ben->id]->movement)->toBe(1)
        ->and($rows[$ann->id]->movement)->toBe(1 - $rows[$ann->id]->position)
        ->and($rows[$ann->id]->movement)->toBeLessThan(0)
        ->and($rows[$cas->id]->movement)->toBeNull()
        ->and($rows[$ann->id]->change30d)->toEqualWithDelta($ann->refresh()->singles_rating - $annThen, 1e-9)
        ->and($rows[$cas->id]->change30d)->toEqualWithDelta($cas->refresh()->singles_rating - 1500, 1e-9);
});

test('a minimum number of matches narrows the ranking', function () {
    [$ann, $ben, $cas] = User::factory()->count(3)->create();
    Play::win($ann, $ben);
    Play::win($ann, $ben);
    Play::win($cas, $ann);

    expect(Leaderboard::for(Discipline::Singles, minMatches: 2)->pluck('user.id')->sort()->values()->all())
        ->toBe(collect([$ann->id, $ben->id])->sort()->values()->all())
        ->and(Leaderboard::for(Discipline::Singles, minMatches: 2)->pluck('position')->all())->toBe([1, 2]);
});

test('search and programme filters keep the real positions', function () {
    $ann = User::factory()->programme(Programme::Games)->create(['name' => 'Ann de Vries']);
    $ben = User::factory()->programme(Programme::Hotel)->create(['name' => 'Ben Bos']);
    $cas = User::factory()->programme(Programme::Games)->create(['name' => 'Cas Claes']);
    Play::win($ann, $ben);
    Play::win($ben, $cas);
    Play::win($ann, $cas);

    $search = Leaderboard::for(Discipline::Singles, search: 'BOS');
    $games = Leaderboard::for(Discipline::Singles, programme: Programme::Games);

    expect(leaderboardNames($search))->toBe(['Ben Bos'])
        ->and($search->first()?->position)->toBe(2)
        ->and($search->keys()->all())->toBe([0])
        ->and(leaderboardNames($games))->toBe(['Ann de Vries', 'Cas Claes'])
        ->and($games->pluck('position')->all())->toBe([1, 3])
        ->and(Leaderboard::for(Discipline::Singles, search: '  de vr '))->toHaveCount(1);
});

test('established players are no longer provisional and the peak is rounded', function () {
    [$ann, $ben] = User::factory()->count(2)->create();

    foreach (range(1, 10) as $i) {
        Play::win($ann, $ben);
    }

    $row = Leaderboard::for(Discipline::Singles)->first();

    expect($row?->provisional)->toBeFalse()
        ->and($row?->peak)->toBe((int) round($ann->refresh()->singles_peak))
        ->and($row?->form)->toHaveCount(5);
});
