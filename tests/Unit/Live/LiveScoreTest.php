<?php

use App\Enums\Side;
use App\Support\Live\LiveScore;

/**
 * Score rallies from a string of winners: "aab" = A, A, B.
 */
function rallies(LiveScore $state, string $winners): LiveScore
{
    foreach (str_split($winners) as $winner) {
        $state = $state->point(Side::from($winner));
    }

    return $state;
}

/**
 * Play the game in progress (from 0–0) to $a–$b, alternating so no game ends on the way.
 */
function scoreTo(LiveScore $state, int $a, int $b): LiveScore
{
    $shared = min($a, $b);

    return rallies($state, str_repeat('ab', $shared).str_repeat('a', $a - $shared).str_repeat('b', $b - $shared));
}

function served(Side $server = Side::A, int $bestOf = 3, int $target = 21): LiveScore
{
    return LiveScore::start($bestOf, $target)->withServer($server);
}

test('a new match starts at 0–0 in game 1 and waits for the first server', function () {
    $state = LiveScore::start();

    expect($state->games())->toBe([['a' => 0, 'b' => 0]])
        ->and($state->gameNumber())->toBe(1)
        ->and($state->needsServer())->toBeTrue()
        ->and($state->serving())->toBeNull()
        ->and($state->serviceCourt())->toBeNull()
        ->and($state->canUndo())->toBeFalse()
        ->and($state->isDecided())->toBeFalse()
        ->and(fn () => $state->point(Side::A))->toThrow(LogicException::class, 'Choose who is serving first.');
});

test('the rally winner serves next, from the right on an even score and the left on an odd one', function () {
    $state = served(Side::B);

    expect($state->serving())->toBe(Side::B)
        ->and($state->serviceCourt())->toBe('right');

    $state = $state->point(Side::B);  // 0–1: B keeps serving, odd
    expect($state->serving())->toBe(Side::B)->and($state->serviceCourt())->toBe('left');

    $state = $state->point(Side::A);  // 1–1: A wins the serve, odd
    expect($state->serving())->toBe(Side::A)->and($state->serviceCourt())->toBe('left');

    $state = $state->point(Side::A);  // 2–1: even
    expect($state->serving())->toBe(Side::A)
        ->and($state->serviceCourt())->toBe('right')
        ->and($state->current())->toBe(['a' => 2, 'b' => 1]);

    $state = $state->point(Side::B);  // 2–2: B serves from the right
    expect($state->serving())->toBe(Side::B)->and($state->serviceCourt())->toBe('right');
});

test('21–19 ends the game and the winner serves first in the next one', function () {
    $state = scoreTo(served(), 20, 19);

    expect($state->hasGamePoint(Side::A))->toBeTrue()
        ->and($state->hasGamePoint(Side::B))->toBeFalse()
        ->and($state->gameNumber())->toBe(1);

    $state = $state->point(Side::A);

    expect($state->event())->toBe(LiveScore::GAME)
        ->and($state->games())->toBe([['a' => 21, 'b' => 19], ['a' => 0, 'b' => 0]])
        ->and($state->completedGames())->toBe([['a' => 21, 'b' => 19]])
        ->and($state->gamesWon())->toBe(['a' => 1, 'b' => 0])
        ->and($state->gameNumber())->toBe(2)
        ->and($state->current())->toBe(['a' => 0, 'b' => 0])
        ->and($state->serving())->toBe(Side::A)
        ->and($state->serviceCourt())->toBe('right')
        ->and($state->isDecided())->toBeFalse();
});

test('a game the receiving side wins hands them the first serve of the next game', function () {
    $state = rallies(scoreTo(served(Side::A), 19, 20), 'b');

    expect($state->completedGames())->toBe([['a' => 19, 'b' => 21]])
        ->and($state->serving())->toBe(Side::B);
});

test('20–20 carries on until a side leads by two', function () {
    $state = scoreTo(served(), 20, 20);

    expect($state->isExtended())->toBeTrue()
        ->and($state->hasGamePoint(Side::A))->toBeFalse()
        ->and($state->hasGamePoint(Side::B))->toBeFalse();

    $state = $state->point(Side::A); // 21–20 continues

    expect($state->gameNumber())->toBe(1)
        ->and($state->current())->toBe(['a' => 21, 'b' => 20])
        ->and($state->event())->toBeNull()
        ->and($state->hasGamePoint(Side::A))->toBeTrue();

    $state = $state->point(Side::B); // 21–21 continues

    expect($state->gameNumber())->toBe(1)->and($state->hasGamePoint(Side::B))->toBeFalse();

    $state = rallies($state, 'bb'); // 21–23 ends

    expect($state->completedGames())->toBe([['a' => 21, 'b' => 23]])
        ->and($state->event())->toBe(LiveScore::GAME);
});

