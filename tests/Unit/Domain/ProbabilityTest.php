<?php

use App\Enums\Side;
use App\Support\Badminton\Probability;
use App\Support\Badminton\Scoring;

/**
 * Independent check of the game probability: plain recursion over every score, using only the
 * scoring rules (no knowledge of the deuce shortcut).
 */
function probabilityBruteForce(float $r, int $target): float
{
    $memo = [];

    $win = function (int $a, int $b) use (&$win, &$memo, $r, $target): float {
        if (Scoring::isCompleteGame($a, $b, $target)) {
            return $a > $b ? 1.0 : 0.0;
        }

        return $memo["{$a}-{$b}"] ??= $r * $win($a + 1, $b) + (1 - $r) * $win($a, $b + 1);
    };

    return $win(0, 0);
}

test('match probability from game probability', function () {
    expect(Probability::matchFromGame(0.5, 3))->toEqualWithDelta(0.5, 1e-12)
        ->and(Probability::matchFromGame(0.6, 3))->toEqualWithDelta(0.648, 1e-12)
        ->and(Probability::matchFromGame(0.6, 1))->toEqualWithDelta(0.6, 1e-12)
        ->and(Probability::matchFromGame(0.6, 5))->toEqualWithDelta(0.68256, 1e-12)
        ->and(Probability::matchFromGame(0.0, 3))->toBe(0.0)
        ->and(Probability::matchFromGame(1.0, 3))->toEqualWithDelta(1.0, 1e-12);
});

test('bisection inverts the match probability', function (float $target, int $bestOf) {
    $game = Probability::gameFromMatch($target, $bestOf);

    expect(Probability::matchFromGame($game, $bestOf))->toEqualWithDelta($target, 1e-9);
})->with([
    [0.1, 3], [0.3, 3], [0.5, 3], [0.62, 3], [0.9, 3], [0.75, 1], [0.75, 5],
]);

test('a game at 50 % rallies is a coin flip and the model is symmetric', function (int $target) {
    expect(Probability::gameFromRally(0.5, $target))->toEqualWithDelta(0.5, 1e-12);

    foreach ([0.42, 0.51, 0.55, 0.6, 0.73] as $r) {
        expect(Probability::gameFromRally($r, $target) + Probability::gameFromRally(1 - $r, $target))->toEqualWithDelta(1.0, 1e-12);
    }
})->with([21, 15, 11]);

test('the rally dynamic programme matches brute force over every score', function (float $r, int $target) {
    expect(Probability::gameFromRally($r, $target))->toEqualWithDelta(probabilityBruteForce($r, $target), 1e-12);
})->with([
    [0.53, 21], [0.47, 21], [0.6, 21], [0.51, 15], [0.58, 11], [0.95, 21],
]);

test('winning more rallies means winning more games', function () {
    $previous = 0.0;

    foreach (range(1, 99) as $percent) {
        $game = Probability::gameFromRally($percent / 100, 21);

        // Strictly increasing until floating point saturates next to certainty.
        $percent <= 85
            ? expect($game)->toBeGreaterThan($previous)
            : expect($game)->toBeGreaterThanOrEqual($previous - 1e-12);

        $previous = $game;
    }
});

test('final scores are valid, include the golden point and sum to one', function (int $target) {
    $scores = Probability::finalScores(0.52, $target);
    $cap = Scoring::cap($target);

    foreach ($scores as $score) {
        expect(Scoring::isCompleteGame($score['a'], $score['b'], $target))->toBeTrue("{$score['a']}-{$score['b']}");
    }

    $keys = array_map(fn (array $score): string => $score['a'].'-'.$score['b'], $scores);

    expect(array_sum(array_column($scores, 'probability')))->toEqualWithDelta(1.0, 1e-12)
        ->and($keys)->toContain("{$cap}-".($cap - 1), ($cap - 1)."-{$cap}", "{$target}-0", ($target + 1).'-'.($target - 1))
        ->and(count(array_unique($keys)))->toBe(count($keys));
})->with([21, 15, 11]);

test('bisection inverts the game probability', function (float $game, int $target) {
    $rally = Probability::rallyFromGame($game, $target);

    expect(Probability::gameFromRally($rally, $target))->toEqualWithDelta($game, 1e-9);
})->with([[0.2, 21], [0.5, 21], [0.66, 21], [0.97, 21], [0.7, 15], [0.7, 11]]);

test('a game edge comes from a much smaller rally edge', function () {
    $rally = Probability::rallyFromGame(0.7, 21);

    expect($rally)->toBeGreaterThan(0.5)->toBeLessThan(0.6);
});

