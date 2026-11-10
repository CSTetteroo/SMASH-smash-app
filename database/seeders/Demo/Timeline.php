<?php

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Closure;

/**
 * The season replayed in order: every event runs with the clock set to its moment, so rows get
 * realistic timestamps and the actions see the world as it was then (registration still open,
 * club night not over yet, …). Events at the same moment run in the order they were added.
 */
final class Timeline
{
    /**
     * @var list<array{at: CarbonImmutable, seq: int, run: Closure(): void}>
     */
    private array $events = [];

    private int $sequence = 0;

    /**
     * @param  Closure(): void  $run
     */
    public function add(CarbonImmutable $at, Closure $run): void
    {
        $this->events[] = ['at' => $at, 'seq' => $this->sequence++, 'run' => $run];
    }

    public function run(World $world): void
    {
        $events = $this->events;

        usort($events, fn (array $a, array $b): int => [$a['at']->getTimestamp(), $a['seq']] <=> [$b['at']->getTimestamp(), $b['seq']]);

        $this->events = [];

        foreach ($events as $event) {
            $world->travel($event['at']);
            ($event['run'])();
        }
    }
}
