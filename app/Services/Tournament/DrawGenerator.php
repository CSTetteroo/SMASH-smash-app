<?php

namespace App\Services\Tournament;

use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Enums\TournamentStatus;
use App\Models\BadmintonMatch;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes a tournament's draw from its entries and starts the tournament (status In progress).
 *
 * Seeding: entries keep a seed they were given; the others fill the free numbers by rating
 * (best first). Knockout: the bracket is the next power of two, seeds are placed in standard
 * order (1 v 16, 8 v 9, …) so seeds 1 and 2 can only meet in the final, every round is created
 * up-front, and byes are walkovers whose entry moves straight on. Round robin: circle method,
 * one matchday per round, nobody plays twice on a matchday, seeds 1 and 2 meet on the last one.
 * Matches copy the tournament's format and venue, get courts 1..n and staggered start times.
 */
final class DrawGenerator
{
    /**
     * @throws ValidationException (key "draw") when the draw cannot be made
     */
    public static function generate(Tournament $tournament): void
    {
        if (! in_array($tournament->status, [TournamentStatus::Draft, TournamentStatus::Open], true)) {
            self::fail('The draw can only be made before the tournament has started.');
        }

        if ($tournament->matches()->exists()) {
            self::fail('This tournament already has a draw. Reset it before making a new one.');
        }

        $entries = $tournament->entries()->with(['player', 'partner'])->orderBy('id')->get();

        if ($entries->count() < 2) {
            self::fail('At least two entries are needed to make a draw.');
        }

        foreach ($entries as $entry) {
            $entry->setRelation('tournament', $tournament);

            if ($tournament->isDoubles() && $entry->partner_id === null) {
                self::fail(sprintf('%s has no partner yet. Every doubles entry needs a partner before the draw.', $entry->name()));
            }

            if (! $tournament->isDoubles() && $entry->partner_id !== null) {
                self::fail(sprintf('%s is a pair, but this is a singles tournament.', $entry->name()));
            }
        }

        DB::transaction(static function () use ($tournament, $entries): void {
            $seeded = self::seed($entries);

            if ($tournament->isKnockout()) {
                self::knockout($tournament, $seeded);
            } else {
                self::roundRobin($tournament, $seeded);
            }

            $tournament->status = TournamentStatus::InProgress;
            $tournament->save();
            $tournament->unsetRelation('matches');
            $tournament->unsetRelation('entries');
        });
    }

    /**
     * Seed order for the entries (seed 1 first), saving the seeds. Given seeds are kept (ties and
     * numbers beyond the entry count are resolved in order); unseeded entries fill the free
     * numbers by rating, best first, then registration order.
     *
     * @param  Collection<int, TournamentEntry>  $entries
     * @return list<TournamentEntry>
     */
    public static function seed(Collection $entries): array
    {
        $fixed = $entries
            ->filter(fn (TournamentEntry $entry): bool => $entry->seed !== null)
            ->sortBy([['seed', 'asc'], ['id', 'asc']])
            ->values()
            ->all();

        $open = $entries
            ->filter(fn (TournamentEntry $entry): bool => $entry->seed === null)
            ->sort(fn (TournamentEntry $a, TournamentEntry $b): int => [$b->rating(), $a->id] <=> [$a->rating(), $b->id])
            ->values()
            ->all();

        $order = [];

        for ($seed = 1, $count = $entries->count(); $seed <= $count; $seed++) {
            $takeFixed = $fixed !== [] && ($open === [] || $fixed[0]->seed <= $seed);
            $entry = $takeFixed ? array_shift($fixed) : array_shift($open);

            if ($entry === null) {
                break;
            }

            if ($entry->seed !== $seed) {
                $entry->seed = $seed;
                $entry->save();
            }

            $order[] = $entry;
        }

        return $order;
    }

    /**
     * Standard bracket positions for a draw of $size (a power of two), top to bottom:
     * 8 => [1, 8, 4, 5, 2, 7, 3, 6], i.e. 1 v 8, 4 v 5, 2 v 7, 3 v 6.
     *
     * @return list<int>
     */
    public static function bracketOrder(int $size): array
    {
        $order = [1];

        for ($length = 2; $length <= $size; $length *= 2) {
            $next = [];

            foreach ($order as $seed) {
                $next[] = $seed;
                $next[] = $length + 1 - $seed;
            }

            $order = $next;
        }

        return $order;
    }

    /**
     * The smallest power of two that fits $entries.
     */
    public static function bracketSize(int $entries): int
    {
        $size = 1;

        while ($size < $entries) {
            $size *= 2;
        }

        return $size;
    }

