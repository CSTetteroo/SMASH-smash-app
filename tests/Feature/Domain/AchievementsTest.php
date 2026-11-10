<?php

use App\Actions\Matches\SaveMatch;
use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\ClubNight;
use App\Models\ClubNightRsvp;
use App\Models\Prediction;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Rating\RatingEngine;
use App\Services\Stats\Achievement;
use App\Services\Stats\Achievements;
use App\Services\Tournament\DrawGenerator;
use Carbon\CarbonImmutable;
use Tests\Feature\Domain\Support\Play;

beforeEach(fn () => Play::reset());

/**
 * @return array<string, Achievement>
 */
function achievementsOf(User $user): array
{
    $byKey = [];

    foreach (Achievements::for($user) as $achievement) {
        $byKey[$achievement->key] = $achievement;
    }

    return $byKey;
}

test('every badge is listed, unearned for a newcomer, with progress for the count-based ones', function () {
    $achievements = Achievements::for(User::factory()->create());

    expect(array_map(fn (Achievement $achievement): string => $achievement->key, $achievements))->toBe([
        'first_blood', 'giant_slayer', 'on_fire', 'iron_lungs', 'comeback_kid', 'shutout', 'champion',
        'finalist', 'dynamic_duo', 'veteran', 'club_regular', 'oracle', 'peak_1700',
    ]);

    foreach ($achievements as $achievement) {
        expect($achievement->earned)->toBeFalse()
            ->and($achievement->earnedAt)->toBeNull()
            ->and($achievement->name)->not->toBe('')
            ->and($achievement->description)->not->toBe('')
            ->and(in_array($achievement->tone, ['navy', 'orange', 'green', 'smash', 'sky', 'grey'], true))->toBeTrue();

        expect(file_exists(base_path("vendor/livewire/flux/stubs/resources/views/flux/icon/{$achievement->icon}.blade.php")))->toBeTrue();
    }

    $byKey = collect($achievements)->keyBy('key');

    expect($byKey['veteran']->progress)->toBe(['current' => 0, 'target' => 25])
        ->and($byKey['on_fire']->progress)->toBe(['current' => 0, 'target' => 5])
        ->and($byKey['peak_1700']->progress)->toBe(['current' => 1500, 'target' => 1700])
        ->and($byKey['first_blood']->progress)->toBeNull()
        ->and($byKey['veteran']->ratio())->toBe(0.0);
});

test('match badges are earned from the games played', function () {
    [$ann, $ben] = User::factory()->count(2)->create();

    Play::match($ann, $ben, [[15, 21], [21, 19], [23, 21]]);
    $shutout = Play::match($ann, $ben, [[21, 5], [21, 17]]);
    Play::match($ben, $ann, [[21, 10], [21, 10]]);

    $badges = achievementsOf($ann);

    expect($badges['first_blood']->earned)->toBeTrue()
        ->and($badges['comeback_kid']->earned)->toBeTrue()
        ->and($badges['iron_lungs']->earned)->toBeTrue()
        ->and($badges['shutout']->earned)->toBeTrue()
        ->and($badges['shutout']->earnedAt?->equalTo($shutout->played_at))->toBeTrue()
        ->and($badges['on_fire']->earned)->toBeFalse()
        ->and($badges['on_fire']->progress)->toBe(['current' => 2, 'target' => 5])
        ->and($badges['veteran']->progress)->toBe(['current' => 3, 'target' => 25]);

    $loser = achievementsOf($ben);

    expect($loser['first_blood']->earned)->toBeTrue()
        ->and($loser['iron_lungs']->earned)->toBeFalse()
        ->and($loser['comeback_kid']->earned)->toBeFalse();
});

test('five wins in a row, twenty-five matches and ten wins with one partner', function () {
    [$ann, $ben, $cas, $dee] = User::factory()->count(4)->create();

    RatingEngine::deferred(function () use ($ann, $ben, $cas, $dee): void {
        foreach (range(1, 10) as $i) {
            Play::win([$ann, $ben], [$cas, $dee]);
        }

        foreach (range(1, 15) as $i) {
            Play::win($cas, $ann);
        }
    });

    $badges = achievementsOf($ann);

    expect($badges['on_fire']->earned)->toBeTrue()
        ->and($badges['dynamic_duo']->earned)->toBeTrue()
        ->and($badges['dynamic_duo']->progress)->toBe(['current' => 10, 'target' => 10])
        ->and($badges['veteran']->earned)->toBeTrue()
        ->and($badges['veteran']->earnedAt?->equalTo(BadmintonMatch::query()->forPlayer($ann)->orderByDesc('played_at')->value('played_at')))->toBeTrue()
        ->and(achievementsOf($cas)['dynamic_duo']->progress)->toBe(['current' => 0, 'target' => 10]);
});

