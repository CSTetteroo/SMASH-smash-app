<?php

namespace App\Services\Rating;

use App\Enums\Side;
use App\Support\SmashConfig;

/**
 * The Elo maths behind SMASH ratings (tunables in config/smash.php under "rating").
 *
 * change = K × margin × (score − expected), where K is 40 for provisional players (fewer than
 * 10 rated matches) and 24 after that, ×1.25 in tournaments; margin rewards straight-game wins
 * (×1.10), softens deciders (×0.90) and adds up to +20 % for a big point difference.
 */
final class EloCalculator
{
    /**
     * Point difference at which the point bonus is maxed out.
     */
    public const POINT_DIFF_CAP = 30;

    /**
     * Expected score (win probability) of a side rated $ratingA against one rated $ratingB.
     */
    public static function expected(float $ratingA, float $ratingB): float
    {
        $scale = SmashConfig::float('rating.scale', 400.0);

        return 1.0 / (1.0 + 10 ** (($ratingB - $ratingA) / $scale));
    }

    /**
     * K-factor for a player with $played rated matches so far in the discipline.
     */
    public static function kFactor(int $played, bool $tournament): float
    {
        $k = $played < SmashConfig::int('rating.provisional_matches', 10)
            ? SmashConfig::float('rating.k_provisional', 40.0)
            : SmashConfig::float('rating.k_established', 24.0);

        return $tournament ? $k * SmashConfig::float('rating.tournament_multiplier', 1.25) : $k;
    }

    /**
     * Result-margin multiplier: straight games ×1.10, a decider ×0.90 (best of 1: ×1.0), then
     * × (1 + 0.20 · min(max(pointDiff, 0), 30) / 30).
     */
    public static function marginMultiplier(int $winnerGames, int $loserGames, int $pointDiff, int $bestOf): float
    {
        $format = 1.0;

        if ($bestOf > 1) {
            if ($loserGames === 0) {
                $format = 1.0 + SmashConfig::float('rating.straight_games_bonus', 0.10);
            } elseif ($winnerGames + $loserGames >= $bestOf) {
                $format = SmashConfig::float('rating.decider_factor', 0.90);
            }
        }

        $bonus = SmashConfig::float('rating.max_point_bonus', 0.20)
            * min(max($pointDiff, 0), self::POINT_DIFF_CAP) / self::POINT_DIFF_CAP;

        return $format * (1.0 + $bonus);
    }

    /**
     * Rating change for side A (side B's change with the same K is the negative).
     */
    public static function delta(float $teamA, float $teamB, Side $winner, float $k, float $margin): float
    {
        $score = $winner === Side::A ? 1.0 : 0.0;

        return $k * $margin * ($score - self::expected($teamA, $teamB));
    }
}
