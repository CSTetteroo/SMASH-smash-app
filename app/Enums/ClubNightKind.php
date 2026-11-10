<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ClubNightKind: string
{
    use HasOptions;

    case Social = 'social';
    case Training = 'training';
    case Clinic = 'clinic';
    case Ladder = 'ladder';

    public function label(): string
    {
        return match ($this) {
            self::Social => 'Social play',
            self::Training => 'Training',
            self::Clinic => 'Clinic',
            self::Ladder => 'Ladder night',
        };
    }

    /**
     * UI tone: navy|orange|green|smash|sky|grey.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Social => 'sky',
            self::Training => 'navy',
            self::Clinic => 'green',
            self::Ladder => 'orange',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Social => 'Open play for every level. Rotate on and off court, mix up partners and meet new people.',
            self::Training => 'Coached drills and condition games focused on footwork, technique and tactics.',
            self::Clinic => 'A relaxed introduction for beginners: grip, serve, the basic strokes and how scoring works.',
            self::Ladder => 'Short challenge matches against players near you in the rankings. Log your results to climb.',
        };
    }
}
