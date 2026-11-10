<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum TournamentFormat: string
{
    use HasOptions;

    case SingleElimination = 'single_elimination';
    case RoundRobin = 'round_robin';

    public function label(): string
    {
        return match ($this) {
            self::SingleElimination => 'Knockout',
            self::RoundRobin => 'Round robin',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SingleElimination => 'Single elimination: win and you move on, lose and you are out. Top seeds may get a bye.',
            self::RoundRobin => 'Everyone plays everyone. The table decides the champion.',
        };
    }
}
