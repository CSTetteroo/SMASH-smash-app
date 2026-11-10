<?php

namespace Tests\Feature\Domain\Support;

use App\Actions\Matches\SaveMatch;
use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Models\BadmintonMatch;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Records results through SaveMatch for the domain tests, with an increasing played_at clock
 * so that the rating replay order is the order the test plays the matches in.
 */
final class Play
{
    private static ?CarbonImmutable $clock = null;

    /**
     * Restart the clock (the first match is played at $start, then one hour apart).
     */
    public static function reset(string $start = '2026-09-01 19:00:00'): void
    {
        self::$clock = CarbonImmutable::parse($start);
    }

    public static function nextTime(): CarbonImmutable
    {
        self::$clock = (self::$clock ?? CarbonImmutable::parse('2026-09-01 19:00:00'))->addHour();

        return self::$clock;
    }

    /**
     * A completed friendly (singles for one player per side, doubles for two).
     *
     * @param  User|list<User>  $sideA
     * @param  User|list<User>  $sideB
     * @param  list<array{0: int, 1: int}>  $games  side A's points first
     * @param  array<string, mixed>  $overrides  extra SaveMatch data
     */
    public static function match(User|array $sideA, User|array $sideB, array $games = [[21, 15], [21, 15]], array $overrides = []): BadmintonMatch
    {
        $sideA = is_array($sideA) ? $sideA : [$sideA];
        $sideB = is_array($sideB) ? $sideB : [$sideB];

        return SaveMatch::handle([
            'discipline' => count($sideA) > 1 ? Discipline::Doubles : Discipline::Singles,
            'side_a' => array_map(fn (User $user): int => $user->id, $sideA),
            'side_b' => array_map(fn (User $user): int => $user->id, $sideB),
            'status' => MatchStatus::Completed,
            'played_at' => self::nextTime(),
            'games' => self::games($games),
            ...$overrides,
        ]);
    }

    /**
     * Side A wins in straight games.
     *
     * @param  User|list<User>  $winner
     * @param  User|list<User>  $loser
     * @param  array<string, mixed>  $overrides
     */
    public static function win(User|array $winner, User|array $loser, array $overrides = []): BadmintonMatch
    {
        return self::match($winner, $loser, [[21, 15], [21, 15]], $overrides);
    }

    /**
     * @param  list<array{0: int, 1: int}>  $games
     * @return list<array{a: int, b: int}>
     */
    public static function games(array $games): array
    {
        return array_map(fn (array $game): array => ['a' => $game[0], 'b' => $game[1]], $games);
    }
}
