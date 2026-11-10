<?php

namespace Database\Seeders\Demo;

use App\Enums\Discipline;
use App\Models\BadmintonMatch;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * A challenge match the committee announces a few days before a club night (a pick'em target,
 * on a fixed court). Most are played on the night; one is called off.
 */
final class ChallengePlan
{
    public ?BadmintonMatch $match = null;

    /**
     * @param  list<int>  $sideA  user ids
     * @param  list<int>  $sideB  user ids
     * @param  int|null  $winnerId  a player whose side is scripted to win (the story needs it)
     */
    public function __construct(
        public readonly Discipline $discipline,
        public readonly array $sideA,
        public readonly array $sideB,
        public readonly CarbonImmutable $scheduledAt,
        public readonly int $court,
        public readonly CarbonImmutable $announcedAt,
        public readonly int $announcedBy,
        public readonly ?int $winnerId = null,
        public readonly ?CarbonImmutable $cancelledAt = null,
        public readonly ?string $cancelNote = null,
        public readonly ?string $note = null,
    ) {}

    /**
     * @return list<int>
     */
    public function players(): array
    {
        return [...$this->sideA, ...$this->sideB];
    }

    public function isCancelled(): bool
    {
        return $this->cancelledAt !== null;
    }

    public function created(): BadmintonMatch
    {
        return $this->match ?? throw new LogicException('The challenge match has not been announced yet.');
    }

    public function key(): string
    {
        $a = $this->sideA;
        $b = $this->sideB;
        sort($a);
        sort($b);
        $sides = [implode('+', $a), implode('+', $b)];
        sort($sides);

        return implode('|', $sides);
    }
}
