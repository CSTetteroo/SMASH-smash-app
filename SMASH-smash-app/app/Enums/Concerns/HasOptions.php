<?php

namespace App\Enums\Concerns;

/**
 * Shared helpers for string-backed enums that expose a human readable label().
 *
 * @phpstan-require-implements \BackedEnum
 */
trait HasOptions
{
    /**
     * All cases as value => label, ready for a <flux:select> or validation rule.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[(string) $case->value] = $case->label();
        }

        return $options;
    }

    /**
     * All backing values, e.g. for Rule::in() / validation.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => (string) $case->value, self::cases());
    }
}
