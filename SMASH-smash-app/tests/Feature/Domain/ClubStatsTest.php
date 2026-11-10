<?php

use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\ClubNight;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Stats\ClubStats;
use Tests\Feature\Domain\Support\Play;

beforeEach(fn () => Play::reset());

/**
 * A completed, rated singles match won by side A, with the given pre-match ratings.
 */
function clubStatsUpset(float $winnerBefore, float $loserBefore): BadmintonMatch
{
    [$winner, $loser] = User::factory()->count(2)->create();
    $match = BadmintonMatch::factory()->completed(Side::A)->withPlayers($winner, $loser)->create();
    $match->participants()->where('user_id', $winner->id)->update(['rating_before' => $winnerBefore]);
    $match->participants()->where('user_id', $loser->id)->update(['rating_before' => $loserBefore]);

    return $match;
}

test('the landing page numbers', function () {
    [$ann, $ben] = User::factory()->count(2)->create();
    User::factory()->inactive()->create();
    Play::match($ann, $ben, [[21, 19], [19, 21], [30, 29]]);
    Play::match($ann, $ben, [[21, 3], [21, 4]]);
    BadmintonMatch::factory()->withPlayers($ann, $ben)->create();
    Tournament::factory()->open()->create();
    Tournament::factory()->draft()->create();
    ClubNight::factory()->past()->create();
    ClubNight::factory()->upcoming()->create();

    $stats = ClubStats::summary();

    expect($stats['members'])->toBe(2)
        ->and($stats['matches'])->toBe(2)
        ->and($stats['games'])->toBe(5)
        ->and($stats['points'])->toBe(21 + 19 + 19 + 21 + 30 + 29 + 21 + 3 + 21 + 4)
        ->and($stats['tournaments'])->toBe(1)
        ->and($stats['club_nights'])->toBe(1)
        ->and($stats['longest_match']?->games)->toHaveCount(3)
        ->and($stats['longest_match']?->relationLoaded('participants'))->toBeTrue()
        ->and($stats['biggest_upset'])->toBeNull()
        ->and($stats['upsets'])->toBe(0);
});

test('an empty club has zeros and no highlights', function () {
    expect(ClubStats::summary())->toBe([
        'members' => 0, 'matches' => 0, 'games' => 0, 'points' => 0, 'tournaments' => 0, 'club_nights' => 0,
        'biggest_upset' => null, 'longest_match' => null, 'upsets' => 0,
    ]);
});

test('the biggest upset is the largest rating gap overcome, from the threshold up', function () {
    clubStatsUpset(1500, 1580);
    $medium = clubStatsUpset(1500, 1620);
    $biggest = clubStatsUpset(1400, 1650);
    clubStatsUpset(1700, 1400);

    $stats = ClubStats::summary();

    expect($stats['biggest_upset']?->is($biggest))->toBeTrue()
        ->and($stats['upsets'])->toBe(2)
        ->and(ClubStats::underdogGaps()[$medium->id])->toBe(120.0);

    config(['smash.upset_threshold' => 300]);

    expect(ClubStats::summary()['biggest_upset'])->toBeNull();
});