test('22–20 ends an extended game', function () {
    $state = rallies(scoreTo(served(), 20, 20), 'aa');

    expect($state->completedGames())->toBe([['a' => 22, 'b' => 20]])
        ->and($state->gameNumber())->toBe(2);
});

test('29–29 is a golden point and 30–29 ends the game at the cap', function () {
    $state = scoreTo(served(), 29, 29);

    expect($state->isGoldenPoint())->toBeTrue()
        ->and($state->hasGamePoint(Side::A))->toBeTrue()
        ->and($state->hasGamePoint(Side::B))->toBeTrue()
        ->and($state->gameNumber())->toBe(1);

    $state = $state->point(Side::A);

    expect($state->completedGames())->toBe([['a' => 30, 'b' => 29]])
        ->and($state->event())->toBe(LiveScore::GAME)
        ->and($state->isGoldenPoint())->toBeFalse();
});

test('the cap works for the side that trails at 28–29 too', function () {
    $state = scoreTo(served(), 28, 29);

    expect($state->hasGamePoint(Side::B))->toBeTrue() // 28–30 would be a two-point lead
        ->and($state->hasGamePoint(Side::A))->toBeFalse();

    $state = $state->point(Side::A); // 29–29

    expect($state->isGoldenPoint())->toBeTrue();

    $state = $state->point(Side::B); // 29–30

    expect($state->completedGames())->toBe([['a' => 29, 'b' => 30]]);
});

test('a game never goes past the cap', function () {
    $state = rallies(scoreTo(served(), 29, 29), 'b');

    expect($state->games()[0])->toBe(['a' => 29, 'b' => 30])
        ->and($state->current())->toBe(['a' => 0, 'b' => 0]);
});

test('the leader reaching 11 calls the interval once per game', function () {
    $state = scoreTo(served(), 10, 6);

    $state = $state->point(Side::A); // 11–6

    expect($state->event())->toBe(LiveScore::INTERVAL)
        ->and($state->isDecidingGame())->toBeFalse();

    $state = $state->point(Side::B); // 11–7

    expect($state->event())->toBeNull();

    $state = scoreTo(served(), 10, 10)->point(Side::B); // 10–11: B leads to 11 first

    expect($state->event())->toBe(LiveScore::INTERVAL);

    $state = $state->point(Side::A); // 11–11: not the leading score reaching 11

    expect($state->event())->toBeNull();
});

test('in the deciding game the interval at 11 is also the change of ends', function () {
    $state = rallies(scoreTo(served(), 20, 10), 'a');   // game 1 to A
    $state = rallies(scoreTo($state, 10, 20), 'b');     // game 2 to B

    expect($state->gameNumber())->toBe(3)
        ->and($state->isDecidingGame())->toBeTrue()
        ->and($state->gamesWon())->toBe(['a' => 1, 'b' => 1]);

    $state = scoreTo($state, 10, 4)->point(Side::A);

    expect($state->event())->toBe(LiveScore::CHANGE_ENDS);
});

test('a best of one is decided in its only game, which is also the deciding game', function () {
    $state = served(Side::A, bestOf: 1);

    expect($state->isDecidingGame())->toBeTrue();

    $state = scoreTo($state, 10, 3)->point(Side::A);

    expect($state->event())->toBe(LiveScore::CHANGE_ENDS);

    $state = rallies(scoreTo(served(Side::A, bestOf: 1), 20, 3), 'a');

    expect($state->winner())->toBe(Side::A)->and($state->event())->toBe(LiveScore::MATCH);
});

test('short formats use their own interval and cap', function () {
    $fifteen = served(target: 15);

    expect($fifteen->intervalAt())->toBe(8)
        ->and(scoreTo($fifteen, 7, 2)->point(Side::A)->event())->toBe(LiveScore::INTERVAL)
        ->and(rallies(scoreTo($fifteen, 20, 20), 'a')->completedGames())->toBe([['a' => 21, 'b' => 20]]);

    $eleven = served(target: 11);

    expect($eleven->intervalAt())->toBe(6)
        ->and(rallies(scoreTo($eleven, 10, 8), 'a')->completedGames())->toBe([['a' => 11, 'b' => 8]])
        ->and(scoreTo($eleven, 14, 14)->isGoldenPoint())->toBeTrue();
});

