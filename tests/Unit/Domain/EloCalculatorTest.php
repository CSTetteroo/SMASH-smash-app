<?php

use App\Enums\Side;
use App\Services\Rating\EloCalculator;
use Tests\TestCase;

uses(TestCase::class);

test('expected score is 50 % for equal ratings and 10/11 for a 400 point gap', function () {
    expect(EloCalculator::expected(1500, 1500))->toBe(0.5)
        ->and(EloCalculator::expected(1900, 1500))->toEqualWithDelta(10 / 11, 1e-12)
        ->and(EloCalculator::expected(1500, 1900))->toEqualWithDelta(1 / 11, 1e-12);
});

test('expected scores of both sides add up to one', function (float $a, float $b) {
    expect(EloCalculator::expected($a, $b) + EloCalculator::expected($b, $a))->toEqualWithDelta(1.0, 1e-12);
})->with([[1500, 1500], [1623.4, 1540.2], [1200, 1850], [2100, 1300]]);

test('the K-factor is 40 while provisional, 24 after ten matches and 25 % higher in tournaments', function () {
    expect(EloCalculator::kFactor(0, false))->toBe(40.0)
        ->and(EloCalculator::kFactor(9, false))->toBe(40.0)
        ->and(EloCalculator::kFactor(10, false))->toBe(24.0)
        ->and(EloCalculator::kFactor(250, false))->toBe(24.0)
        ->and(EloCalculator::kFactor(0, true))->toBe(50.0)
        ->and(EloCalculator::kFactor(10, true))->toBe(30.0);
});

test('the margin rewards straight games, softens deciders and adds a capped point bonus', function (int $winnerGames, int $loserGames, int $pointDiff, int $bestOf, float $expected) {
    expect(EloCalculator::marginMultiplier($winnerGames, $loserGames, $pointDiff, $bestOf))->toEqualWithDelta($expected, 1e-12);
})->with([
    'straight games, level points' => [2, 0, 0, 3, 1.10],
    'decider' => [2, 1, 0, 3, 0.90],
    'best of one' => [1, 0, 0, 1, 1.0],
    'straight games, +15 points' => [2, 0, 15, 3, 1.10 * 1.10],
    'straight games, +30 points' => [2, 0, 30, 3, 1.10 * 1.20],
    'point bonus capped at 30' => [2, 0, 45, 3, 1.10 * 1.20],
    'negative point difference ignored' => [2, 1, -4, 3, 0.90],
    'best of five 3-1 is neither' => [3, 1, 15, 5, 1.10],
    'best of five 3-2 is a decider' => [3, 2, 0, 5, 0.90],
    'best of five 3-0' => [3, 0, 0, 5, 1.10],
]);

test('delta is the change for side A and mirrors for side B', function () {
    expect(EloCalculator::delta(1500, 1500, Side::A, 40, 1.0))->toBe(20.0)
        ->and(EloCalculator::delta(1500, 1500, Side::B, 40, 1.0))->toBe(-20.0)
        ->and(EloCalculator::delta(1600, 1500, Side::A, 24, 1.1))->toEqualWithDelta(24 * 1.1 * (1 - EloCalculator::expected(1600, 1500)), 1e-12)
        ->and(EloCalculator::delta(1600, 1500, Side::A, 24, 1.0))->toEqualWithDelta(-EloCalculator::delta(1500, 1600, Side::B, 24, 1.0), 1e-12);
});

test('beating a stronger side earns more than beating a weaker one', function () {
    expect(EloCalculator::delta(1400, 1600, Side::A, 24, 1.0))
        ->toBeGreaterThan(EloCalculator::delta(1600, 1400, Side::A, 24, 1.0));
});

test('the tunables come from config/smash.php', function () {
    config(['smash.rating.scale' => 200, 'smash.rating.k_established' => 30, 'smash.rating.provisional_matches' => 3]);

    expect(EloCalculator::expected(1700, 1500))->toEqualWithDelta(10 / 11, 1e-12)
        ->and(EloCalculator::kFactor(3, false))->toBe(30.0)
        ->and(EloCalculator::kFactor(2, false))->toBe(40.0);
});
