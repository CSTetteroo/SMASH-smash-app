<?php

namespace App\Support\Badminton;

use App\Enums\Side;

/**
 * Win probabilities for badminton's nested structure: rallies make games, games make matches.
 *
 * Rallies are modelled as independent with probability r for side A (rally scoring, no serve
 * advantage). The game probability comes from a dynamic programme over every reachable score,
 * including the win-by-two extension and the golden point at the cap. Inverses are solved by
 * bisection, which is exact to float precision because every map here is strictly increasing.
 * Pure functions; side A's point of view throughout.
 */
final class Probability
{
    private const BISECTION_STEPS = 60;

    /**
     * Chance side A wins a best-of-$bestOf match when it wins each game with probability $p.
     */
    public static function matchFromGame(float $p, int $bestOf): float
    {
        $p = self::clamp($p);
        $q = 1.0 - $p;
        $needed = Scoring::gamesToWin($bestOf);
        $total = 0.0;

        for ($lost = 0; $lost < $needed; $lost++) {
            $total += self::binomial($needed - 1 + $lost, $lost) * ($p ** $needed) * ($q ** $lost);
        }

        return self::clamp($total);
    }

    /**
     * The per-game probability that gives side A $matchProbability over a best of $bestOf (bisection).
     */
    public static function gameFromMatch(float $matchProbability, int $bestOf): float
    {
        return self::solve(fn (float $p): float => self::matchFromGame($p, $bestOf), $matchProbability);
    }

    /**
     * Chance side A wins a game to $target when it wins each rally with probability $r.
     */
    public static function gameFromRally(float $r, int $target): float
    {
        return self::rallyGame($r, $target, false)['win'];
    }

    /**
     * The per-rally probability that gives side A $gameProbability in a game to $target (bisection).
     */
    public static function rallyFromGame(float $gameProbability, int $target): float
    {
        return self::solve(fn (float $r): float => self::gameFromRally($r, $target), $gameProbability);
    }

    /**
     * Match results from side A's point of view with their probabilities, A's wins first:
     * best of 3 => ['2–0' => …, '2–1' => …, '1–2' => …, '0–2' => …]. Sums to 1.
     *
     * @return array<string, float>
     */
    public static function scoreDistribution(float $p, int $bestOf): array
    {
        $p = self::clamp($p);
        $q = 1.0 - $p;
        $needed = Scoring::gamesToWin($bestOf);
        $distribution = [];

        for ($lost = 0; $lost < $needed; $lost++) {
            $distribution[$needed.'–'.$lost] = self::binomial($needed - 1 + $lost, $lost) * ($p ** $needed) * ($q ** $lost);
        }

        for ($won = $needed - 1; $won >= 0; $won--) {
            $distribution[$won.'–'.$needed] = self::binomial($needed - 1 + $won, $won) * ($q ** $needed) * ($p ** $won);
        }

        $total = array_sum($distribution);

        if ($total <= 0.0) {
            return $distribution;
        }

        return array_map(fn (float $probability): float => $probability / $total, $distribution);
    }

    /**
     * Every possible final score of a game to $target with its probability (sums to 1).
     *
     * @return list<array{a: int, b: int, probability: float}>
     */
    public static function finalScores(float $r, int $target): array
    {
        return self::rallyGame($r, $target, true)['scores'];
    }

    /**
     * The typical game won by $winner, as [winner's points, loser's points], e.g. [21, 17]: the
     * loser's expected points given that $winner takes the game, mapped onto a valid final score.
     *
     * @return array{0: int, 1: int}
     */
    public static function typicalWinningScore(float $r, int $target, Side $winner): array
    {
        $mass = 0.0;
        $loserPoints = 0.0;

        foreach (self::finalScores($r, $target) as $score) {
            [$winnerScore, $loserScore] = $winner === Side::A
                ? [$score['a'], $score['b']]
                : [$score['b'], $score['a']];

            if ($winnerScore <= $loserScore) {
                continue;
            }

            $mass += $score['probability'];
            $loserPoints += $score['probability'] * $loserScore;
        }

        if ($mass <= 0.0) {
            return [$target, 0];
        }

        $cap = Scoring::cap($target);
        $loser = (int) round($loserPoints / $mass);

        if ($loser <= $target - 2) {
            return [$target, max(0, $loser)];
        }

        $loser = min($loser, $cap - 1);

        return [min($loser + 2, $cap), $loser];
    }

    /**
     * Chance side A wins the current game from a score of $a–$b, winning each rally with
     * probability $r. Finished scores give 1 or 0; an impossible score counts as 0–0.
     */
    public static function gameFromScore(float $r, int $target, int $a, int $b): float
    {
        if (Scoring::isCompleteGame($a, $b, $target)) {
            return $a > $b ? 1.0 : 0.0;
        }

        if (! Scoring::isValidInProgressGame($a, $b, $target)) {
            return self::gameFromRally($r, $target);
        }

        $r = self::clamp($r);
        $memo = [];

        $win = function (int $a, int $b) use (&$win, &$memo, $r, $target): float {
            if (Scoring::isCompleteGame($a, $b, $target)) {
                return $a > $b ? 1.0 : 0.0;
            }

            return $memo[$a * 100 + $b] ??= $r * $win($a + 1, $b) + (1.0 - $r) * $win($a, $b + 1);
        };

        return self::clamp($win($a, $b));
    }

