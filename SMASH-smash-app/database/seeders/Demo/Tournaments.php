<?php

namespace Database\Seeders\Demo;

use App\Actions\Matches\SaveMatch;
use App\Actions\Tournaments\RegisterForTournament;
use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\BadmintonMatch;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Services\Rating\RatingEngine;
use App\Services\Tournament\DrawGenerator;
use Carbon\CarbonImmutable;
use Closure;
use LogicException;

/**
 * The season's five tournaments, run through the real actions and services: members register
 * (RegisterForTournament) while registration is open, ratings are recalculated right before each
 * draw so seeding uses the ratings of that moment (DrawGenerator), and results go in through
 * SaveMatch, which moves winners on (TournamentProgress) and completes the tournament.
 *
 * - Welcome Week Open: singles knockout, 16 players, second Saturday of the season. Completed.
 * - Autumn Doubles Cup: doubles round robin, 6 pairs, Saturday of week 5. Completed.
 * - Halloween Smash: singles knockout, 24 players (8 byes); rounds of 32 and 16 three days ago,
 *   quarter-finals to the final four days ahead, with a walkover in the round of 32. In progress.
 * - Winter Doubles Showdown: doubles knockout, max 16 pairs, nine registered. Registration open.
 * - New Year Ladder Classic: singles round robin in January. Draft.
 */
final class Tournaments
{
    /**
     * @var array<string, Tournament>
     */
    private array $tournaments = [];

    public function __construct(private readonly World $world) {}

    public function schedule(Timeline $timeline): void
    {
        $this->welcomeWeekOpen($timeline);
        $this->autumnDoublesCup($timeline);
        $this->halloweenSmash($timeline);
        $this->winterDoublesShowdown($timeline);
        $this->newYearLadderClassic($timeline);
    }

    private function welcomeWeekOpen(Timeline $timeline): void
    {
        $day = $this->world->calendar->welcomeWeekOpen();
        $deadline = $day->subDays(2)->setTime(22, 0);
        $created = $this->world->calendar->seasonStart->subDays(18)->setTime(12, 0);

        $this->create($timeline, 'welcome-week-open', $created, 'noor', [
            'name' => 'Welcome Week Open',
            'description' => "SMASH's first tournament: a one-day singles knockout to kick off the season. Open to every member, seeded on rating, best of three games to 21.",
            'discipline' => Discipline::Singles,
            'format' => TournamentFormat::SingleElimination,
            'starts_at' => $day,
            'ends_at' => $day->setTime(15, 30),
            'registration_closes_at' => $deadline,
            'max_entries' => 16,
        ]);

        $this->register($timeline, 'welcome-week-open', [
            ['wei'], ['noor'], ['mads'], ['lucas'], ['arjun'], ['emre'], ['kenji'], ['jonas'],
            ['kacper'], ['oliver'], ['hao'], ['giulia'], ['andrei'], ['chidi'], ['aiman'], ['marco'],
        ], $created->addHours(3), $deadline->subHours(6));

        $this->draw($timeline, 'welcome-week-open', $day->subDay()->setTime(18, 0), [
            1 => [$day, $day->addMinutes(45)],
            2 => [$day->setTime(11, 45)],
            3 => [$day->setTime(13, 15)],
            4 => [$day->setTime(14, 30)],
        ]);

        $noor = $this->world->profile('noor')->id;

        // Noor reaches the final and loses it in three, after winning game two on the golden point.
        $this->play($timeline, 'welcome-week-open', $day, [1, 2, 3, 4], function (BadmintonMatch $match, array $sideA, array $sideB) use ($noor): ?array {
            $side = $this->sideOf($noor, $sideA, $sideB);

            if ($side === null) {
                return null;
            }

            if ($match->round === 4) {
                $games = [['a' => 19, 'b' => 21], ['a' => 30, 'b' => 29], ['a' => 18, 'b' => 21]];

                return [
                    'games' => $side === Side::A ? $games : MatchSimulator::flip($games),
                    'note' => 'A final to remember: game two went to the golden point at 29-all.',
                ];
            }

            return ['winner' => $side];
        });
    }

