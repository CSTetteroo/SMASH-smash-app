<?php

use App\Enums\Side;
use App\Services\Tournament\DrawGenerator;
use App\Services\Tournament\TournamentProgress;

test('bracket size is the next power of two', function (int $entries, int $size) {
    expect(DrawGenerator::bracketSize($entries))->toBe($size);
})->with([[2, 2], [3, 4], [4, 4], [5, 8], [8, 8], [9, 16], [13, 16], [16, 16], [17, 32], [24, 32], [32, 32]]);

test('standard bracket order', function () {
    expect(DrawGenerator::bracketOrder(2))->toBe([1, 2])
        ->and(DrawGenerator::bracketOrder(4))->toBe([1, 4, 2, 3])
        ->and(DrawGenerator::bracketOrder(8))->toBe([1, 8, 4, 5, 2, 7, 3, 6])
        ->and(DrawGenerator::bracketOrder(16))->toBe([1, 16, 8, 9, 4, 13, 5, 12, 2, 15, 7, 10, 3, 14, 6, 11]);
});

test('the bracket keeps top seeds apart until the late rounds', function (int $size) {
    $order = DrawGenerator::bracketOrder($size);

    expect($order)->toHaveCount($size)
        ->and(array_values(array_unique($order)))->toHaveCount($size);

    // First-round pairs are seed s against seed size + 1 − s.
    foreach (array_chunk($order, 2) as [$a, $b]) {
        expect($a + $b)->toBe($size + 1)->and($a)->toBeLessThan($b);
    }

    // Every block of the bracket that plays down to one winner holds exactly one of the top seeds
    // for that stage: 1 and 2 in different halves, 1–4 in different quarters, and so on.
    for ($blocks = 2; $blocks <= $size; $blocks *= 2) {
        $best = array_map('min', array_chunk($order, intdiv($size, $blocks)));
        sort($best);

        expect($best)->toBe(range(1, $blocks));
    }
})->with([2, 4, 8, 16, 32, 64]);

test('round robin schedules every pair once without anybody playing twice on a matchday', function (int $entries) {
    $rounds = DrawGenerator::roundRobinRounds($entries);
    $pairs = [];

    expect($rounds)->toHaveCount($entries % 2 === 0 ? $entries - 1 : $entries);

    foreach ($rounds as $matchday) {
        $playing = array_merge(...$matchday);

        expect(count($playing))->toBe(count(array_unique($playing)))
            ->and($matchday)->toHaveCount(intdiv($entries, 2));

        foreach ($matchday as [$a, $b]) {
            expect($a)->toBeLessThan($b);
            $pairs[] = "{$a}-{$b}";
        }
    }

    expect($pairs)->toHaveCount(intdiv($entries * ($entries - 1), 2))
        ->and(array_unique($pairs))->toHaveCount(count($pairs))
        ->and(end($rounds))->toContain([0, 1]);
})->with([2, 3, 4, 5, 6, 7, 8, 9, 12]);

test('in an odd round robin everybody rests exactly once', function () {
    $rests = array_fill(0, 7, 0);

    foreach (DrawGenerator::roundRobinRounds(7) as $matchday) {
        $playing = array_merge(...$matchday);

        foreach (array_diff(range(0, 6), $playing) as $resting) {
            $rests[$resting]++;
        }
    }

    expect($rests)->toBe(array_fill(0, 7, 1));
});

test('winners move to position ceil(p / 2), odd positions on side A', function () {
    expect(TournamentProgress::nextPosition(1))->toBe(1)
        ->and(TournamentProgress::nextPosition(2))->toBe(1)
        ->and(TournamentProgress::nextPosition(3))->toBe(2)
        ->and(TournamentProgress::nextPosition(8))->toBe(4)
        ->and(TournamentProgress::nextSide(1))->toBe(Side::A)
        ->and(TournamentProgress::nextSide(2))->toBe(Side::B)
        ->and(TournamentProgress::nextSide(7))->toBe(Side::A);
});

test('no matches for fewer than two entries', function () {
    expect(DrawGenerator::roundRobinRounds(1))->toBe([])
        ->and(DrawGenerator::roundRobinRounds(0))->toBe([]);
});