    /**
     * Live chance side A wins the match: $gamesA–$gamesB in completed games, the current game at
     * $pointsA–$pointsB (0–0 between games), rallies won with probability $r.
     */
    public static function matchFromScore(float $r, int $bestOf, int $target, int $gamesA, int $gamesB, int $pointsA = 0, int $pointsB = 0): float
    {
        $needed = Scoring::gamesToWin($bestOf);

        if ($gamesA >= $needed || $gamesB >= $needed) {
            return $gamesA >= $needed ? 1.0 : 0.0;
        }

        $game = self::gameFromRally($r, $target);
        $current = self::gameFromScore($r, $target, $pointsA, $pointsB);

        $rest = function (int $a, int $b) use (&$rest, $game, $needed): float {
            if ($a >= $needed || $b >= $needed) {
                return $a >= $needed ? 1.0 : 0.0;
            }

            return $game * $rest($a + 1, $b) + (1.0 - $game) * $rest($a, $b + 1);
        };

        return self::clamp($current * $rest($gamesA + 1, $gamesB) + (1.0 - $current) * $rest($gamesA, $gamesB + 1));
    }

    /**
     * Binomial coefficient as a float (n choose k).
     */
    public static function binomial(int $n, int $k): float
    {
        if ($k < 0 || $k > $n) {
            return 0.0;
        }

        $k = min($k, $n - $k);
        $result = 1.0;

        for ($i = 1; $i <= $k; $i++) {
            $result = $result * ($n - $k + $i) / $i;
        }

        return $result;
    }

    /**
     * Dynamic programme over the scores of one game. Below target−1 on both sides every score is
     * reachable; from (target−1, target−1) two clear points are needed, and at cap−1 all the next
     * rally wins.
     *
     * @return array{win: float, scores: list<array{a: int, b: int, probability: float}>}
     */
    private static function rallyGame(float $r, int $target, bool $withScores): array
    {
        $r = self::clamp($r);
        $q = 1.0 - $r;
        $size = max(1, $target);
        $cap = Scoring::cap($size);
        $last = $size - 1;

        $states = array_fill(0, $size * $size, 0.0);
        $states[0] = 1.0;
        $win = 0.0;
        $lose = 0.0;
        $scores = [];

        for ($a = 0; $a < $size; $a++) {
            for ($b = 0; $b < $size; $b++) {
                $reach = $states[$a * $size + $b];

                if ($reach <= 0.0 || ($a === $last && $b === $last)) {
                    continue;
                }

                if ($a === $last) {
                    $win += $reach * $r;

                    if ($withScores) {
                        $scores[] = ['a' => $size, 'b' => $b, 'probability' => $reach * $r];
                    }
                } else {
                    $states[($a + 1) * $size + $b] += $reach * $r;
                }

                if ($b === $last) {
                    $lose += $reach * $q;

                    if ($withScores) {
                        $scores[] = ['a' => $a, 'b' => $size, 'probability' => $reach * $q];
                    }
                } else {
                    $states[$a * $size + $b + 1] += $reach * $q;
                }
            }
        }

        $level = $states[$last * $size + $last];

        for ($points = $last; $points < $cap - 1; $points++) {
            $win += $level * $r * $r;
            $lose += $level * $q * $q;

            if ($withScores) {
                $scores[] = ['a' => $points + 2, 'b' => $points, 'probability' => $level * $r * $r];
                $scores[] = ['a' => $points, 'b' => $points + 2, 'probability' => $level * $q * $q];
            }

            $level *= 2.0 * $r * $q;
        }

        $win += $level * $r;
        $lose += $level * $q;

        if ($withScores) {
            $scores[] = ['a' => $cap, 'b' => $cap - 1, 'probability' => $level * $r];
            $scores[] = ['a' => $cap - 1, 'b' => $cap, 'probability' => $level * $q];
        }

        // Both outcomes are summed and normalised, which removes rounding drift (and keeps
        // P(r) + P(1 − r) = 1 to the last bit).
        $total = $win + $lose;

        return ['win' => $total > 0.0 ? self::clamp($win / $total) : 0.5, 'scores' => $scores];
    }

    /**
     * Invert a strictly increasing map [0, 1] => [0, 1] by bisection.
     *
     * @param  callable(float): float  $function
     */
    private static function solve(callable $function, float $target): float
    {
        $target = self::clamp($target);

        if ($target <= 0.0 || $target >= 1.0) {
            return $target;
        }

        $low = 0.0;
        $high = 1.0;

        for ($step = 0; $step < self::BISECTION_STEPS; $step++) {
            $middle = ($low + $high) / 2;

            if ($function($middle) < $target) {
                $low = $middle;
            } else {
                $high = $middle;
            }
        }

        return ($low + $high) / 2;
    }

    private static function clamp(float $probability): float
    {
        return max(0.0, min(1.0, $probability));
    }
}
