<?php

namespace App\Services\Stats;

use Carbon\CarbonImmutable;

/**
 * A badge on a player's profile.
 */
final readonly class Achievement
{
    /**
     * @param  string  $icon  Heroicon name available in Flux (e.g. "trophy")
     * @param  string  $tone  navy|orange|green|smash|sky|grey
     * @param  CarbonImmutable|null  $earnedAt  when it was first earned (null when not earned, or unknown)
     * @param  array{current: int, target: int}|null  $progress  for count-based badges (current is capped at target)
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public string $icon,
        public string $tone,
        public bool $earned,
        public ?CarbonImmutable $earnedAt,
        public ?array $progress,
    ) {}

    /**
     * Progress as a share of the target (0–1), or null for one-off badges.
     */
    public function ratio(): ?float
    {
        if ($this->progress === null || $this->progress['target'] <= 0) {
            return null;
        }

        return min(1.0, $this->progress['current'] / $this->progress['target']);
    }
}
