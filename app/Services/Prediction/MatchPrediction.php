<?php

namespace App\Services\Prediction;

use App\Enums\Side;

/**
 * The predictor's view of a match, side A's point of view unless stated otherwise.
 */
final readonly class MatchPrediction
{
    /**
     * @param  float  $probabilityA  chance side A wins the match (form and head-to-head included)
     * @param  float  $eloProbabilityA  chance from the rating gap alone
     * @param  float  $ratingA  side A's strength: rating, or the mean of both partners in doubles
     * @param  Side|null  $favourite  null when it rounds to 50 %
     * @param  string  $confidence  "Toss-up" (< 55 %), "Slight edge" (< 65 %), "Favourite" (< 80 %), "Strong favourite"
     * @param  list<array{key: string, label: string, detail: string, shift: float}>  $factors  what moved the needle, in Elo points for A
     * @param  float  $gameProbabilityA  chance side A wins any one game
     * @param  float  $rallyProbabilityA  chance side A wins any one rally
     * @param  array<string, float>  $scoreDistribution  e.g. ['2–0' => …, '2–1' => …, '1–2' => …, '0–2' => …], sums to 1
     * @param  array{0: int, 1: int}  $expectedGameScore  the favourite's typical winning game, e.g. [21, 17]
     * @param  array{a_wins: array<int, float>, b_wins: array<int, float>}  $stakes  rating change per user id for each outcome
     * @param  float  $effectiveDiff  rating gap plus form and head-to-head shifts (Elo points for A)
     */
    public function __construct(
        public float $probabilityA,
        public float $probabilityB,
        public float $eloProbabilityA,
        public float $ratingA,
        public float $ratingB,
        public ?Side $favourite,
        public string $confidence,
        public array $factors,
        public float $gameProbabilityA,
        public float $rallyProbabilityA,
        public array $scoreDistribution,
        public array $expectedGameScore,
        public array $stakes,
        public float $effectiveDiff = 0.0,
        public int $bestOf = 3,
        public int $pointsPerGame = 21,
    ) {}

    public function probabilityFor(Side $side): float
    {
        return $side === Side::A ? $this->probabilityA : $this->probabilityB;
    }

    /**
     * Whole percentages that always add up to 100 (A rounded, B the rest).
     */
    public function percentFor(Side $side): int
    {
        $percentA = (int) round($this->probabilityA * 100);

        return $side === Side::A ? $percentA : 100 - $percentA;
    }

    /**
     * Rating change for a player if $winner wins (null when the player is not in this match).
     */
    public function stakeFor(int $userId, Side $winner): ?float
    {
        return $this->stakes[$winner === Side::A ? 'a_wins' : 'b_wins'][$userId] ?? null;
    }

    /**
     * A factor by key ("rating", "form", "head_to_head", "provisional"), if present.
     *
     * @return array{key: string, label: string, detail: string, shift: float}|null
     */
    public function factor(string $key): ?array
    {
        foreach ($this->factors as $factor) {
            if ($factor['key'] === $key) {
                return $factor;
            }
        }

        return null;
    }
}
