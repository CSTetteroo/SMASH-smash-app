<?php

use App\Actions\Matches\SaveMatch;
use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Models\BadmintonMatch;
use App\Models\User;

/**
 * A completed friendly, saved through the domain action so ratings are recalculated.
 *
 * @param  list<User>  $sideA
 * @param  list<User>  $sideB
 * @param  list<array{0: int, 1: int}>  $games
 * @param  array<string, mixed>  $extra
 */
function homePlay(array $sideA, array $sideB, array $games = [[21, 15], [21, 17]], array $extra = []): BadmintonMatch
{
    return SaveMatch::handle([
        'discipline' => count($sideA) > 1 ? Discipline::Doubles : Discipline::Singles,
        'side_a' => array_map(fn (User $user): int => $user->id, $sideA),
        'side_b' => array_map(fn (User $user): int => $user->id, $sideB),
        'status' => MatchStatus::Completed,
        'games' => array_map(fn (array $game): array => ['a' => $game[0], 'b' => $game[1]], $games),
        ...$extra,
    ]);
}

/**
 * A scheduled friendly between two singles players.
 */
function homeFixture(User $a, User $b, string $when = '+2 days'): BadmintonMatch
{
    return SaveMatch::handle([
        'discipline' => Discipline::Singles,
        'side_a' => [$a->id],
        'side_b' => [$b->id],
        'status' => MatchStatus::Scheduled,
        'scheduled_at' => now()->modify($when)->setTime(20, 30),
    ]);
}