test('game point becomes match point when the game would win the match', function () {
    $state = rallies(scoreTo(served(), 20, 15), 'a');  // A leads 1–0
    $state = scoreTo($state, 20, 18);

    expect($state->hasGamePoint(Side::A))->toBeTrue()
        ->and($state->hasMatchPoint(Side::A))->toBeTrue()
        ->and($state->hasMatchPoint(Side::B))->toBeFalse();

    $first = scoreTo(served(), 20, 18);

    expect($first->hasGamePoint(Side::A))->toBeTrue()
        ->and($first->hasMatchPoint(Side::A))->toBeFalse();
});

test('the match ends with the deciding rally, which is kept out of the recorded games until confirmed', function () {
    $state = rallies(scoreTo(served(), 20, 12), 'a');
    $state = scoreTo($state, 20, 19);
    $decided = $state->point(Side::A);

    expect($decided->isDecided())->toBeTrue()
        ->and($decided->winner())->toBe(Side::A)
        ->and($decided->event())->toBe(LiveScore::MATCH)
        ->and($decided->games())->toBe([['a' => 21, 'b' => 12], ['a' => 21, 'b' => 19]])
        ->and($decided->completedGames())->toBe([['a' => 21, 'b' => 12], ['a' => 21, 'b' => 19]])
        ->and($decided->gamesWon())->toBe(['a' => 2, 'b' => 0])
        ->and($decided->recorded())->toBe([['a' => 21, 'b' => 12], ['a' => 20, 'b' => 19]])
        ->and($decided->serving())->toBeNull()
        ->and($decided->hasGamePoint(Side::A))->toBeFalse()
        ->and(fn () => $decided->point(Side::B))->toThrow(LogicException::class, 'already decided');

    expect($decided->undo()->isDecided())->toBeFalse()
        ->and($decided->undo()->current())->toBe(['a' => 20, 'b' => 19]);
});

test('undo forgets the last rally, also across the end of a game, and restores the server', function () {
    $state = scoreTo(served(Side::B), 20, 19);
    $afterGame = $state->point(Side::A);

    expect($afterGame->gameNumber())->toBe(2);

    $back = $afterGame->undo();

    expect($back->games())->toBe([['a' => 20, 'b' => 19]])
        ->and($back->gameNumber())->toBe(1)
        ->and($back->serving())->toBe($state->serving())
        ->and($back->version())->toBe($state->version());

    $first = served(Side::B)->point(Side::A)->undo();

    expect($first->current())->toBe(['a' => 0, 'b' => 0])
        ->and($first->serving())->toBe(Side::B)
        ->and($first->canUndo())->toBeTrue();

    $unchosen = $first->undo();

    expect($unchosen->needsServer())->toBeTrue()
        ->and($unchosen->canUndo())->toBeFalse()
        ->and(fn () => $unchosen->undo())->toThrow(LogicException::class, 'nothing to undo');
});

test('the server can only be chosen before the first rally', function () {
    $state = served(Side::A)->withServer(Side::B);

    expect($state->serving())->toBe(Side::B)
        ->and(fn () => $state->point(Side::A)->withServer(Side::B))->toThrow(LogicException::class);
});

test('a match picked up from saved games asks who serves unless a game is just starting', function () {
    $midGame = LiveScore::resume([['a' => 21, 'b' => 19], ['a' => 5, 'b' => 3]]);

    expect($midGame->gameNumber())->toBe(2)
        ->and($midGame->current())->toBe(['a' => 5, 'b' => 3])
        ->and($midGame->needsServer())->toBeTrue()
        ->and($midGame->canUndo())->toBeFalse()
        ->and($midGame->recorded())->toBe([['a' => 21, 'b' => 19], ['a' => 5, 'b' => 3]]);

    $nextGame = LiveScore::resume([['a' => 21, 'b' => 19], ['a' => 0, 'b' => 0]]);

    expect($nextGame->serving())->toBe(Side::A)
        ->and($nextGame->serviceCourt())->toBe('right')
        ->and($nextGame->canUndo())->toBeFalse();

    $afterGame = LiveScore::resume([['a' => 18, 'b' => 21]]);

    expect($afterGame->games())->toBe([['a' => 18, 'b' => 21], ['a' => 0, 'b' => 0]])
        ->and($afterGame->serving())->toBe(Side::B);

    $nothing = LiveScore::resume([]);

    expect($nothing->games())->toBe([['a' => 0, 'b' => 0]])
        ->and($nothing->needsServer())->toBeTrue();
});

