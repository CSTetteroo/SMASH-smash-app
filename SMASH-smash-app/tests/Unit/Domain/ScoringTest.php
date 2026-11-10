<?php

use App\Enums\Side;
use App\Models\MatchGame;
use App\Support\Badminton\Scoring;

/**
 * @param  list<array{0: int, 1: int}>  $games
 * @return list<array{a: int, b: int}>
 */
function scoringGames(array $games): array
{
    return array_map(fn (array $game): array => ['a' => $game[0], 'b' => $game[1]], $games);
}

test('the golden point cap depends on the game length', function () {
    expect(Scoring::cap(21))->toBe(30)
        ->and(Scoring::cap(15))->toBe(21)
        ->and(Scoring::cap(11))->toBe(15);
});

test('complete games follow rally point scoring with win by two and the cap', function (int $a, int $b, int $target, bool $complete) {
    expect(Scoring::isCompleteGame($a, $b, $target))->toBe($complete)
        ->and(Scoring::isCompleteGame($b, $a, $target))->toBe($complete);
})->with([
    '21-0' => [21, 0, 21, true],
    '21-19' => [21, 19, 21, true],
    '21-20 needs two clear points' => [21, 20, 21, false],
    '22-20' => [22, 20, 21, true],
    '23-21' => [23, 21, 21, true],
    '29-27' => [29, 27, 21, true],
    '30-28' => [30, 28, 21, true],
    '30-29 golden point' => [30, 29, 21, true],
    '22-19 cannot happen' => [22, 19, 21, false],
    '25-20 cannot happen' => [25, 20, 21, false],
    '31-29 past the cap' => [31, 29, 21, false],
    '30-30 cannot happen' => [30, 30, 21, false],
    'level 20-20' => [20, 20, 21, false],
    'not finished 20-18' => [20, 18, 21, false],
    'negative' => [-1, 21, 21, false],
    'to 15: 15-13' => [15, 13, 15, true],
    'to 15: 17-15' => [17, 15, 15, true],
    'to 15: 21-20 at the cap' => [21, 20, 15, true],
    'to 15: 15-14' => [15, 14, 15, false],
    'to 11: 11-9' => [11, 9, 11, true],
    'to 11: 15-14 at the cap' => [15, 14, 11, true],
    'to 11: 16-14 past the cap' => [16, 14, 11, false],
]);

test('in-progress scores are reachable and not finished', function (int $a, int $b, int $target, bool $valid) {
    expect(Scoring::isValidInProgressGame($a, $b, $target))->toBe($valid);
})->with([
    '0-0' => [0, 0, 21, true],
    '20-0' => [20, 0, 21, true],
    '20-20' => [20, 20, 21, true],
    '21-20' => [21, 20, 21, true],
    '25-25' => [25, 25, 21, true],
    '29-28' => [29, 28, 21, true],
    '29-29' => [29, 29, 21, true],
    'finished 21-19' => [21, 19, 21, false],
    'finished 30-29' => [30, 29, 21, false],
    'unreachable 22-19' => [22, 19, 21, false],
    'unreachable 30-30' => [30, 30, 21, false],
    'negative' => [3, -1, 21, false],
    'to 11: 14-14' => [14, 14, 11, true],
    'to 11: 15-14 is over' => [15, 14, 11, false],
]);

test('every complete game ends exactly one point after a valid in-progress score', function (int $target) {
    $cap = Scoring::cap($target);

    for ($a = 0; $a <= $cap; $a++) {
        for ($b = 0; $b <= $cap; $b++) {
            if (! Scoring::isCompleteGame($a, $b, $target)) {
                continue;
            }

            [$previousA, $previousB] = $a > $b ? [$a - 1, $b] : [$a, $b - 1];

            expect(Scoring::isValidInProgressGame($previousA, $previousB, $target))
                ->toBeTrue("{$previousA}-{$previousB} should lead to {$a}-{$b}");
        }
    }
})->with([21, 15, 11]);

test('game winner, games to win and formatting', function () {
    expect(Scoring::gameWinner(21, 19))->toBe(Side::A)
        ->and(Scoring::gameWinner(19, 21))->toBe(Side::B)
        ->and(Scoring::gamesToWin(1))->toBe(1)
        ->and(Scoring::gamesToWin(3))->toBe(2)
        ->and(Scoring::gamesToWin(5))->toBe(3)
        ->and(Scoring::format(21, 17))->toBe('21–17');
});

