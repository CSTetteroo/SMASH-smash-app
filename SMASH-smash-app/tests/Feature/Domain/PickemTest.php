<?php

use App\Actions\Predictions\PlacePrediction;
use App\Enums\Discipline;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\Prediction;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Prediction\PredictionBacktest;
use App\Services\Predictions\PredictionLeaderboard;
use App\Services\Predictions\PredictorRow;
use App\Services\Rating\EloCalculator;
use App\Services\Tournament\DrawGenerator;
use Illuminate\Validation\ValidationException;

/**
 * @return array<string, list<string>>
 */
function pickemErrors(User $user, BadmintonMatch $match, Side $side, ?int $loserGames = null): array
{
    try {
        PlacePrediction::handle($user, $match, $side, $loserGames);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

/**
 * A completed, rated match with stored pre-match ratings per side.
 *
 * @param  list<float>  $ratingsA
 * @param  list<float>  $ratingsB
 */
function pickemRatedMatch(array $ratingsA, array $ratingsB, Side $winner, array $attributes = []): BadmintonMatch
{
    $sideA = User::factory()->count(count($ratingsA))->create();
    $sideB = User::factory()->count(count($ratingsB))->create();
    $factory = BadmintonMatch::factory()->completed($winner)->withPlayers($sideA, $sideB);
    $match = (count($ratingsA) > 1 ? $factory->doubles() : $factory)->create($attributes);

    foreach ([[$sideA, $ratingsA], [$sideB, $ratingsB]] as [$players, $ratings]) {
        foreach ($players->values() as $index => $player) {
            $match->participants()->where('user_id', $player->id)->update(['rating_before' => $ratings[$index]]);
        }
    }

    return $match;
}

test('members pick a winner and can change their mind until the match starts', function () {
    $user = User::factory()->create();
    $match = BadmintonMatch::factory()->withRandomPlayers()->create(['scheduled_at' => now()->addDay()]);

    $first = PlacePrediction::handle($user, $match, Side::A, 1);
    $second = PlacePrediction::handle($user, $match, Side::B);

    expect($second->id)->toBe($first->id)
        ->and($second->predicted_side)->toBe(Side::B)
        ->and($second->predicted_loser_games)->toBeNull()
        ->and(Prediction::query()->count())->toBe(1);
});

test('predictions follow the policy rules and need a known line-up', function () {
    $user = User::factory()->create();
    $player = User::factory()->create();
    $upcoming = BadmintonMatch::factory()->withPlayers($player, User::factory()->create())->create(['scheduled_at' => now()->addDay()]);
    $started = BadmintonMatch::factory()->withRandomPlayers()->create(['scheduled_at' => now()->subMinutes(5)]);
    $live = BadmintonMatch::factory()->inProgress()->withRandomPlayers()->create();
    $tournament = Tournament::factory()->open()->withEntries(4)->create();
    DrawGenerator::generate($tournament);
    $final = $tournament->matches()->where('round', 2)->sole();
    $final->update(['scheduled_at' => now()->addWeek()]);

    expect(pickemErrors($player, $upcoming, Side::A)['prediction'][0])->toContain('playing in this match')
        ->and(pickemErrors($user, $started, Side::A)['prediction'][0])->toContain('started')
        ->and(pickemErrors($user, $live, Side::A)['prediction'][0])->toContain('closed')
        ->and(pickemErrors($user, $final, Side::A)['prediction'][0])->toContain('not known yet')
        ->and(pickemErrors($user, $upcoming, Side::A, 2))->toHaveKey('loser_games')
        ->and(pickemErrors($user, $upcoming, Side::A, -1))->toHaveKey('loser_games')
        ->and(pickemErrors($user, $upcoming, Side::A, 1))->toBe([])
        ->and(Prediction::query()->count())->toBe(1);
});

test('the pick\'em table ranks by points, then correct and exact picks', function () {
    [$oracle, $steady, $unlucky, $idle] = User::factory()->count(4)->sequence(
        ['name' => 'Olga Oracle'], ['name' => 'Stan Steady'], ['name' => 'Uma Unlucky'], ['name' => 'Ivo Idle'],
    )->create();

    $straight = BadmintonMatch::factory()->completed(Side::A)->create();
    $close = BadmintonMatch::factory()->completed(Side::B)->create();
    $another = BadmintonMatch::factory()->completed(Side::A)->create();
    $walkover = BadmintonMatch::factory()->walkover(Side::A)->withRandomPlayers()->create();
    $upcoming = BadmintonMatch::factory()->withRandomPlayers()->create();

    // Olga: two exact picks (5 + 5). Stan: three right winners (3 × 3). Uma: one wrong, one void, one pending.
    Prediction::factory()->for($oracle)->for($straight, 'match')->picking(Side::A, 0)->create();
    Prediction::factory()->for($oracle)->for($another, 'match')->picking(Side::A, 0)->create();
    Prediction::factory()->for($steady)->for($straight, 'match')->picking(Side::A)->create();
    Prediction::factory()->for($steady)->for($close, 'match')->picking(Side::B, 1)->create();
    Prediction::factory()->for($steady)->for($another, 'match')->picking(Side::A)->create();
    Prediction::factory()->for($unlucky)->for($close, 'match')->picking(Side::A)->create();
    Prediction::factory()->for($unlucky)->for($walkover, 'match')->picking(Side::A)->create();
    Prediction::factory()->for($unlucky)->for($upcoming, 'match')->picking(Side::B)->create();
    Prediction::factory()->for($idle)->for($upcoming, 'match')->picking(Side::A)->create();

    $rows = PredictionLeaderboard::rows();

    expect($rows->map(fn (PredictorRow $row): string => $row->user->name)->all())
        ->toBe(['Olga Oracle', 'Stan Steady', 'Uma Unlucky', 'Ivo Idle'])
        ->and($rows->pluck('position')->all())->toBe([1, 2, 3, 4]);

    [$first, $second, $third, $fourth] = $rows->all();

    expect([$first->predictions, $first->settled, $first->correct, $first->exact, $first->points, $first->accuracy])->toBe([2, 2, 2, 2, 10, 1.0])
        ->and([$second->settled, $second->correct, $second->exact, $second->points])->toBe([3, 3, 0, 9])
        ->and([$third->predictions, $third->settled, $third->correct, $third->points, $third->accuracy])->toBe([3, 1, 0, 0, 0.0])
        ->and([$fourth->settled, $fourth->accuracy])->toBe([0, null])
        ->and(PredictionLeaderboard::rows(2))->toHaveCount(2);
});

test('the backtest scores the pure Elo pick from stored pre-match ratings', function () {
    $favouriteWins = pickemRatedMatch([1600], [1400], Side::A);
    $upset = pickemRatedMatch([1500], [1700], Side::A);
    pickemRatedMatch([1500], [1500], Side::B);
    $doubles = pickemRatedMatch([1600, 1500], [1500, 1500], Side::B);
    pickemRatedMatch([1900], [1100], Side::B, ['is_rated' => false]);
    BadmintonMatch::factory()->walkover(Side::A)->withRandomPlayers()->create();

    $result = PredictionBacktest::run();
    $pFavourite = EloCalculator::expected(1600, 1400);
    $pUpset = EloCalculator::expected(1500, 1700);
    $pDoubles = EloCalculator::expected(1550, 1500);

    expect($result['matches'])->toBe(3)
        ->and($result['skipped'])->toBe(1)
        ->and($result['correct'])->toBe(1)
        ->and($result['accuracy'])->toEqualWithDelta(1 / 3, 1e-12)
        ->and($result['brier'])->toEqualWithDelta(((1 - $pFavourite) ** 2 + (1 - $pUpset) ** 2 + $pDoubles ** 2) / 3, 1e-12)
        ->and($result['biggestUpsets'][0])->toBe(['match_id' => $upset->id, 'probability' => $pUpset])
        ->and($result['biggestUpsets'][1]['match_id'])->toBe($doubles->id)
        ->and(array_column($result['calibration'], 'bucket'))->toBe(['50–60%', '70–80%'])
        ->and(array_sum(array_column($result['calibration'], 'count')))->toBe(3);

    $favouriteBucket = $result['calibration'][1];

    expect($favouriteBucket['count'])->toBe(2)
        ->and($favouriteBucket['predicted'])->toEqualWithDelta(($pFavourite + (1 - $pUpset)) / 2, 1e-12)
        ->and($favouriteBucket['actual'])->toBe(0.5)
        ->and($favouriteWins->id)->toBeInt();

    expect(PredictionBacktest::run(Discipline::Doubles)['matches'])->toBe(1)
        ->and(PredictionBacktest::run(Discipline::Doubles)['correct'])->toBe(0);
});

test('an empty history has no accuracy yet', function () {
    expect(PredictionBacktest::run())->toBe([
        'matches' => 0, 'correct' => 0, 'accuracy' => null, 'brier' => null,
        'calibration' => [], 'biggestUpsets' => [], 'skipped' => 0,
    ]);
});