    private function autumnDoublesCup(Timeline $timeline): void
    {
        $calendar = $this->world->calendar;
        $day = $calendar->autumnDoublesCup();
        $deadline = $calendar->at(5, 1, '23:00');
        $created = $calendar->at(2, 3, '12:00');

        $this->create($timeline, 'autumn-doubles-cup', $created, 'daan', [
            'name' => 'Autumn Doubles Cup',
            'description' => 'Six pairs, one round robin: everybody plays everybody, best of three to 21. The table decides the cup (wins, then games, then points).',
            'discipline' => Discipline::Doubles,
            'format' => TournamentFormat::RoundRobin,
            'starts_at' => $day,
            'ends_at' => $day->setTime(14, 0),
            'registration_closes_at' => $deadline,
            'max_entries' => 6,
        ]);

        $this->register($timeline, 'autumn-doubles-cup', [
            ['noor', 'sanne'], ['meiling', 'linh'], ['lucas', 'mateo'], ['felix', 'lena'], ['priya', 'rohan'], ['emre', 'elif'],
        ], $created->addHours(2), $deadline->subDay());

        $slots = [];

        for ($matchday = 1; $matchday <= 5; $matchday++) {
            $slots[$matchday] = [$day->addMinutes(40 * ($matchday - 1))];
        }

        $this->draw($timeline, 'autumn-doubles-cup', $day->subDay()->setTime(18, 0), $slots);

        $noor = $this->world->profile('noor')->id;
        $meiLing = $this->world->profile('meiling')->id;

        // Noor and Sanne win the cup with five out of five, the one against Mei Ling and Linh in three.
        $this->play($timeline, 'autumn-doubles-cup', $day, [1, 2, 3, 4, 5], function (BadmintonMatch $match, array $sideA, array $sideB) use ($noor, $meiLing): ?array {
            $side = $this->sideOf($noor, $sideA, $sideB);

            if ($side === null) {
                return null;
            }

            $opponents = $side === Side::A ? $sideB : $sideA;

            return [
                'winner' => $side,
                'accept' => in_array($meiLing, $opponents, true) ? fn (array $games): bool => count($games) === 3 : null,
            ];
        });
    }

    private function halloweenSmash(Timeline $timeline): void
    {
        $calendar = $this->world->calendar;
        $start = $calendar->halloweenStart();
        $finals = $calendar->halloweenFinalsDay();
        $deadline = $start->subDays(2)->setTime(23, 0);
        $created = $start->subDays(26)->setTime(12, 0);

        $this->create($timeline, 'halloween-smash', $created, 'noor', [
            'name' => 'Halloween Smash',
            'description' => 'Singles knockout over two days: the rounds of 32 and 16 on day one, quarter-finals to the final a week later. With 24 players, the top eight seeds start with a bye.',
            'discipline' => Discipline::Singles,
            'format' => TournamentFormat::SingleElimination,
            'starts_at' => $start,
            'ends_at' => $finals->setTime(15, 0),
            'registration_closes_at' => $deadline,
            'max_entries' => 32,
        ]);

        $this->register($timeline, 'halloween-smash', [
            ['noor'], ['wei'], ['femke'], ['lucas'], ['mads'], ['arjun'], ['jonas'], ['rizky'],
            ['emre'], ['kacper'], ['hao'], ['moritz'], ['giulia'], ['andrei'], ['ananya'], ['aiman'],
            ['jiwoo'], ['rafael'], ['arne'], ['chidi'], ['marco'], ['wout'], ['minh'], ['yassine'],
        ], $created->addHours(2), $deadline->subHours(12));

        $this->draw($timeline, 'halloween-smash', $start->subDay()->setTime(18, 0), [
            1 => [$start, $start->addMinutes(45)],
            2 => [$start->setTime(12, 0), $start->setTime(12, 45)],
            3 => [$finals],
            4 => [$finals->setTime(11, 30)],
            5 => [$finals->setTime(13, 0)],
        ]);

        $noor = $this->world->profile('noor')->id;
        $lucas = $this->world->profile('lucas')->id;
        $femke = $this->world->profile('femke')->id;

        // Day one: Femke (injured) gives a walkover; both demo accounts reach the quarter-finals.
        $this->play($timeline, 'halloween-smash', $start, [1, 2], function (BadmintonMatch $match, array $sideA, array $sideB) use ($noor, $lucas, $femke): ?array {
            $injured = $this->sideOf($femke, $sideA, $sideB);

            if ($injured !== null) {
                return ['walkover' => $injured->opposite(), 'note' => 'Walkover: Femke Willems withdrew with an ankle injury.'];
            }

            foreach ([$noor, $lucas] as $hero) {
                $side = $this->sideOf($hero, $sideA, $sideB);

                if ($side !== null) {
                    return ['winner' => $side];
                }
            }

            return null;
        });
    }

