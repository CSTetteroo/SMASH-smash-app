<?php

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\MatchGame;
use App\Models\Prediction;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A prediction on an in-memory match: $scores are the games, side A first.
 *
 * @param  list<array{0: int, 1: int}>  $scores
 */
function modelsSpecPrediction(Side $side, ?int $loserGames, MatchStatus $status, ?Side $winner, array $scores = []): Prediction
{
    $match = new BadmintonMatch([
        'discipline' => Discipline::Singles,
        'status' => $status,
        'winning_side' => $winner,
        'best_of' => 3,
    ]);

    $games = [];

    foreach ($scores as $index => [$a, $b]) {
        $games[] = new MatchGame(['number' => $index + 1, 'side_a_points' => $a, 'side_b_points' => $b]);
    }

    $match->setRelation('games', new Collection($games));

    return (new Prediction(['predicted_side' => $side, 'predicted_loser_games' => $loserGames]))
        ->setRelation('match', $match);
}

test('pick\'em points', function (Prediction $prediction, ?bool $correct, ?bool $exact, ?int $points) {
    expect($prediction->isCorrect())->toBe($correct)
        ->and($prediction->isExact())->toBe($exact)
        ->and($prediction->points())->toBe($points);
})->with([
    'not played yet' => [fn () => modelsSpecPrediction(Side::A, 0, MatchStatus::Scheduled, null), null, null, null],
    'live' => [fn () => modelsSpecPrediction(Side::A, 0, MatchStatus::InProgress, null, [[21, 10]]), null, null, null],
    'right winner, no game count' => [fn () => modelsSpecPrediction(Side::A, null, MatchStatus::Completed, Side::A, [[21, 10], [21, 12]]), true, false, 3],
    'right winner, straight games' => [fn () => modelsSpecPrediction(Side::A, 0, MatchStatus::Completed, Side::A, [[21, 10], [21, 12]]), true, true, 5],
    'right winner, three games' => [fn () => modelsSpecPrediction(Side::B, 1, MatchStatus::Completed, Side::B, [[21, 19], [17, 21], [20, 22]]), true, true, 5],
    'right winner, wrong game count' => [fn () => modelsSpecPrediction(Side::B, 0, MatchStatus::Completed, Side::B, [[21, 19], [17, 21], [20, 22]]), true, false, 3],
    'wrong winner' => [fn () => modelsSpecPrediction(Side::B, 1, MatchStatus::Completed, Side::A, [[21, 19], [17, 21], [22, 20]]), false, false, 0],
    'walkover is void' => [fn () => modelsSpecPrediction(Side::A, 0, MatchStatus::Walkover, Side::A), null, null, null],
    'cancelled is void' => [fn () => modelsSpecPrediction(Side::A, 0, MatchStatus::Cancelled, null), null, null, null],
]);

test('void and settled states', function () {
    expect(modelsSpecPrediction(Side::A, 0, MatchStatus::Walkover, Side::A)->isVoid())->toBeTrue()
        ->and(modelsSpecPrediction(Side::A, 0, MatchStatus::Cancelled, null)->isVoid())->toBeTrue()
        ->and(modelsSpecPrediction(Side::A, 0, MatchStatus::Completed, Side::A, [[21, 3], [21, 4]])->isSettled())->toBeTrue()
        ->and(modelsSpecPrediction(Side::A, 0, MatchStatus::Scheduled, null)->isVoid())->toBeFalse()
        ->and(modelsSpecPrediction(Side::A, 0, MatchStatus::Scheduled, null)->isSettled())->toBeFalse();
});

test('the predicted score reads from the picked side', function () {
    expect(modelsSpecPrediction(Side::B, 1, MatchStatus::Scheduled, null)->predictedScore())->toBe('2–1')
        ->and(modelsSpecPrediction(Side::A, 0, MatchStatus::Scheduled, null)->predictedScore())->toBe('2–0')
        ->and(modelsSpecPrediction(Side::A, null, MatchStatus::Scheduled, null)->predictedScore())->toBeNull();
});
