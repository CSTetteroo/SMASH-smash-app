<?php

use App\Enums\Side;
use App\Models\MatchGame;
use App\Models\MatchPlayer;
use Tests\TestCase;

uses(TestCase::class);

test('a game knows its winner, leader and points per side', function () {
    $game = new MatchGame(['number' => 1, 'side_a_points' => 17, 'side_b_points' => 21]);

    expect($game->winner())->toBe(Side::B)
        ->and($game->leader())->toBe(Side::B)
        ->and($game->pointsFor(Side::A))->toBe(17)
        ->and($game->pointsFor(Side::B))->toBe(21)
        ->and($game->score())->toBe('17–21');

    $level = new MatchGame(['number' => 2, 'side_a_points' => 9, 'side_b_points' => 9]);

    expect($level->leader())->toBeNull();
});

test('a game is complete only with a valid final score', function (int $a, int $b, int $target, bool $complete) {
    $game = new MatchGame(['number' => 1, 'side_a_points' => $a, 'side_b_points' => $b]);

    expect($game->isComplete($target))->toBe($complete);
})->with([
    'straight to 21' => [21, 19, 21, true],
    'love game' => [21, 0, 21, true],
    'one point lead at 21' => [21, 20, 21, false],
    'extended by two' => [22, 20, 21, true],
    'extended to 29-27' => [27, 29, 21, true],
    'golden point 30-29' => [30, 29, 21, true],
    'cap 30-28' => [28, 30, 21, true],
    'too far past 21' => [24, 21, 21, false],
    'not reached' => [20, 18, 21, false],
    'in progress' => [11, 7, 21, false],
    'to 15' => [15, 13, 15, true],
    'to 15 capped at 21' => [21, 20, 15, true],
    'to 11' => [11, 4, 11, true],
    'to 11 golden point at 15' => [15, 14, 11, true],
]);

test('the golden point cap depends on the game length', function () {
    expect(MatchGame::cap(21))->toBe(30)
        ->and(MatchGame::cap(15))->toBe(21)
        ->and(MatchGame::cap(11))->toBe(15);
});

test('a match player rating change is after minus before', function () {
    expect((new MatchPlayer(['side' => Side::A]))->ratingChange())->toBeNull()
        ->and((new MatchPlayer(['side' => Side::A, 'rating_before' => 1500.0]))->ratingChange())->toBeNull()
        ->and((new MatchPlayer(['side' => Side::B, 'rating_before' => 1500.0, 'rating_after' => 1512.5]))->ratingChange())->toBe(12.5)
        ->and((new MatchPlayer(['side' => Side::B, 'rating_before' => 1610.25, 'rating_after' => 1600.0]))->ratingChange())->toBe(-10.25);
});
