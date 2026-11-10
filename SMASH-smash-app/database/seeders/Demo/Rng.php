<?php

namespace Database\Seeders\Demo;

use LogicException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A seeded, self-contained random source for the demo world (Mersenne Twister), so the demo is
 * identical on every run and independent of anything else that draws random numbers.
 */
final class Rng
{
    private readonly Randomizer $randomizer;

    public function __construct(int $seed)
    {
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    /**
     * A whole number from $min to $max (inclusive).
     */
    public function int(int $min, int $max): int
    {
        return $max <= $min ? $min : $this->randomizer->getInt($min, $max);
    }

    /**
     * A float in [0, 1).
     */
    public function float(): float
    {
        return $this->randomizer->nextFloat();
    }

    /**
     * A float in [$min, $max).
     */
    public function between(float $min, float $max): float
    {
        return $min + ($max - $min) * $this->float();
    }

    public function chance(float $probability): bool
    {
        return $this->float() < $probability;
    }

    /**
     * Normally distributed (Box–Muller).
     */
    public function gaussian(float $mean = 0.0, float $deviation = 1.0): float
    {
        $u = max(1e-12, $this->float());
        $v = $this->float();

        return $mean + $deviation * sqrt(-2.0 * log($u)) * cos(2.0 * M_PI * $v);
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        if ($items === []) {
            throw new LogicException('Cannot pick from an empty list.');
        }

        return $items[$this->int(0, count($items) - 1)];
    }

    /**
     * A key of $weights, chosen with probability proportional to its weight (null when every weight is 0).
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, float>  $weights
     * @return TKey|null
     */
    public function weighted(array $weights): int|string|null
    {
        $total = 0.0;

        foreach ($weights as $weight) {
            $total += max(0.0, $weight);
        }

        if ($total <= 0.0) {
            return null;
        }

        $target = $this->float() * $total;
        $last = null;

        foreach ($weights as $key => $weight) {
            if ($weight <= 0.0) {
                continue;
            }

            $last = $key;
            $target -= $weight;

            if ($target < 0.0) {
                return $key;
            }
        }

        return $last;
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        return $this->randomizer->shuffleArray($items);
    }
}
