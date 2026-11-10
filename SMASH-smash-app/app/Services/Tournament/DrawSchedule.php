<?php

namespace App\Services\Tournament;

use App\Support\SmashConfig;
use Carbon\CarbonImmutable;

/**
 * Start times and courts for a draw: matches fill courts 1..n (config smash.club.courts) per time
 * slot, and every round (matchday) starts in a new slot. A slot lasts about ten minutes per game
 * to 21 (best of 3 => 30 minutes).
 *
 * @internal used by DrawGenerator
 */
final class DrawSchedule
{
    private int $slot = 0;

    private int $court = 0;

    private readonly int $courts;

    private readonly int $minutes;

    public function __construct(private readonly CarbonImmutable $start, int $bestOf, int $pointsPerGame)
    {
        $this->courts = max(1, SmashConfig::int('club.courts', 4));
        $this->minutes = (int) max(10, round($bestOf * 10 * $pointsPerGame / 21 / 5) * 5);
    }

    /**
     * The next free court: [start time, court number].
     *
     * @return array{0: CarbonImmutable, 1: int}
     */
    public function next(): array
    {
        if ($this->court >= $this->courts) {
            $this->slot++;
            $this->court = 0;
        }

        $this->court++;

        return [$this->start->addMinutes($this->slot * $this->minutes), $this->court];
    }

    /**
     * Start the next round in a fresh time slot.
     */
    public function nextRound(): void
    {
        if ($this->court > 0) {
            $this->slot++;
            $this->court = 0;
        }
    }

    /**
     * Minutes per slot.
     */
    public function slotMinutes(): int
    {
        return $this->minutes;
    }
}
