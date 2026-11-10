<?php

namespace Database\Seeders\Demo;

use App\Enums\Side;
use App\Support\Badminton\Probability;
use App\Support\Badminton\Scoring;
use RuntimeException;

/**
 * Plays matches rally by rally, so scores look like real badminton (21–17, 23–21, the odd
 * 30–29, deciders, upsets). The chance that side A wins a rally is solved from the match win
 * probability with the same rally model the predictor uses, so results follow the hidden skills.
 */
final class MatchSimulator
{
    /**
     * Swing of the rally win chance from game to game, and for a whole match (a good or bad day).
     */
    private const GAME_SWING = 0.006;

    private const MATCH_SWING = 0.015;

    /**
     * Momentum: winning a game lifts the winner's rally chance in the next one, so matches go
     * to a decider about as often as real club matches do (instead of half the time).
     */
    private const MOMENTUM = 0.018;

    private const ATTEMPTS = 600;

    /**
     * @var array<string, float>
     */
    private array $rallyCache = [];

    public function __construct(private readonly Rng $rng) {}

    /**
     * Elo win expectancy of a side with skill $a against a side with skill $b.
     */
    public static function expected(float $a, float $b, float $scale = 400.0): float
    {
        return 1.0 / (1.0 + 10 ** (($b - $a) / $scale));
    }

    /**
     * The rally win chance for side A that gives it $matchProbability over the format.
     */
    public function rallyProbability(float $matchProbability, int $bestOf, int $target): float
    {
        $probability = round(max(0.002, min(0.998, $matchProbability)), 3);
        $key = $probability.'|'.$bestOf.'|'.$target;

        return $this->rallyCache[$key] ??= Probability::rallyFromGame(
            Probability::gameFromMatch($probability, $bestOf),
            $target,
        );
    }

    /**
     * A complete match. $winner forces the result (an underdog's day); $accept can demand more
     * (a decider, a game past 21, …). Both are met by replaying the match, so the scores stay natural.
     *
     * @param  (callable(list<array{a: int, b: int}>): bool)|null  $accept
     * @return list<array{a: int, b: int}>
     */
    public function play(float $probabilityA, int $bestOf = 3, int $target = 21, ?Side $winner = null, ?callable $accept = null): array
    {
        $rally = $this->rallyProbability($probabilityA, $bestOf, $target);

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            // After many misses, lean the rallies towards the scripted winner.
            $tilt = $winner === null ? 0.0 : min(0.12, intdiv($attempt, 40) * 0.015);
            $games = $this->match($winner === Side::B ? $rally - $tilt : $rally + $tilt, $bestOf, $target);

            if ($winner !== null && Scoring::matchWinner($games, $bestOf, $target) !== $winner) {
                continue;
            }

            if ($accept !== null && ! $accept($games)) {
                continue;
            }

            return $games;
        }

        throw new RuntimeException('The demo seeder could not simulate a scripted result.');
    }

    /**
     * @return list<array{a: int, b: int}>
     */
    public function match(float $rally, int $bestOf, int $target): array
    {
        $needed = Scoring::gamesToWin($bestOf);
        $won = ['a' => 0, 'b' => 0];
        $games = [];
        $rally += $this->rng->gaussian(0.0, self::MATCH_SWING);

        while ($won['a'] < $needed && $won['b'] < $needed) {
            $swing = $this->rng->gaussian(0.0, self::GAME_SWING);
            $game = $this->game(max(0.25, min(0.75, $rally + $swing)), $target);
            $games[] = $game;
            $aWon = $game['a'] > $game['b'];
            $won[$aWon ? 'a' : 'b']++;
            $rally += $aWon ? self::MOMENTUM : -self::MOMENTUM;
        }

        return $games;
    }

    /**
     * @return array{a: int, b: int}
     */
    public function game(float $rally, int $target): array
    {
        $a = 0;
        $b = 0;

        while (! Scoring::isCompleteGame($a, $b, $target)) {
            if ($this->rng->float() < $rally) {
                $a++;
            } else {
                $b++;
            }
        }

        return ['a' => $a, 'b' => $b];
    }

    /**
     * A game stopped after about $rallies rallies, still in progress (never a finished score).
     *
     * @return array{a: int, b: int}
     */
    public function partialGame(float $rally, int $target, int $rallies): array
    {
        $a = 0;
        $b = 0;

        for ($i = 0; $i < $rallies; $i++) {
            [$nextA, $nextB] = $this->rng->float() < $rally ? [$a + 1, $b] : [$a, $b + 1];

            if (Scoring::isCompleteGame($nextA, $nextB, $target)) {
                break;
            }

            [$a, $b] = [$nextA, $nextB];
        }

        return ['a' => $a, 'b' => $b];
    }

    /**
     * Minutes on court for these games (about 25 seconds per rally plus changeovers).
     *
     * @param  list<array{a: int, b: int}>  $games
     */
    public static function minutes(array $games): int
    {
        $rallies = 0;

        foreach ($games as $game) {
            $rallies += $game['a'] + $game['b'];
        }

        return (int) round($rallies * 0.4) + 2 * count($games);
    }

    /**
     * Swap the sides of a list of games (B's view as A's).
     *
     * @param  list<array{a: int, b: int}>  $games
     * @return list<array{a: int, b: int}>
     */
    public static function flip(array $games): array
    {
        return array_map(fn (array $game): array => ['a' => $game['b'], 'b' => $game['a']], $games);
    }
}