    private function winterDoublesShowdown(Timeline $timeline): void
    {
        $calendar = $this->world->calendar;
        $day = $calendar->winterDoubles();
        $created = $calendar->today->subDays(16)->setTime(12, 0);

        $this->create($timeline, 'winter-doubles-showdown', $created, 'priya', [
            'name' => 'Winter Doubles Showdown',
            'description' => 'Doubles knockout before the Christmas break. Up to 16 pairs, seeded on the average doubles rating of each pair. Register together with your partner before the deadline.',
            'discipline' => Discipline::Doubles,
            'format' => TournamentFormat::SingleElimination,
            'starts_at' => $day,
            'ends_at' => $day->setTime(16, 0),
            'registration_closes_at' => $day->subDays(2)->setTime(23, 0),
            'max_entries' => 16,
        ]);

        $this->register($timeline, 'winter-doubles-showdown', [
            ['putri', 'rizky'], ['noor', 'sanne'], ['meiling', 'linh'], ['lucas', 'mateo'], ['mads', 'freja'],
            ['felix', 'lena'], ['thijs', 'fleur'], ['kacper', 'zuzanna'], ['emre', 'elif'],
        ], $created->addHours(3), $calendar->now->subHours(3));
    }

    private function newYearLadderClassic(Timeline $timeline): void
    {
        $calendar = $this->world->calendar;
        $day = $calendar->newYearLadder();

        $this->create($timeline, 'new-year-ladder-classic', $calendar->today->subDays(2)->setTime(15, 20), 'noor', [
            'name' => 'New Year Ladder Classic',
            'description' => 'Start 2027 with a singles round robin in groups of similar level. Details and registration follow after the Christmas break.',
            'discipline' => Discipline::Singles,
            'format' => TournamentFormat::RoundRobin,
            'status' => TournamentStatus::Draft,
            'starts_at' => $day,
            'ends_at' => $day->setTime(17, 0),
            'registration_closes_at' => $day->subDays(5)->setTime(23, 0),
            'max_entries' => 12,
        ]);
    }

    // ------------------------------------------------------------------
    // Steps
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(Timeline $timeline, string $slug, CarbonImmutable $at, string $creator, array $attributes): void
    {
        $timeline->add($at, function () use ($slug, $creator, $attributes): void {
            $this->tournaments[$slug] = Tournament::query()->create([
                'slug' => $slug,
                'status' => TournamentStatus::Open,
                'venue' => config()->string('smash.club.venue', 'BUas Sports Hall'),
                'best_of' => 3,
                'points_per_game' => 21,
                'is_rated' => true,
                'created_by' => $this->world->user($creator)->id,
                ...$attributes,
            ]);
        });
    }

    /**
     * Entries register in the order given, spread evenly over the registration window.
     *
     * @param  list<array{0: string, 1?: string}>  $entrants  player key (and partner key)
     */
    private function register(Timeline $timeline, string $slug, array $entrants, CarbonImmutable $from, CarbonImmutable $until): void
    {
        $span = max(60, $until->getTimestamp() - $from->getTimestamp());
        $count = count($entrants);

        foreach ($entrants as $index => $entrant) {
            $at = $from->addSeconds((int) ($span * ($index + 0.5 + $this->world->plan->between(-0.4, 0.4)) / $count));

            $timeline->add($at, function () use ($slug, $entrant): void {
                RegisterForTournament::handle(
                    $this->tournament($slug),
                    $this->world->user($entrant[0]),
                    isset($entrant[1]) ? $this->world->user($entrant[1]) : null,
                );
            });
        }
    }

    /**
     * Make the draw with the ratings of that moment, then set the match times per round
     * (four courts; byes have no time or court).
     *
     * @param  array<int, list<CarbonImmutable>>  $slots  round => start times (one per wave of four courts)
     */
    private function draw(Timeline $timeline, string $slug, CarbonImmutable $at, array $slots): void
    {
        $timeline->add($at, function () use ($slug, $at, $slots): void {
            $tournament = $this->tournament($slug);

            RatingEngine::recalculate();
            DrawGenerator::generate($tournament);

            $waves = [];

            foreach ($tournament->matches()->orderBy('round')->orderBy('bracket_position')->get() as $match) {
                if ($match->status !== MatchStatus::Scheduled || ! isset($slots[(int) $match->round])) {
                    continue;
                }

                $round = (int) $match->round;
                $index = $waves[$round] = ($waves[$round] ?? -1) + 1;
                $times = $slots[$round];
                $wave = intdiv($index, 4);
                $time = $times[min($wave, count($times) - 1)]->addMinutes(45 * max(0, $wave - count($times) + 1));

                $match->forceFill(['scheduled_at' => $time, 'court' => $index % 4 + 1])->save();

                if ($match->entry_a_id !== null && $match->entry_b_id !== null) {
                    $this->world->announce($match->id, $at);
                }
            }
        });
    }

