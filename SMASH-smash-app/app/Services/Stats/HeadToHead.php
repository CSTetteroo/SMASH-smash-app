<?php

namespace App\Services\Stats;

use App\Models\BadmintonMatch;
use Illuminate\Database\Eloquent\Collection;

/**
 * Completed matches in which player A and player B were on opposite sides of the net
 * (singles, and doubles with any partners), from A's and B's point of view.
 */
final readonly class HeadToHead
{
    /**
     * @param  Collection<int, BadmintonMatch>  $matches  newest first, with details loaded
     */
    public function __construct(
        public Collection $matches,
        public int $winsA,
        public int $winsB,
        public int $gamesA,
        public int $gamesB,
        public int $pointsA,
        public int $pointsB,
        public ?BadmintonMatch $lastMeeting,
    ) {}

    public function meetings(): int
    {
        return $this->matches->count();
    }
}