    /**
     * Circle-method pairings: n − 1 matchdays for an even n, n for an odd n (each entry rests once).
     * Pairs are 0-based seed indexes, better seed first; the matchday where seeds 1 and 2 meet is last.
     *
     * @return list<list<array{0: int, 1: int}>>
     */
    public static function roundRobinRounds(int $entries): array
    {
        if ($entries < 2) {
            return [];
        }

        $slots = range(0, $entries - 1);

        if ($entries % 2 === 1) {
            $slots[] = null;
        }

        $size = count($slots);
        $rounds = [];

        for ($round = 0; $round < $size - 1; $round++) {
            $pairs = [];

            for ($i = 0; $i < intdiv($size, 2); $i++) {
                $home = $slots[$i];
                $away = $slots[$size - 1 - $i];

                if ($home !== null && $away !== null) {
                    $pairs[] = [min($home, $away), max($home, $away)];
                }
            }

            sort($pairs);
            $rounds[] = $pairs;

            // Keep the first slot fixed and rotate the others one place.
            $last = array_pop($slots);
            array_splice($slots, 1, 0, [$last]);
        }

        foreach ($rounds as $index => $pairs) {
            if (in_array([0, 1], $pairs, true)) {
                unset($rounds[$index]);
                $rounds[] = $pairs;

                break;
            }
        }

        return array_values($rounds);
    }

    /**
     * @param  list<TournamentEntry>  $seeded
     */
    private static function knockout(Tournament $tournament, array $seeded): void
    {
        $size = self::bracketSize(count($seeded));
        $order = self::bracketOrder($size);
        $schedule = new DrawSchedule($tournament->starts_at, $tournament->best_of, $tournament->points_per_game);

        /** @var array<int, BadmintonMatch> $previousRound position => match */
        $previousRound = [];

        for ($round = 1, $inRound = intdiv($size, 2); $inRound >= 1; $round++, $inRound = intdiv($inRound, 2)) {
            $currentRound = [];

            for ($position = 1; $position <= $inRound; $position++) {
                if ($round === 1) {
                    $entryA = $seeded[$order[2 * $position - 2] - 1] ?? null;
                    $entryB = $seeded[$order[2 * $position - 1] - 1] ?? null;
                    $entryAId = $entryA?->id;
                    $entryBId = $entryB?->id;
                } else {
                    // Only byes are decided at this point.
                    $entryAId = self::byeWinner($previousRound, 2 * $position - 1);
                    $entryBId = self::byeWinner($previousRound, 2 * $position);
                }

                $attributes = self::baseAttributes($tournament) + [
                    'round' => $round,
                    'bracket_position' => $position,
                    'entry_a_id' => $entryAId,
                    'entry_b_id' => $entryBId,
                ];

                if ($round === 1 && ($entryAId === null || $entryBId === null)) {
                    $attributes = [
                        ...$attributes,
                        'status' => MatchStatus::Walkover,
                        'winning_side' => $entryAId !== null ? Side::A : Side::B,
                        'is_rated' => false,
                    ];
                } else {
                    [$attributes['scheduled_at'], $attributes['court']] = $schedule->next();
                }

                $match = BadmintonMatch::query()->create($attributes);

                if ($entryAId !== null && $entryBId !== null) {
                    TournamentProgress::syncParticipants($match);
                }

                $currentRound[$position] = $match;
            }

            $schedule->nextRound();
            $previousRound = $currentRound;
        }
    }

    /**
     * @param  list<TournamentEntry>  $seeded
     */
    private static function roundRobin(Tournament $tournament, array $seeded): void
    {
        $schedule = new DrawSchedule($tournament->starts_at, $tournament->best_of, $tournament->points_per_game);

        foreach (self::roundRobinRounds(count($seeded)) as $index => $pairs) {
            foreach ($pairs as $position => [$home, $away]) {
                [$scheduledAt, $court] = $schedule->next();

                $match = BadmintonMatch::query()->create(self::baseAttributes($tournament) + [
                    'round' => $index + 1,
                    'bracket_position' => $position + 1,
                    'entry_a_id' => $seeded[$home]->id,
                    'entry_b_id' => $seeded[$away]->id,
                    'scheduled_at' => $scheduledAt,
                    'court' => $court,
                ]);

                TournamentProgress::syncParticipants($match);
            }

            $schedule->nextRound();
        }
    }

    /**
     * The entry a first-round bye sends through, if the match at $position of $round is a bye.
     *
     * @param  array<int, BadmintonMatch>  $round  position => match
     */
    private static function byeWinner(array $round, int $position): ?int
    {
        $match = $round[$position] ?? null;

        return $match !== null && $match->isBye() ? TournamentProgress::advancingEntryId($match) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function baseAttributes(Tournament $tournament): array
    {
        return [
            'tournament_id' => $tournament->id,
            'discipline' => $tournament->discipline,
            'status' => MatchStatus::Scheduled,
            'best_of' => $tournament->best_of,
            'points_per_game' => $tournament->points_per_game,
            'is_rated' => $tournament->is_rated,
            'venue' => $tournament->venue ?? config('smash.club.venue'),
            'scheduled_at' => null,
            'played_at' => null,
            'court' => null,
            'winning_side' => null,
            'created_by' => $tournament->created_by,
        ];
    }

    /**
     * @throws ValidationException
     */
    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['draw' => $message]);
    }
}