    /**
     * Play $rounds on $day in order. $script may force a result for the story:
     * ['winner' => Side], ['accept' => fn(games): bool], ['games' => fixed games], ['walkover' => Side], ['note' => …].
     *
     * @param  list<int>  $rounds
     * @param  Closure(BadmintonMatch, list<int>, list<int>): (array<string, mixed>|null)  $script
     */
    private function play(Timeline $timeline, string $slug, CarbonImmutable $day, array $rounds, Closure $script): void
    {
        $timeline->add($day, function () use ($slug, $rounds, $script): void {
            $tournament = $this->tournament($slug);
            $form = [];

            foreach ($tournament->entries()->get() as $entry) {
                foreach ([$entry->player_id, $entry->partner_id] as $id) {
                    if ($id !== null) {
                        $form[$id] = $this->world->play->gaussian(0.0, 25.0);
                    }
                }
            }

            RatingEngine::deferred(function () use ($tournament, $rounds, $script, $form): void {
                foreach ($rounds as $round) {
                    $matches = $tournament->matches()->where('round', $round)->orderBy('bracket_position')->get();

                    foreach ($matches as $match) {
                        if ($match->status === MatchStatus::Scheduled) {
                            $this->playMatch($match, $script, $form);
                        }
                    }

                    $this->announceNextRound($tournament, $round);
                }
            });
        });
    }

    /**
     * @param  Closure(BadmintonMatch, list<int>, list<int>): (array<string, mixed>|null)  $script
     * @param  array<int, float>  $form
     */
    private function playMatch(BadmintonMatch $match, Closure $script, array $form): void
    {
        $sideA = $this->entryPlayers($match->entry_a_id);
        $sideB = $this->entryPlayers($match->entry_b_id);
        $outcome = $script($match, $sideA, $sideB) ?? [];
        $scheduled = $match->scheduled_at ?? CarbonImmutable::now();
        $note = isset($outcome['note']) && is_string($outcome['note']) ? $outcome['note'] : null;

        if (($outcome['walkover'] ?? null) instanceof Side) {
            $this->world->travel($scheduled->subMinutes(20));

            SaveMatch::handle([
                'status' => MatchStatus::Walkover,
                'walkover_winner' => $outcome['walkover'],
                'played_at' => $scheduled,
                'notes' => $note,
            ], $match, $this->world->chair());

            return;
        }

        $games = $this->fixedGames($outcome['games'] ?? null) ?? $this->world->simulator->play(
            $this->world->probability($sideA, $sideB, $match->discipline, $form),
            $match->best_of,
            $match->points_per_game,
            ($outcome['winner'] ?? null) instanceof Side ? $outcome['winner'] : null,
            ($outcome['accept'] ?? null) instanceof Closure ? $outcome['accept'] : null,
        );

        $playedAt = $scheduled->addMinutes(MatchSimulator::minutes($games));
        $this->world->travel($playedAt->addMinutes(3));

        SaveMatch::handle([
            'status' => MatchStatus::Completed,
            'games' => $games,
            'played_at' => $playedAt,
            'notes' => $note,
        ], $match, $this->world->chair());
    }

    /**
     * Knockout: next-round matches whose line-up is now known open for predictions.
     */
    private function announceNextRound(Tournament $tournament, int $round): void
    {
        if (! $tournament->isKnockout()) {
            return;
        }

        $next = $tournament->matches()
            ->where('round', $round + 1)
            ->where('status', MatchStatus::Scheduled->value)
            ->whereNotNull('entry_a_id')
            ->whereNotNull('entry_b_id')
            ->get();

        foreach ($next as $match) {
            $this->world->announce($match->id, CarbonImmutable::now());
        }
    }

    /**
     * @return list<int>
     */
    private function entryPlayers(?int $entryId): array
    {
        $entry = $entryId === null ? null : TournamentEntry::query()->find($entryId);

        if ($entry === null) {
            return [];
        }

        return $entry->partner_id === null ? [$entry->player_id] : [$entry->player_id, $entry->partner_id];
    }

    /**
     * @param  list<int>  $sideA
     * @param  list<int>  $sideB
     */
    private function sideOf(int $userId, array $sideA, array $sideB): ?Side
    {
        return match (true) {
            in_array($userId, $sideA, true) => Side::A,
            in_array($userId, $sideB, true) => Side::B,
            default => null,
        };
    }

    /**
     * @return list<array{a: int, b: int}>|null
     */
    private function fixedGames(mixed $games): ?array
    {
        if (! is_array($games) || $games === []) {
            return null;
        }

        $list = [];

        foreach ($games as $game) {
            if (is_array($game) && is_int($game['a'] ?? null) && is_int($game['b'] ?? null)) {
                $list[] = ['a' => $game['a'], 'b' => $game['b']];
            }
        }

        return $list;
    }

    private function tournament(string $slug): Tournament
    {
        return $this->tournaments[$slug] ?? throw new LogicException("The {$slug} tournament does not exist yet.");
    }
}
