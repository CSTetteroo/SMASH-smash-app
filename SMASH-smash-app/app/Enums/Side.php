<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum Side: string
{
    use HasOptions;

    case A = 'a';
    case B = 'b';

    public function label(): string
    {
        return match ($this) {
            self::A => 'Side A',
            self::B => 'Side B',
        };
    }

    public function opposite(): self
    {
        return match ($this) {
            self::A => self::B,
            self::B => self::A,
        };
    }
}
