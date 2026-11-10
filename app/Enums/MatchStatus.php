<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum MatchStatus: string
{
    use HasOptions;

    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Walkover = 'walkover';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::InProgress => 'Live',
            self::Completed => 'Completed',
            self::Walkover => 'Walkover',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * A finished match has a result (a winner): completed or walkover.
     */
    public function isFinished(): bool
    {
        return $this === self::Completed || $this === self::Walkover;
    }

    /**
     * UI tone: navy|orange|green|smash|sky|grey.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Scheduled => 'sky',
            self::InProgress => 'smash',
            self::Completed => 'navy',
            self::Walkover => 'orange',
            self::Cancelled => 'grey',
        };
    }
}
