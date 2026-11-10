<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum TournamentStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Open = 'open';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Open => 'Registration open',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
        };
    }

    /**
     * UI tone: navy|orange|green|smash|sky|grey.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'grey',
            self::Open => 'green',
            self::InProgress => 'orange',
            self::Completed => 'navy',
        };
    }
}
