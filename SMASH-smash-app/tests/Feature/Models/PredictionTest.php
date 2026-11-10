<?php

use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\MatchGame;
use App\Models\Prediction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A completed best-of-3 won 2–1 by side B.
 */
function modelsSpecThreeGameMatch(): BadmintonMatch
{
    $match = BadmintonMatch::factory()->withRandomPlayers()->create([
        'status' => MatchStatus::Completed,
        'winning_side' => Side::B,
        'played_at' => now()->subDay(),
    ]);

    MatchGame::factory()->for($match, 'match')->number(1)->score(21, 17)->create();
    MatchGame::factory()->for($match, 'match')->number(2)->score(19, 21)->create();
    MatchGame::factory()->for($match, 'match')->number(3)->score(23, 25)->create();

    return $match;
}

test('predictions score once the match is completed', function () {
    $match = modelsSpecThreeGameMatch();

    $exact = Prediction::factory()->for($match, 'match')->picking(Side::B, 1)->create();
    $winnerOnly = Prediction::factory()->for($match, 'match')->picking(Side::B)->create();
    $wrongCount = Prediction::factory()->for($match, 'match')->picking(Side::B, 0)->create();
    $wrong = Prediction::factory()->for($match, 'match')->picking(Side::A, 1)->create();

    expect($exact->points())->toBe(5)
        ->and($exact->isExact())->toBeTrue()
        ->and($winnerOnly->points())->toBe(3)
        ->and($winnerOnly->isExact())->toBeFalse()
        ->and($wrongCount->points())->toBe(3)
        ->and($wrong->points())->toBe(0)
        ->and($wrong->isCorrect())->toBeFalse()
        ->and($wrong->isExact())->toBeFalse();
});

test('predictions on unplayed matches are pending and walkovers are void', function () {
    $scheduled = Prediction::factory()->for(BadmintonMatch::factory()->scheduled(), 'match')->picking(Side::A, 0)->create();
    $live = Prediction::factory()->for(BadmintonMatch::factory()->inProgress(), 'match')->picking(Side::A, 0)->create();
    $walkover = Prediction::factory()->for(BadmintonMatch::factory()->walkover(Side::A), 'match')->picking(Side::A, 0)->create();

    expect($scheduled->points())->toBeNull()
        ->and($scheduled->isCorrect())->toBeNull()
        ->and($live->points())->toBeNull()
        ->and($walkover->points())->toBeNull()
        ->and($walkover->isVoid())->toBeTrue()
        ->and($walkover->isCorrect())->toBeNull();
});

test('settled and pending scopes', function () {
    $user = User::factory()->create();
    $settled = Prediction::factory()->for($user)->for(modelsSpecThreeGameMatch(), 'match')->create();
    $pending = Prediction::factory()->for($user)->for(BadmintonMatch::factory()->scheduled(), 'match')->create();
    Prediction::factory()->for($user)->for(BadmintonMatch::factory()->walkover(), 'match')->create();

    expect(Prediction::settled()->pluck('id')->all())->toBe([$settled->id])
        ->and(Prediction::pending()->pluck('id')->all())->toBe([$pending->id])
        ->and($user->predictions()->settled()->count())->toBe(1);
});

test('scoring eager-loaded predictions needs no further queries', function () {
    $match = modelsSpecThreeGameMatch();
    Prediction::factory()->count(5)->for($match, 'match')->create();

    $predictions = Prediction::query()->with('match.games')->get();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $points = $predictions->map(fn (Prediction $prediction) => $prediction->points());

    expect($points)->toHaveCount(5)
        ->and(DB::getQueryLog())->toBeEmpty();
});

test('a member predicts a match only once', function () {
    $prediction = Prediction::factory()->create();

    Prediction::factory()->create(['user_id' => $prediction->user_id, 'match_id' => $prediction->match_id]);
})->throws(UniqueConstraintViolationException::class);
