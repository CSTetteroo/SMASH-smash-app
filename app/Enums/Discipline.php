<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum Discipline: string
{
    use HasOptions;

    case Singles = 'singles';
    case Doubles = 'doubles';

    public function label(): string
    {
        return match ($this) {
            self::Singles => 'Singles',
            self::Doubles => 'Doubles',
        };
    }

    /**
     * Number of players on each side of the net.
     */
    public function playersPerSide(): int
    {
        return match ($this) {
            self::Singles => 1,
            self::Doubles => 2,
        };
    }

    /**
     * Heroicon name for this discipline (available in Flux).
     */
    public function icon(): string
    {
        return match ($this) {
            self::Singles => 'user',
            self::Doubles => 'user-group',
        };
    }

    /**
     * Column on `users` holding the current rating, e.g. "singles_rating".
     */
    public function ratingColumn(): string
    {
        return $this->value.'_rating';
    }

    /**
     * Column on `users` holding the highest rating ever reached, e.g. "singles_peak".
     */
    public function peakColumn(): string
    {
        return $this->value.'_peak';
    }

    /**
     * Column on `users` holding the number of rated matches played, e.g. "singles_played".
     */
    public function playedColumn(): string
    {
        return $this->value.'_played';
    }

    /**
     * Column on `users` holding the number of rated matches won, e.g. "singles_wins".
     */
    public function winsColumn(): string
    {
        return $this->value.'_wins';
    }
}
