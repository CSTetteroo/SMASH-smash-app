<?php

namespace App\Services\Rating;

use App\Enums\Side;

/**
 * One player's result in a completed, rated match, as read by RatedHistory.
 * $playedAt is the raw database value ("Y-m-d H:i:s"), so results compare without date parsing.
 */
final readonly class RatedResult
{
    public function __construct(
        public int $matchId,
        public ?string $playedAt,
        public Side $side,
        public bool $won,
        public ?float $ratingBefore,
        public ?float $ratingAfter,
    ) {}

    /**
     * Position in the rating replay order: undated matches first, then played_at, then id.
     *
     * @return array{0: int, 1: string, 2: int}
     */
    public function sortKey(): array
    {
        return RatedHistory::sortKey($this->playedAt, $this->matchId);
    }

    public function ratingChange(): ?float
    {
        return $this->ratingBefore === null || $this->ratingAfter === null
            ? null
            : $this->ratingAfter - $this->ratingBefore;
    }
}