test('the score distribution lists A wins first and sums to one', function () {
    $distribution = Probability::scoreDistribution(0.6, 3);

    expect(array_keys($distribution))->toBe(['2–0', '2–1', '1–2', '0–2'])
        ->and(array_sum($distribution))->toEqualWithDelta(1.0, 1e-12)
        ->and($distribution['2–0'])->toEqualWithDelta(0.36, 1e-12)
        ->and($distribution['2–1'])->toEqualWithDelta(0.288, 1e-12)
        ->and($distribution['1–2'])->toEqualWithDelta(0.192, 1e-12)
        ->and($distribution['0–2'])->toEqualWithDelta(0.16, 1e-12)
        ->and($distribution['2–0'] + $distribution['2–1'])->toEqualWithDelta(Probability::matchFromGame(0.6, 3), 1e-12);

    expect(array_keys(Probability::scoreDistribution(0.5, 1)))->toBe(['1–0', '0–1'])
        ->and(array_keys(Probability::scoreDistribution(0.5, 5)))->toBe(['3–0', '3–1', '3–2', '2–3', '1–3', '0–3'])
        ->and(array_sum(Probability::scoreDistribution(0.37, 5)))->toEqualWithDelta(1.0, 1e-12);
});

test('the typical winning game is a valid score that tightens as sides get closer', function () {
    $close = Probability::typicalWinningScore(0.5, 21, Side::A);
    $clear = Probability::typicalWinningScore(0.6, 21, Side::A);
    $crushing = Probability::typicalWinningScore(0.99, 21, Side::A);
    $mirrored = Probability::typicalWinningScore(0.4, 21, Side::B);

    expect($close[0])->toBe(21)
        ->and($close[1])->toBeGreaterThanOrEqual(15)->toBeLessThanOrEqual(19)
        ->and($clear[1])->toBeLessThan($close[1])
        ->and($crushing)->toBe([21, 0])
        ->and($mirrored)->toBe($clear);

    foreach ([0.3, 0.5, 0.55, 0.7] as $r) {
        [$winner, $loser] = Probability::typicalWinningScore($r, 15, Side::A);

        expect(Scoring::isCompleteGame($winner, $loser, 15))->toBeTrue()
            ->and($winner)->toBeGreaterThan($loser);
    }
});

test('the chance of winning a game from any score', function () {
    expect(Probability::gameFromScore(0.5, 21, 0, 0))->toEqualWithDelta(0.5, 1e-12)
        ->and(Probability::gameFromScore(0.56, 21, 0, 0))->toEqualWithDelta(Probability::gameFromRally(0.56, 21), 1e-12)
        ->and(Probability::gameFromScore(0.5, 21, 21, 20))->toEqualWithDelta(0.75, 1e-12)
        ->and(Probability::gameFromScore(0.5, 21, 25, 25))->toEqualWithDelta(0.5, 1e-12)
        ->and(Probability::gameFromScore(0.6, 21, 29, 29))->toEqualWithDelta(0.6, 1e-12)
        ->and(Probability::gameFromScore(0.5, 21, 20, 0))->toBeGreaterThan(0.999)
        ->and(Probability::gameFromScore(0.5, 21, 21, 19))->toBe(1.0)
        ->and(Probability::gameFromScore(0.5, 21, 17, 21))->toBe(0.0)
        ->and(Probability::gameFromScore(0.55, 21, 25, 20))->toEqualWithDelta(Probability::gameFromRally(0.55, 21), 1e-12);
});

test('the live chance of winning the match from the score so far', function () {
    $r = 0.53;

    expect(Probability::matchFromScore($r, 3, 21, 0, 0))->toEqualWithDelta(Probability::matchFromGame(Probability::gameFromRally($r, 21), 3), 1e-12)
        ->and(Probability::matchFromScore(0.5, 3, 21, 1, 0))->toEqualWithDelta(0.75, 1e-12)
        ->and(Probability::matchFromScore(0.5, 3, 21, 1, 1, 20, 18))->toEqualWithDelta(Probability::gameFromScore(0.5, 21, 20, 18), 1e-12)
        ->and(Probability::matchFromScore(0.5, 3, 21, 2, 0))->toBe(1.0)
        ->and(Probability::matchFromScore(0.5, 3, 21, 0, 2))->toBe(0.0)
        ->and(Probability::matchFromScore(0.5, 1, 21, 0, 0, 11, 3))->toEqualWithDelta(Probability::gameFromScore(0.5, 21, 11, 3), 1e-12)
        ->and(Probability::matchFromScore(0.45, 3, 21, 1, 0, 15, 10))->toBeGreaterThan(Probability::matchFromScore(0.45, 3, 21, 1, 0));
});

test('binomial coefficients', function () {
    expect(Probability::binomial(5, 2))->toBe(10.0)
        ->and(Probability::binomial(40, 19))->toEqualWithDelta(131282408400.0, 1.0)
        ->and(Probability::binomial(3, 5))->toBe(0.0);
});
