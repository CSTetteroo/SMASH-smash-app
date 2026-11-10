<?php

namespace App\Support\Badminton;

use App\Enums\Side;
use App\Models\MatchGame;

/**
 * Badminton rally-point scoring rules (BWF): every rally scores, a game is won by reaching the
 * target (21, or 15 / 11 for short formats) with a two-point lead; at 20–20 play continues until
 * one side leads by two, and at 29–29 the next point wins (cap 30; 15 => 21, 11 => 15).
 *
 * Pure functions, safe to call anywhere. A game is passed around as array{a: int, b: int}
 * (side A's points first).
 */
final class Scoring
{
    /**
     * The score at which the golden point decides the game: 21 => 30, 15 => 21, 11 => 15.
     */
    public static function cap(int $pointsPerGame): int
    {
        return match ($pointsPerGame) {
            21 => 30,
            15 => 21,
            11 => 15,
            default => $pointsPerGame + 9,
        };
    }

    /**
     * Whether a–b is a valid final score of a game to $target: reach the target with a two-point
     * lead, extend to a two-point lead beyond it, or win the golden point at the cap.
     */
    public static function isCompleteGame(int $a, int $b, int $target): bool
    {
        if ($a < 0 || $b < 0 || $target < 1) {
            return false;
        }

        $high = max($a, $b);
        $low = min($a, $b);
        $cap = self::cap($target);

        return ($high === $target && $low <= $target - 2)
            || ($high > $target && $high <= $cap && $high - $low === 2)
            || ($high === $cap && $low === $cap - 1);
    }

    /**
     * Whether a–b can occur during a game that is still going (0–0 included): below the target,
     * or level / one point apart past it, and below the cap.
     */
    public static function isValidInProgressGame(int $a, int $b, int $target): bool
    {
        if ($a < 0 || $b < 0 || $target < 1 || self::isCompleteGame($a, $b, $target)) {
            return false;
        }

        $high = max($a, $b);

        if ($high < $target) {
            return true;
        }

        return $high < self::cap($target) && $high - min($a, $b) <= 1;
    }

    /**
     * The side with more points (A on a level score, which never ends a game).
     */
    public static function gameWinner(int $a, int $b): Side
    {
        return $b > $a ? Side::B : Side::A;
    }

    /**
     * Games needed to win a best-of-$bestOf match: 1 => 1, 3 => 2, 5 => 3.
     */
    public static function gamesToWin(int $bestOf): int
    {
        return max(1, intdiv(max(1, $bestOf) + 1, 2));
    }

    /**
     * Check a sequence of games. Completed matches ($requireDecided): every game complete and valid,
     * the match decided exactly by the last game, no games after it. In-progress matches: every game
     * but the last complete, the last may still be going, and the match is not decided yet.
     *
     * @param  list<array{a: int, b: int}>  $games
     * @return list<string> human-readable problems (empty when the games are valid)
     */
    public static function validate(array $games, int $bestOf, int $target, bool $requireDecided): array
    {
        if ($bestOf < 1) {
            return ['A match is played over at least one game.'];
        }

        $errors = [];
        $count = count($games);
        $needed = self::gamesToWin($bestOf);

        if ($count > $bestOf) {
            $errors[] = sprintf('A best-of-%d match has at most %d %s.', $bestOf, $bestOf, $bestOf === 1 ? 'game' : 'games');
        }

        $won = [Side::A->value => 0, Side::B->value => 0];
        $decidedIn = null;

        $number = 0;

        foreach ($games as $game) {
            $number++;
            $a = $game['a'];
            $b = $game['b'];

            if ($decidedIn !== null) {
                $errors[] = sprintf('The match was already decided in game %d, so game %d cannot have been played.', $decidedIn, $number);

                break;
            }

            if ($a < 0 || $b < 0) {
                $errors[] = sprintf('Game %d cannot have a negative score.', $number);

                continue;
            }

            if (self::isCompleteGame($a, $b, $target)) {
                $winner = self::gameWinner($a, $b)->value;

                if (++$won[$winner] === $needed) {
                    $decidedIn = $number;
                }

                continue;
            }

            if (self::isValidInProgressGame($a, $b, $target)) {
                if (! $requireDecided && $number === $count) {
                    continue;
                }

                $errors[] = $requireDecided
                    ? sprintf('Game %d (%s) is not finished: %s.', $number, self::format($a, $b), self::finishRule($target))
                    : sprintf('Game %d (%s) is not finished; only the last game can still be in progress.', $number, self::format($a, $b));

                continue;
            }

            $errors[] = sprintf('Game %d (%s) is not a valid score for a game to %d: %s.', $number, self::format($a, $b), $target, self::finishRule($target));
        }

        if ($requireDecided) {
            if ($count === 0) {
                $errors[] = 'Enter the score of every game played.';
            } elseif ($decidedIn === null && $errors === []) {
                $errors[] = sprintf('The match is not decided yet: a side needs %d %s to win a best of %d.', $needed, $needed === 1 ? 'game' : 'games', $bestOf);
            }
        } elseif ($decidedIn !== null) {
            $errors[] = 'These games already decide the match, so it can no longer be in progress. Mark it as completed.';
        }

        return $errors;
    }

    /**
     * The first side to win enough games, or null while undecided. With $target, only complete
     * games count; without it every game counts for the side with more points (level games for nobody).
     *
     * @param  list<array{a: int, b: int}>  $games
     */
    public static function matchWinner(array $games, int $bestOf, ?int $target = null): ?Side
    {
        $needed = self::gamesToWin($bestOf);
        $won = [Side::A->value => 0, Side::B->value => 0];

        foreach ($games as $game) {
            if ($game['a'] === $game['b']) {
                continue;
            }

            if ($target !== null && ! self::isCompleteGame($game['a'], $game['b'], $target)) {
                continue;
            }

            $winner = self::gameWinner($game['a'], $game['b']);

            if (++$won[$winner->value] === $needed) {
                return $winner;
            }
        }

        return null;
    }

    /**
     * Complete games won by each side.
     *
     * @param  list<array{a: int, b: int}>  $games
     * @return array{a: int, b: int}
     */
    public static function tally(array $games, int $target): array
    {
        $won = ['a' => 0, 'b' => 0];

        foreach ($games as $game) {
            if (self::isCompleteGame($game['a'], $game['b'], $target)) {
                $won[self::gameWinner($game['a'], $game['b'])->value]++;
            }
        }

        return $won;
    }

    /**
     * Stored games as arrays for validate()/matchWinner() (playing order).
     *
     * @param  iterable<MatchGame>  $games
     * @return list<array{a: int, b: int}>
     */
    public static function fromModels(iterable $games): array
    {
        $list = [];

        foreach ($games as $game) {
            $list[$game->number] = ['a' => $game->side_a_points, 'b' => $game->side_b_points];
        }

        ksort($list);

        return array_values($list);
    }

    /**
     * "21–19" (en dash, side A first).
     */
    public static function format(int $a, int $b): string
    {
        return $a.'–'.$b;
    }

    private static function finishRule(int $target): string
    {
        $cap = self::cap($target);

        return sprintf(
            'a game is won at %d with a two-point lead, past %d–%d by two clear points, or %d–%d at the cap',
            $target, $target - 1, $target - 1, $cap, $cap - 1,
        );
    }
}
