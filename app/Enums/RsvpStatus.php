<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum RsvpStatus: string
{
    use HasOptions;

    case Going = 'going';
    case Waitlist = 'waitlist';

    public function label(): string
    {
        return match ($this) {
            self::Going => 'Going',
            self::Waitlist => 'Waitlist',
        };
    }

    /**
     * UI tone: navy|orange|green|smash|sky|grey.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Going => 'green',
            self::Waitlist => 'orange',
        };
    }
}
