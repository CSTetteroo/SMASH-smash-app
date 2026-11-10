<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum SkillLevel: string
{
    use HasOptions;

    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';
    case Competitive = 'competitive';

    public function label(): string
    {
        return match ($this) {
            self::Beginner => 'Beginner',
            self::Intermediate => 'Intermediate',
            self::Advanced => 'Advanced',
            self::Competitive => 'Competitive',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Beginner => 'New to badminton or still learning the basic strokes and rules.',
            self::Intermediate => 'Comfortable in rallies and with the rules; working on consistency and placement.',
            self::Advanced => 'Solid technique and tactics, plays regularly and enjoys fast, long rallies.',
            self::Competitive => 'Plays or has played league or tournament badminton.',
        };
    }
}