test('saved games that decide the match resume as decided, and impossible ones are refused', function () {
    $decided = LiveScore::resume([['a' => 21, 'b' => 10], ['a' => 21, 'b' => 12]]);

    expect($decided->isDecided())->toBeTrue()
        ->and($decided->winner())->toBe(Side::A)
        ->and($decided->needsServer())->toBeFalse();

    expect(fn () => LiveScore::resume([['a' => 25, 'b' => 3]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => LiveScore::resume([['a' => 15, 'b' => 3], ['a' => 2, 'b' => 2]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => LiveScore::resume([], bestOf: 2))->toThrow(InvalidArgumentException::class);
});

test('the state survives a round trip through the cache format', function () {
    $state = rallies(scoreTo(served(Side::B), 20, 17), 'a');
    $state = scoreTo($state, 3, 1);

    $restored = LiveScore::fromArray($state->toArray());

    expect($restored)->not->toBeNull()
        ->and($restored?->games())->toBe($state->games())
        ->and($restored?->serving())->toBe($state->serving())
        ->and($restored?->history())->toBe($state->history())
        ->and($restored?->version())->toBe($state->version())
        ->and($restored?->canUndo())->toBeTrue();
});

test('malformed or impossible cached states are ignored', function (mixed $data) {
    expect(LiveScore::fromArray($data))->toBeNull();
})->with([
    'nothing' => [null],
    'a string' => ['live'],
    'another schema' => [['v' => 2, 'best_of' => 3, 'target' => 21, 'base' => [['a' => 0, 'b' => 0]], 'server' => 'a', 'chosen' => true, 'rallies' => '']],
    'unknown rally winner' => [['v' => 1, 'best_of' => 3, 'target' => 21, 'base' => [['a' => 0, 'b' => 0]], 'server' => 'a', 'chosen' => true, 'rallies' => 'abc']],
    'unknown server' => [['v' => 1, 'best_of' => 3, 'target' => 21, 'base' => [['a' => 0, 'b' => 0]], 'server' => 'c', 'chosen' => true, 'rallies' => '']],
    'impossible base' => [['v' => 1, 'best_of' => 3, 'target' => 21, 'base' => [['a' => 40, 'b' => 0]], 'server' => 'a', 'chosen' => true, 'rallies' => '']],
    'base not opened' => [['v' => 1, 'best_of' => 3, 'target' => 21, 'base' => [], 'server' => 'a', 'chosen' => true, 'rallies' => '']],
    'string scores' => [['v' => 1, 'best_of' => 3, 'target' => 21, 'base' => [['a' => '3', 'b' => 0]], 'server' => 'a', 'chosen' => true, 'rallies' => '']],
    'rallies after the match was decided' => [['v' => 1, 'best_of' => 1, 'target' => 11, 'base' => [['a' => 0, 'b' => 0]], 'server' => 'a', 'chosen' => true, 'rallies' => str_repeat('a', 12)]],
]);

test('the recorded games ignore a game that has not started', function () {
    $state = rallies(scoreTo(served(), 20, 19), 'a'); // game 2 at 0–0

    expect($state->isRecordedAs([['a' => 21, 'b' => 19]]))->toBeTrue()
        ->and($state->isRecordedAs([['a' => 21, 'b' => 19], ['a' => 0, 'b' => 0]]))->toBeTrue()
        ->and($state->isRecordedAs([['a' => 21, 'b' => 18]]))->toBeFalse()
        ->and(LiveScore::start()->isRecordedAs([]))->toBeTrue()
        ->and(LiveScore::start()->isRecordedAs([['a' => 0, 'b' => 0]]))->toBeTrue();
});

test('the history lists every rally with the score after it', function () {
    $state = rallies(scoreTo(served(), 20, 19), 'a');
    $history = $state->history();

    expect($history)->toHaveCount(40)
        ->and(end($history))->toBe(['game' => 1, 'a' => 21, 'b' => 19, 'winner' => Side::A, 'event' => LiveScore::GAME])
        ->and($state->lastRally())->toBe(Side::A)
        ->and($history[0])->toBe(['game' => 1, 'a' => 1, 'b' => 0, 'winner' => Side::A, 'event' => null]);
});

test('the version changes with every rally and only with the scoresheet', function () {
    $state = served();

    expect($state->point(Side::A)->version())->not->toBe($state->version())
        ->and($state->point(Side::A)->version())->toBe($state->point(Side::A)->version())
        ->and($state->point(Side::A)->version())->not->toBe($state->point(Side::B)->version());
});
