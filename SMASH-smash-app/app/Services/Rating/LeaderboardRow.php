<?php

namespace App\Services\Rating;

use App\Models\User;

/**
 * One line of the rankings table.
 */
final readonly class LeaderboardRow
{
    /**
     * @param  int  $position  1..n over the whole ranking (filters keep the real position)
     * @param  int  $rating  current rating, rounded
     * @param  float|null  $winRate  0–1, null without matches
     * @param  list<'W'|'L'>  $form  last five rated results, most recent first
     * @param  float  $change30d  rating change over the last 30 days
     * @param  int|null  $movement  places gained (+) or lost (−) compared with 30 days ago; null when not ranked then
     * @param  bool  $provisional  fewer than config('smash.rating.provisional_matches') rated matches
     * @param  int  $peak  highest rating reached, rounded
     */
    public function __construct(
        public int $position,
        public User $user,
        public int $rating,
        public int $played,
        public int $wins,
        public int $losses,
        public ?float $winRate,
        public array $form,
        public float $change30d,
        public ?int $movement,
        public bool $provisional,
        public int $peak,
    ) {}
}
