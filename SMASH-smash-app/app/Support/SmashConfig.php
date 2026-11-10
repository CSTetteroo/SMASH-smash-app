<?php

namespace App\Support;

/**
 * Typed access to the numeric tunables in config/smash.php.
 *
 * config()->float() rejects integers (e.g. 'scale' => 400), so these accept any numeric value.
 */
final class SmashConfig
{
    /**
     * A numeric value under "smash.", e.g. float('rating.scale', 400.0).
     */
    public static function float(string $key, float $default): float
    {
        $value = config('smash.'.$key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * An integer value under "smash.", e.g. int('rating.provisional_matches', 10).
     */
    public static function int(string $key, int $default): int
    {
        $value = config('smash.'.$key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * The rating every player starts with (1500).
     */
    public static function initialRating(): float
    {
        return self::float('rating.initial', 1500.0);
    }
}
