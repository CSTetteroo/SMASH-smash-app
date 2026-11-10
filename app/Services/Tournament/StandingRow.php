<?php

namespace App\Services\Tournament;

use App\Models\TournamentEntry;

/**
 * One line of a tournament table. Walkovers count as a win / loss without games or points.
 */
final readonly class StandingRow
{
    public function __construct(
        public int $position,
        public TournamentEntry $entry,
        public int $played,
        public int $won,
        public int $lost,
        public int $gamesFor,
        public int $gamesAgainst,
        public int $pointsFor,
        public int $pointsAgainst,
    ) {}

    public function gameDifference(): int
    {
        return $this->gamesFor - $this->gamesAgainst;
    }

    public function pointDifference(): int
    {
        return $this->pointsFor - $this->pointsAgainst;
    }
}
