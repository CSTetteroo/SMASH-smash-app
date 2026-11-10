<?php

namespace App\Services\Predictions;

use App\Models\User;

/**
 * One line of the pick'em leaderboard.
 */
final readonly class PredictorRow
{
    /**
     * @param  int  $predictions  picks made (any match state)
     * @param  int  $settled  picks on completed matches (void walkovers / cancelled matches excluded)
     * @param  int  $correct  right winner
     * @param  int  $exact  right winner and right number of games
     * @param  int  $points  3 per right winner, +2 when exact
     * @param  float|null  $accuracy  correct / settled (0–1), null before anything settled
     */
    public function __construct(
        public int $position,
        public User $user,
        public int $predictions,
        public int $settled,
        public int $correct,
        public int $exact,
        public int $points,
        public ?float $accuracy,
    ) {}
}