test('giant slayer needs a win over a side rated 150 higher before the match', function () {
    [$ann, $ben] = User::factory()->count(2)->create();
    $upset = BadmintonMatch::factory()->completed(Side::A)->withPlayers($ann, $ben)->create();
    $upset->participants()->where('user_id', $ann->id)->update(['rating_before' => 1500]);
    $upset->participants()->where('user_id', $ben->id)->update(['rating_before' => 1655]);

    expect(achievementsOf($ann)['giant_slayer']->earned)->toBeTrue();

    $upset->participants()->where('user_id', $ben->id)->update(['rating_before' => 1640]);

    expect(achievementsOf($ann)['giant_slayer']->earned)->toBeFalse();
});

test('tournament winners are champions and both finalists are finalists', function () {
    $knockout = Tournament::factory()->open()->knockout()->withEntries(2)->create();
    DrawGenerator::generate($knockout);
    $final = SaveMatch::handle(['status' => MatchStatus::Completed, 'games' => Play::games([[21, 9], [21, 9]])], $knockout->matches()->sole());
    $winner = $final->playersOn(Side::A)->sole();
    $runnerUp = $final->playersOn(Side::B)->sole();

    expect(achievementsOf($winner)['champion']->earned)->toBeTrue()
        ->and(achievementsOf($winner)['finalist']->earned)->toBeTrue()
        ->and(achievementsOf($runnerUp)['champion']->earned)->toBeFalse()
        ->and(achievementsOf($runnerUp)['finalist']->earned)->toBeTrue()
        ->and(achievementsOf($winner)['champion']->earnedAt?->equalTo($final->played_at))->toBeTrue();
});

test('the top two of a finished round robin count as champion and finalist', function () {
    $tournament = Tournament::factory()->open()->roundRobin()->withEntries(3)->create();
    DrawGenerator::generate($tournament);

    foreach ($tournament->matches()->with(['entryA', 'entryB'])->get() as $match) {
        // The better seed wins every match.
        $aWins = $match->entryA?->seed < $match->entryB?->seed;
        SaveMatch::handle(['status' => 'completed', 'games' => Play::games($aWins ? [[21, 9], [21, 9]] : [[9, 21], [9, 21]])], $match);
    }

    $entries = $tournament->entries()->orderBy('seed')->with('player')->get();

    expect(achievementsOf($entries[0]->player)['champion']->earned)->toBeTrue()
        ->and(achievementsOf($entries[1]->player)['finalist']->earned)->toBeTrue()
        ->and(achievementsOf($entries[1]->player)['champion']->earned)->toBeFalse()
        ->and(achievementsOf($entries[2]->player)['finalist']->earned)->toBeFalse();
});

test('club regulars, oracles and 1700 peaks', function () {
    $user = User::factory()->withRating(Discipline::Doubles, 1712, 20)->create();

    foreach (range(1, 10) as $week) {
        ClubNightRsvp::factory()
            ->for(ClubNight::factory()->state(['starts_at' => CarbonImmutable::parse('2026-09-01 19:30')->addWeeks($week)]))
            ->for($user)
            ->create(['checked_in_at' => CarbonImmutable::parse('2026-09-01 19:45')->addWeeks($week)]);
    }

    foreach (range(1, 10) as $i) {
        $match = BadmintonMatch::factory()->completed(Side::A)->create();
        Prediction::factory()->for($user)->for($match, 'match')->picking(Side::A)->create();
    }

    $badges = achievementsOf($user);

    expect($badges['club_regular']->earned)->toBeTrue()
        ->and($badges['club_regular']->earnedAt?->toDateTimeString())->toBe('2026-11-10 19:45:00')
        ->and($badges['oracle']->earned)->toBeTrue()
        ->and($badges['oracle']->progress)->toBe(['current' => 10, 'target' => 10])
        ->and($badges['peak_1700']->earned)->toBeTrue()
        ->and($badges['peak_1700']->progress)->toBe(['current' => 1700, 'target' => 1700])
        ->and($badges['peak_1700']->ratio())->toBe(1.0);
});
