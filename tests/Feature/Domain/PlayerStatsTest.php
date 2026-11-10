<?php

use App\Enums\Discipline;
use App\Models\User;
use App\Services\Stats\PlayerStats;
use Carbon\CarbonImmutable;
use Tests\Feature\Domain\Support\Play;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-03 12:00:00');
    Play::reset('2026-10-10 18:00:00');

    $this->ann = User::factory()->create(['name' => 'Ann Admiraal']);
    $this->ben = User::factory()->create(['name' => 'Ben Bos']);
    $this->cas = User::factory()->create(['name' => 'Cas Claes']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * Ann: beats Ben twice (once in three), loses three times to Cas (once in three), then beats Ben twice.
 */
function playerStatsHistory(User $ann, User $ben, User $cas): void
{
    Play::match($ann, $ben, [[21, 15], [21, 15]]);
    Play::match($ann, $ben, [[21, 19], [18, 21], [21, 17]]);
    Play::match($cas, $ann, [[21, 10], [21, 10]]);
    Play::match($cas, $ann, [[21, 19], [19, 21], [21, 15]]);
    Play::match($cas, $ann, [[21, 15], [21, 15]]);
    Play::match($ann, $ben, [[21, 15], [21, 15]]);
    Play::match($ann, $ben, [[21, 15], [21, 15]]);
}

test('a player summary counts streaks, games, points and deciders', function () {
    playerStatsHistory($this->ann, $this->ben, $this->cas);

    $stats = PlayerStats::for($this->ann->refresh(), Discipline::Singles);

    expect($stats->played)->toBe(7)
        ->and($stats->wins)->toBe(4)
        ->and($stats->losses)->toBe(3)
        ->and($stats->winRate)->toEqualWithDelta(4 / 7, 1e-12)
        ->and($stats->currentStreak)->toBe(2)
        ->and($stats->longestWinStreak)->toBe(2)
        ->and(array_column($stats->form, 'result'))->toBe(['W', 'W', 'L', 'L', 'L'])
        ->and($stats->gamesWon)->toBe(9)
        ->and($stats->gamesLost)->toBe(7)
        ->and($stats->pointsWon)->toBe(42 + 60 + 20 + 55 + 30 + 42 + 42)
        ->and($stats->pointsLost)->toBe(30 + 57 + 42 + 61 + 42 + 30 + 30)
        ->and($stats->decidersPlayed)->toBe(2)
        ->and($stats->decidersWon)->toBe(1)
        ->and($stats->rating)->toBe($this->ann->rating(Discipline::Singles))
        ->and($stats->rankedPlayers)->toBe(3)
        ->and($stats->rank)->toBeInt()
        ->and($stats->provisional)->toBeTrue()
        ->and($stats->partners)->toBe([]);

    expect(PlayerStats::for($this->cas->refresh(), Discipline::Singles)->currentStreak)->toBe(3)
        ->and(PlayerStats::for($this->ben->refresh(), Discipline::Singles)->currentStreak)->toBe(-4);
});

test('the rating history starts at the initial rating and follows every match', function () {
    playerStatsHistory($this->ann, $this->ben, $this->cas);

    $history = PlayerStats::for($this->ann->refresh(), Discipline::Singles)->ratingHistory;

    expect($history)->toHaveCount(8)
        ->and($history[0]['rating'])->toBe(1500.0)
        ->and($history[0]['change'])->toBe(0.0)
        ->and($history[0]['match_id'])->toBe($history[1]['match_id'])
        ->and($history[0]['date'])->toBeInstanceOf(CarbonImmutable::class)
        ->and(end($history)['rating'])->toEqualWithDelta($this->ann->singles_rating, 1e-9)
        ->and(array_sum(array_column($history, 'change')))->toEqualWithDelta($this->ann->singles_rating - 1500, 1e-9);

    for ($i = 1; $i < count($history); $i++) {
        expect($history[$i]['date']->greaterThanOrEqualTo($history[$i - 1]['date']))->toBeTrue()
            ->and($history[$i]['rating'])->toEqualWithDelta($history[$i - 1]['rating'] + $history[$i]['change'], 1e-9);
    }
});

test('nemesis, favourite opponent and best win', function () {
    playerStatsHistory($this->ann, $this->ben, $this->cas);

    $stats = PlayerStats::for($this->ann->refresh(), Discipline::Singles);

    expect($stats->nemesis['user']->is($this->cas))->toBeTrue()
        ->and($stats->nemesis['losses'])->toBe(3)
        ->and($stats->nemesis['played'])->toBe(3)
        ->and($stats->favouriteOpponent['user']->is($this->ben))->toBeTrue()
        ->and($stats->favouriteOpponent['wins'])->toBe(4)
        ->and($stats->bestWin['opponent'])->toBe('Ben Bos')
        ->and($stats->bestWin['opponent_rating'])->toBe(1500);

    // One loss is not enough to be anybody's nemesis.
    $fresh = User::factory()->create();
    Play::win($this->ben, $fresh);

    expect(PlayerStats::for($fresh->refresh(), Discipline::Singles)->nemesis)->toBeNull();
});

test('doubles partners are listed by matches played together', function () {
    $dee = User::factory()->create(['name' => 'Dee Dijkstra']);

    Play::match([$this->ann, $this->ben], [$this->cas, $dee], [[21, 10], [21, 10]]);
    Play::match([$this->ann, $this->ben], [$this->cas, $dee], [[10, 21], [10, 21]]);
    Play::match([$this->ann, $this->cas], [$this->ben, $dee], [[21, 10], [21, 10]]);

    $stats = PlayerStats::for($this->ann->refresh(), Discipline::Doubles);

    expect(array_map(fn (array $partner): string => $partner['user']->name, $stats->partners))->toBe(['Ben Bos', 'Cas Claes'])
        ->and($stats->partners[0]['played'])->toBe(2)
        ->and($stats->partners[0]['wins'])->toBe(1)
        ->and($stats->partners[1]['wins'])->toBe(1)
        ->and($stats->bestWin['opponent'])->toContain(' & ')
        ->and(PlayerStats::for($this->ann, Discipline::Singles)->played)->toBe(0);
});

test('the 30-day change ignores older matches', function () {
    Play::reset('2026-09-01 19:00:00');
    Play::win($this->ann, $this->ben);
    $then = $this->ann->refresh()->singles_rating;

    Play::reset('2026-10-30 19:00:00');
    Play::win($this->cas, $this->ann);

    $stats = PlayerStats::for($this->ann->refresh(), Discipline::Singles);

    expect($stats->change30d)->toEqualWithDelta($this->ann->singles_rating - $then, 1e-9)
        ->and($stats->change30d)->toBeLessThan(0.0);
});

test('a newcomer has an empty but complete summary', function () {
    $stats = PlayerStats::for($this->ann, Discipline::Doubles);

    expect([$stats->rank, $stats->rankedPlayers, $stats->rating, $stats->peak, $stats->played, $stats->winRate])->toBe([null, 0, 1500, 1500, 0, null])
        ->and([$stats->currentStreak, $stats->longestWinStreak, $stats->change30d])->toBe([0, 0, 0.0])
        ->and([$stats->form, $stats->ratingHistory, $stats->partners])->toBe([[], [], []])
        ->and([$stats->bestWin, $stats->nemesis, $stats->favouriteOpponent])->toBe([null, null, null])
        ->and($stats->discipline)->toBe(Discipline::Doubles)
        ->and($stats->provisional)->toBeTrue();
});

test('unranked players have no rank', function () {
    $inactive = User::factory()->inactive()->create();
    Play::win($inactive, $this->ann);

    expect(PlayerStats::for($inactive->refresh(), Discipline::Singles)->rank)->toBeNull()
        ->and(PlayerStats::for($this->ben, Discipline::Singles)->rank)->toBeNull()
        ->and(PlayerStats::for($this->ann->refresh(), Discipline::Singles)->rank)->toBe(1);
});

test('head-to-head counts matches on opposite sides only', function () {
    $dee = User::factory()->create();

    Play::match($this->ann, $this->ben, [[21, 10], [21, 10]]);
    Play::match($this->ben, $this->ann, [[21, 19], [21, 19]]);
    Play::match([$this->ann, $this->cas], [$this->ben, $dee], [[21, 15], [21, 15]]);
    Play::match([$this->ann, $this->ben], [$this->cas, $dee], [[21, 15], [21, 15]]);
    $last = Play::match($this->ann, $this->ben, [[21, 5], [21, 5]]);

    $all = PlayerStats::headToHead($this->ann, $this->ben);
    $singles = PlayerStats::headToHead($this->ann, $this->ben, Discipline::Singles);

    expect($all->meetings())->toBe(4)
        ->and([$all->winsA, $all->winsB])->toBe([3, 1])
        ->and([$singles->winsA, $singles->winsB])->toBe([2, 1])
        ->and([$singles->gamesA, $singles->gamesB])->toBe([4, 2])
        ->and([$singles->pointsA, $singles->pointsB])->toBe([42 + 38 + 42, 20 + 42 + 10])
        ->and($singles->lastMeeting?->is($last))->toBeTrue()
        ->and($singles->matches->first()?->is($last))->toBeTrue()
        ->and(PlayerStats::headToHead($this->ann, $this->ann)->meetings())->toBe(0);

    $mirror = PlayerStats::headToHead($this->ben, $this->ann, Discipline::Singles);

    expect([$mirror->winsA, $mirror->winsB, $mirror->pointsA])->toBe([1, 2, $singles->pointsB]);
});
