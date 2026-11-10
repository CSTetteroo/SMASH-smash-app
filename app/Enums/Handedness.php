<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum Handedness: string
{
    use HasOptions;

    case Right = 'right';
    case Left = 'left';

    public function label(): string
    {
        return match ($this) {
            self::Right => 'Right-handed',
            self::Left => 'Left-handed',
        };
    }
}