test('valid completed matches pass validation', function (array $games, int $bestOf, int $target) {
    expect(Scoring::validate(scoringGames($games), $bestOf, $target, true))->toBe([]);
})->with([
    'straight games' => [[[21, 15], [21, 19]], 3, 21],
    'three games with a golden point' => [[[21, 15], [18, 21], [30, 29]], 3, 21],
    'side B in three' => [[[15, 21], [22, 20], [19, 21]], 3, 21],
    'best of one' => [[[21, 19]], 1, 21],
    'best of five in five' => [[[21, 1], [1, 21], [21, 1], [1, 21], [21, 1]], 5, 21],
    'to 15' => [[[15, 13], [21, 20]], 3, 15],
    'to 11' => [[[11, 9], [15, 14]], 3, 11],
]);

test('invalid completed matches are explained', function (array $games, int $bestOf, string $message) {
    $errors = Scoring::validate(scoringGames($games), $bestOf, 21, true);

    expect($errors)->not->toBeEmpty()
        ->and(implode(' ', $errors))->toContain($message);
})->with([
    'no games' => [[], 3, 'Enter the score'],
    'not decided' => [[[21, 15]], 3, 'not decided yet'],
    'one game each' => [[[21, 15], [15, 21]], 3, 'not decided yet'],
    'a game after the match was decided' => [[[21, 15], [21, 15], [21, 10]], 3, 'already decided in game 2'],
    'too many games' => [[[21, 15], [15, 21], [15, 21], [21, 5]], 3, 'at most 3 games'],
    'one point lead at 21' => [[[21, 20], [21, 15], [21, 15]], 3, 'Game 1 (21–20) is not finished'],
    'past the cap' => [[[31, 29], [21, 15]], 3, 'Game 1 (31–29) is not a valid score'],
    'unreachable score' => [[[25, 20], [21, 15]], 3, 'Game 1 (25–20) is not a valid score'],
    'unfinished game' => [[[21, 15], [11, 7]], 3, 'Game 2 (11–7) is not finished'],
    'negative score' => [[[-3, 21], [21, 15]], 3, 'negative'],
    'best of one decided twice' => [[[21, 19], [21, 19]], 1, 'already decided in game 1'],
]);

test('in-progress matches may end in an unfinished game but must not be decided', function () {
    expect(Scoring::validate([], 3, 21, false))->toBe([])
        ->and(Scoring::validate(scoringGames([[11, 7]]), 3, 21, false))->toBe([])
        ->and(Scoring::validate(scoringGames([[21, 15], [0, 0]]), 3, 21, false))->toBe([])
        ->and(Scoring::validate(scoringGames([[21, 15], [15, 21]]), 3, 21, false))->toBe([])
        ->and(Scoring::validate(scoringGames([[21, 15], [15, 21], [20, 20]]), 3, 21, false))->toBe([]);

    expect(implode(' ', Scoring::validate(scoringGames([[21, 15], [21, 15]]), 3, 21, false)))->toContain('already decide')
        ->and(implode(' ', Scoring::validate(scoringGames([[11, 7], [5, 3]]), 3, 21, false)))->toContain('only the last game')
        ->and(implode(' ', Scoring::validate(scoringGames([[21, 15], [25, 20]]), 3, 21, false)))->toContain('not a valid score');
});

test('the match winner is the first side to win enough games', function () {
    expect(Scoring::matchWinner(scoringGames([[21, 15], [21, 15]]), 3))->toBe(Side::A)
        ->and(Scoring::matchWinner(scoringGames([[15, 21], [21, 15], [19, 21]]), 3))->toBe(Side::B)
        ->and(Scoring::matchWinner(scoringGames([[21, 15]]), 3))->toBeNull()
        ->and(Scoring::matchWinner(scoringGames([[21, 15]]), 1))->toBe(Side::A)
        ->and(Scoring::matchWinner(scoringGames([[21, 15], [11, 7]]), 3, 21))->toBeNull()
        ->and(Scoring::matchWinner(scoringGames([[21, 15], [11, 7]]), 3))->toBe(Side::A);
});

test('complete games are tallied per side and stored games convert to arrays', function () {
    expect(Scoring::tally(scoringGames([[21, 15], [18, 21], [11, 7]]), 21))->toBe(['a' => 1, 'b' => 1]);

    $games = [
        new MatchGame(['number' => 2, 'side_a_points' => 18, 'side_b_points' => 21]),
        new MatchGame(['number' => 1, 'side_a_points' => 21, 'side_b_points' => 15]),
    ];

    expect(Scoring::fromModels($games))->toBe([['a' => 21, 'b' => 15], ['a' => 18, 'b' => 21]]);
});
