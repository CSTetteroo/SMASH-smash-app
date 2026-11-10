<?php

namespace Database\Seeders\Demo;

use App\Actions\ClubNights\CheckInAttendee;
use App\Actions\ClubNights\JoinClubNight;
use App\Actions\ClubNights\LeaveClubNight;
use App\Actions\Matches\SaveMatch;
use App\Enums\ClubNightKind;
use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\RsvpStatus;
use App\Enums\Side;
use App\Enums\SkillLevel;
use App\Models\ClubNight;
use App\Models\User;
use App\Services\Rating\RatingEngine;
use Carbon\CarbonImmutable;

/**
 * Club nights from the first Tuesday of the season to six weeks ahead.
 *
 * Past nights: members RSVP in the days before (JoinClubNight; a few cancel with
 * LeaveClubNight, which moves the waitlist up), most turn up and are checked in at the door
 * (CheckInAttendee), and the people there play and log friendlies (SaveMatch): mostly with
 * players of their own level, sometimes not, singles or doubles to taste. Ladder nights are one
 * game to 21 against players near you in the rankings; clinics have an unrated practice game.
 * The committee announces a challenge match for most social nights (a pick'em target).
 *
 * Upcoming nights: RSVPs so far, the next Tuesday and Thursday and the Christmas social already
 * full with a waitlist, and a handful of challenge matches on fixed courts.
 */
final class ClubNights
{
    /**
     * Minutes between match start slots on a night.
     */
    private const SLOT = 26;

    /**
     * Global scale on RSVP chances (how busy nights are).
     */
    private const ATTENDANCE = 0.82;

    /**
     * Match-making: how far apart (in skill) people still pick each other, normally and on the
     * nights they just play whoever is free; and how often social doubles are "balanced" teams.
     */
    private const NEAR = 300.0;

    private const MIXED = 600.0;

    private const MIXING = 0.42;

    private const BALANCED_SPLIT = 0.1;

    private const NO_SHOW = 0.08;

    private const LEAVE = 0.07;

    /**
     * Matches logged per checked-in player on a night, by kind.
     */
    private const LOG_RATE = ['social' => 0.58, 'special' => 0.62, 'training' => 0.28, 'ladder' => 0.72];

    /**
     * Demo-account moments (achievements) on given nights: "week-day" => [player, what, discipline].
     *
     * @var array<string, list<array{0: string, 1: 'shutout'|'comeback'|'iron_lungs', 2: Discipline}>>
     */
    private const SCRIPTS = [
        '0-thu' => [['lucas', 'shutout', Discipline::Singles]],
        '1-tue' => [['noor', 'comeback', Discipline::Singles]],
        '2-tue' => [['noor', 'shutout', Discipline::Singles]],
        '2-thu' => [['lucas', 'comeback', Discipline::Singles]],
        '3-thu' => [['lucas', 'iron_lungs', Discipline::Doubles]],
    ];

    private const GENERIC_NOTES = [
        'Played with the new feather shuttles.',
        'Rematch from last week.',
        'Long rallies, hardly any unforced errors.',
        'First match back after a sprained wrist.',
        'Played on court 4, the one with the flickering light.',
        'Good-natured grudge match. Loser buys the drinks.',
    ];

    /**
     * Challenge matches per night index.
     *
     * @var array<int, list<ChallengePlan>>
     */
    private array $challenges = [];

    /**
     * @var array<string, true>
     */
    private array $usedMatchups = [];

    private int $announcer = 0;

    public function __construct(private readonly World $world) {}

    /**
     * Create every club night row, in date order (published in two blocks).
     */
    public function create(): void
    {
        $venue = config()->string('smash.club.venue', 'BUas Sports Hall');

        foreach (Schedule::build($this->world->calendar) as $plan) {
            $this->world->travel($plan->publishedAt);

            $plan->model = ClubNight::query()->create([
                'title' => $plan->title,
                'kind' => $plan->kind,
                'description' => $plan->description,
                'venue' => $venue,
                'starts_at' => $plan->startsAt,
                'ends_at' => $plan->endsAt,
                'courts' => $plan->courts,
                'capacity' => $plan->capacity,
                'level' => $plan->level,
                'created_by' => $this->world->user($plan->week < 8 ? 'noor' : 'priya')->id,
            ]);

            $this->world->nights[] = $plan;
        }
    }

    public function schedule(Timeline $timeline): void
    {
        $now = $this->world->calendar->now;
        $upcoming = ['tue' => 0, 'thu' => 0, 'sun' => 0];

        foreach ($this->world->nights as $index => $plan) {
            if ($plan->startsAt->lessThanOrEqualTo($now)) {
                $this->schedulePast($timeline, $index, $plan);

                continue;
            }

            $upcoming[$plan->day]++;
            $this->scheduleUpcoming($timeline, $plan, $upcoming[$plan->day]);
        }
    }

    // ------------------------------------------------------------------
    // Past nights
    // ------------------------------------------------------------------

    private function schedulePast(Timeline $timeline, int $index, NightPlan $plan): void
    {
        $challenges = $this->planChallenges($plan);
        $this->challenges[$index] = $challenges;
        $forced = [];

        foreach ($challenges as $challenge) {
            $timeline->add($challenge->announcedAt, function () use ($challenge): void {
                $this->announce($challenge);
            });

            if ($challenge->cancelledAt !== null) {
                $timeline->add($challenge->cancelledAt, function () use ($challenge): void {
                    $this->cancel($challenge);
                });

                continue;
            }

            foreach ($challenge->players() as $id) {
                $forced[$id] ??= $challenge->announcedAt;
            }
        }

        foreach (self::SCRIPTS[$plan->week.'-'.$plan->day] ?? [] as [$key]) {
            $forced[$this->world->profile($key)->id] ??= $plan->startsAt->subDays(2);
        }

        $night = $plan->night();

        foreach ($this->planRsvps($plan, $forced) as [$userId, $at, $leaveAt]) {
            $user = $this->world->userById($userId);

            $timeline->add($at, function () use ($night, $user): void {
                JoinClubNight::handle($night, $user);
            });

            if ($leaveAt !== null) {
                $timeline->add($leaveAt, function () use ($night, $user): void {
                    LeaveClubNight::handle($night, $user);
                });
            }
        }

        $timeline->add($plan->startsAt, function () use ($index, $plan): void {
            $this->runNight($index, $plan);
        });
    }

    /**
     * Who RSVPs (and when, and whether they cancel again): [user id, moment, cancelled at].
     *
     * @param  array<int, CarbonImmutable>  $forced  user id => earliest RSVP moment
     * @return list<array{0: int, 1: CarbonImmutable, 2: CarbonImmutable|null}>
     */
    private function planRsvps(NightPlan $plan, array $forced): array
    {
        $rng = $this->world->plan;
        $end = $plan->startsAt->subMinutes(90);
        $begin = $plan->publishedAt->max($plan->startsAt->subDays(9));
        $rsvps = [];

        foreach ($this->world->profiles as $player) {
            $chance = $this->rsvpChance($player, $plan);
            $isForced = isset($forced[$player->id]);

            if (! $isForced && ($chance <= 0.0 || ! $rng->chance($chance))) {
                continue;
            }

            $at = match (true) {
                $isForced => $this->early($forced[$player->id], $end, 0.3),
                $player->key === 'noor' => $this->early($begin, $end, 0.15),
                default => $this->late($begin, $end),
            };

            $leaveAt = ! $isForced && ! in_array($player->key, ['noor', 'lucas'], true) && $rng->chance(self::LEAVE)
                ? $this->early($at->addMinutes(5), $plan->startsAt->subMinutes(30), 1.0)
                : null;

            $rsvps[] = [$player->id, $at, $leaveAt];
        }

        return $rsvps;
    }

    private function rsvpChance(PlayerProfile $player, NightPlan $plan): float
    {
        if (! $player->availableIn($plan->week)) {
            return 0.0;
        }

        // In their last week, players who left only make the Tuesday.
        if ($player->leftWeek === $plan->week && $plan->day !== 'tue') {
            return 0.0;
        }

        if ($plan->kind === ClubNightKind::Clinic) {
            return match (true) {
                $player->key === 'margaux' => 0.85,
                in_array($player->key, ['daan', 'noor'], true) => 0.3,
                $player->isBeginner() => 0.85,
                $player->level === SkillLevel::Intermediate && $player->joinedWeek >= 0 && $plan->week - $player->joinedWeek <= 2 => 0.3,
                default => 0.0,
            };
        }

        $day = $player->days === 'both' || $player->days === $plan->day ? 1.0 : 0.04;

        $kind = match ($plan->kind) {
            ClubNightKind::Training => match ($player->level) {
                SkillLevel::Competitive => 0.75,
                SkillLevel::Advanced => 1.1,
                SkillLevel::Intermediate => 1.15,
                SkillLevel::Beginner => 0.55,
            },
            ClubNightKind::Ladder => 0.6 + 0.8 * $player->singlesShare,
            default => 1.0,
        };

        $special = $plan->isSpecial() ? 1.3 : 1.0;

        return min(0.97, $player->attendance * $day * $kind * $special * self::ATTENDANCE);
    }

    /**
     * Check-ins at the door, then the night's matches (ratings recalculated once at the end).
     */
    private function runNight(int $index, NightPlan $plan): void
    {
        $world = $this->world;
        $now = $world->calendar->now;
        $challenges = array_values(array_filter(
            $this->challenges[$index] ?? [],
            fn (ChallengePlan $challenge): bool => ! $challenge->isCancelled(),
        ));
        $scripts = self::SCRIPTS[$plan->week.'-'.$plan->day] ?? [];
        $must = [];

        foreach ($challenges as $challenge) {
            array_push($must, ...$challenge->players());
        }

        foreach ($scripts as [$key]) {
            $must[] = $world->profile($key)->id;
        }

        $attendees = [];

        foreach ($plan->night()->rsvps()->orderBy('id')->get() as $rsvp) {
            $sure = in_array($rsvp->user_id, $must, true);

            if ($rsvp->status !== RsvpStatus::Going && ! $sure) {
                continue;
            }

            if (! $sure && $world->profileOf($rsvp->user_id)->key !== 'noor' && $world->play->chance(self::NO_SHOW)) {
                continue;
            }

            $at = $plan->startsAt->subMinutes(12)->addMinutes($world->play->int(0, 38));

            if ($at->greaterThanOrEqualTo($now)) {
                continue;
            }

            $world->travel($at);
            CheckInAttendee::handle($rsvp);
            $attendees[] = $rsvp->user_id;
        }

        // A night that is on right now has check-ins but no results yet.
        if ($plan->endsAt->greaterThan($now)) {
            return;
        }

        $form = [];

        foreach ($attendees as $id) {
            $form[$id] = $world->play->gaussian(0.0, 14.0);
        }

        RatingEngine::deferred(function () use ($plan, $challenges, $scripts, $attendees, $form): void {
            $busy = [];
            $played = [];

            foreach ($challenges as $challenge) {
                $this->playChallenge($plan, $challenge, $form, $busy, $played);
            }

            foreach ($scripts as $script) {
                $this->playScript($plan, $script, $attendees, $form, $busy, $played);
            }

            if ($plan->kind === ClubNightKind::Clinic) {
                $this->playClinic($plan, $attendees, $form, $busy, $played);
            } else {
                $this->playFriendlies($plan, $attendees, $form, $busy, $played, count($challenges) + count($scripts));
            }
        });
    }

    /**
     * @param  array<int, float>  $form
     * @param  array<int, array<int, true>>  $busy  round => user ids on court
     * @param  array<int, int>  $played  user id => matches tonight
     */
    private function playChallenge(NightPlan $plan, ChallengePlan $challenge, array $form, array &$busy, array &$played): void
    {
        $winner = null;
        $accept = null;

        if ($challenge->winnerId !== null) {
            $winner = in_array($challenge->winnerId, $challenge->sideA, true) ? Side::A : Side::B;
            $loser = $winner->opposite()->value;
            // The scripted underdog comes back from a game down.
            $accept = fn (array $games): bool => count($games) === 3 && $games[0][$loser] > $games[0][$winner->value];
        }

        $games = $this->world->simulator->play(
            $this->world->probability($challenge->sideA, $challenge->sideB, $challenge->discipline, $form),
            3,
            21,
            $winner,
            $accept,
        );

        $playedAt = $challenge->scheduledAt->addMinutes(MatchSimulator::minutes($games));
        $this->occupy($plan, $challenge->players(), $this->roundAt($plan, $challenge->scheduledAt), $busy, $played, 2);
        $this->world->travel($this->logMoment($playedAt));

        SaveMatch::handle([
            'status' => MatchStatus::Completed,
            'games' => $games,
            'played_at' => $playedAt,
            'notes' => $challenge->note,
        ], $challenge->created(), $this->world->userById($challenge->sideA[0]));
    }

    /**
     * A demo-account moment: an opponent from the room and a result that earns the badge.
     *
     * @param  array{0: string, 1: 'shutout'|'comeback'|'iron_lungs', 2: Discipline}  $script
     * @param  list<int>  $attendees
     * @param  array<int, float>  $form
     * @param  array<int, array<int, true>>  $busy
     * @param  array<int, int>  $played
     */
    private function playScript(NightPlan $plan, array $script, array $attendees, array $form, array &$busy, array &$played): void
    {
        [$key, $want, $discipline] = $script;
        $hero = $this->world->profile($key);

        if (! in_array($hero->id, $attendees, true)) {
            return;
        }

        $round = 0;

        while (isset($busy[$round][$hero->id])) {
            $round++;
        }

        $others = array_values(array_filter(
            $attendees,
            fn (int $id): bool => $id !== $hero->id && ! isset($busy[$round][$id]),
        ));

        $skill = $hero->skill($discipline);
        $closest = function (array $pool, float $target, ?float $below = null) use ($discipline): ?int {
            $best = null;
            $bestGap = INF;

            foreach ($pool as $id) {
                $other = $this->world->profileOf($id)->skill($discipline);

                if ($below !== null && $other > $below) {
                    continue;
                }

                $gap = abs($other - $target);

                if ($gap < $bestGap) {
                    [$best, $bestGap] = [$id, $gap];
                }
            }

            return $best;
        };

        if ($discipline === Discipline::Singles) {
            $singlesPlayers = array_values(array_filter($others, fn (int $id): bool => $this->world->profileOf($id)->singlesShare >= 0.45));
            $pool = $singlesPlayers !== [] ? $singlesPlayers : $others;

            // A shutout needs a clearly weaker opponent; a comeback needs an even one.
            $opponent = $want === 'shutout'
                ? ($closest($pool, $skill - 320.0, $skill - 150.0) ?? $closest($pool, $skill - 320.0))
                : $closest($pool, $skill - 20.0);

            if ($opponent === null) {
                return;
            }

            $sideA = [$hero->id];
            $sideB = [$opponent];
        } else {
            $partnerKey = $hero->partnerKey;
            $partner = $partnerKey !== null && in_array($this->world->profile($partnerKey)->id, $others, true)
                ? $this->world->profile($partnerKey)->id
                : $closest($others, $skill);

            if ($partner === null) {
                return;
            }

            $rest = array_values(array_diff($others, [$partner]));
            $team = ($skill + $this->world->profileOf($partner)->skill($discipline)) / 2;
            $first = $closest($rest, $team);
            $second = $first === null ? null : $closest(array_values(array_diff($rest, [$first])), $team);

            if ($first === null || $second === null) {
                return;
            }

            $sideA = [$hero->id, $partner];
            $sideB = [$first, $second];
        }

        $accept = match ($want) {
            'shutout' => fn (array $games): bool => array_filter($games, fn (array $game): bool => $game['a'] > $game['b'] && $game['b'] <= 5) !== [],
            'comeback' => fn (array $games): bool => count($games) === 3 && $games[0]['a'] < $games[0]['b'],
            'iron_lungs' => fn (array $games): bool => array_filter($games, fn (array $game): bool => $game['a'] > $game['b'] && $game['a'] > 21) !== [],
        };

        $games = $this->world->simulator->play($this->world->probability($sideA, $sideB, $discipline, $form), 3, 21, Side::A, $accept);
        $this->occupy($plan, [...$sideA, ...$sideB], $round, $busy, $played);
        $this->saveFriendly($plan, $round, $discipline, $sideA, $sideB, $games, 3, 21, true, $this->world->userById($hero->id));
    }

    /**
     * @param  list<int>  $attendees
     * @param  array<int, float>  $form
     * @param  array<int, array<int, true>>  $busy
     * @param  array<int, int>  $played
     */
    private function playFriendlies(NightPlan $plan, array $attendees, array $form, array &$busy, array &$played, int $already): void
    {
        $rng = $this->world->play;
        $kind = match (true) {
            $plan->kind === ClubNightKind::Training => 'training',
            $plan->kind === ClubNightKind::Ladder => 'ladder',
            $plan->isSpecial() => 'special',
            default => 'social',
        };
        $count = (int) round(count($attendees) * self::LOG_RATE[$kind] + $rng->gaussian(0.0, 0.8)) - $already;
        $rounds = $this->rounds($plan);
        $ratings = $kind === 'ladder' ? $this->singlesRatings($attendees) : [];

        for ($k = 0; $k < $count; $k++) {
            $round = ($k + $already) % $rounds;
            $discipline = match ($kind) {
                'ladder' => Discipline::Singles,
                'training' => $rng->chance(0.3) ? Discipline::Singles : Discipline::Doubles,
                default => $rng->chance(0.38) ? Discipline::Singles : Discipline::Doubles,
            };

            $sides = $this->lineUp($discipline, $round, $attendees, $busy, $played, $ratings)
                ?? ($kind === 'ladder' ? null : $this->lineUp($discipline === Discipline::Singles ? Discipline::Doubles : Discipline::Singles, $round, $attendees, $busy, $played, $ratings));

            if ($sides === null) {
                continue;
            }

            [$sideA, $sideB, $discipline] = $sides;
            [$bestOf, $target] = $kind === 'ladder' ? [1, 21] : [3, 21];
            $games = $this->world->simulator->play($this->world->probability($sideA, $sideB, $discipline, $form), $bestOf, $target);

            $this->occupy($plan, [...$sideA, ...$sideB], $round, $busy, $played);
            $this->saveFriendly($plan, $round, $discipline, $sideA, $sideB, $games, $bestOf, $target, true, null);
        }
    }

    /**
     * The clinic ends with an unrated practice game or two between beginners.
     *
     * @param  list<int>  $attendees
     * @param  array<int, float>  $form
     * @param  array<int, array<int, true>>  $busy
     * @param  array<int, int>  $played
     */
    private function playClinic(NightPlan $plan, array $attendees, array $form, array &$busy, array &$played): void
    {
        $beginners = array_values(array_filter($attendees, fn (int $id): bool => $this->world->profileOf($id)->isBeginner()));
        $count = min(2, intdiv(count($beginners), 3));
        $round = $this->rounds($plan) - 1;

        for ($k = 0; $k < $count; $k++) {
            $free = array_values(array_filter($beginners, fn (int $id): bool => ! isset($busy[$round][$id])));
            $discipline = count($free) >= 4 ? Discipline::Doubles : Discipline::Singles;
            $perSide = $discipline->playersPerSide();

            if (count($free) < 2 * $perSide) {
                break;
            }

            $picked = array_slice($this->world->play->shuffle($free), 0, 2 * $perSide);
            $sideA = array_slice($picked, 0, $perSide);
            $sideB = array_slice($picked, $perSide);
            $games = $this->world->simulator->play($this->world->probability($sideA, $sideB, $discipline, $form), 1, 15);

            $this->occupy($plan, $picked, $round, $busy, $played);
            $this->saveFriendly($plan, $round, $discipline, $sideA, $sideB, $games, 1, 15, false, null);
        }
    }

    /**
     * Pick a line-up for one court: players who are free this round and have not played too
     * much tonight, mostly against people of their own level.
     *
     * @param  list<int>  $attendees
     * @param  array<int, array<int, true>>  $busy
     * @param  array<int, int>  $played
     * @param  array<int, float>  $ratings  visible singles ratings (ladder nights)
     * @return array{0: list<int>, 1: list<int>, 2: Discipline}|null
     */
    private function lineUp(Discipline $discipline, int $round, array $attendees, array $busy, array $played, array $ratings): ?array
    {
        $rng = $this->world->play;
        $free = array_values(array_filter(
            $attendees,
            fn (int $id): bool => ! isset($busy[$round][$id]) && ($played[$id] ?? 0) < $this->cap($id),
        ));

        if (count($free) < 2 * $discipline->playersPerSide()) {
            return null;
        }

        $singles = $discipline === Discipline::Singles;
        $weights = [];

        foreach ($free as $id) {
            $weights[$id] = $this->appetite($id, $discipline, $played[$id] ?? 0);
        }

        $first = $rng->weighted($weights);

        if ($first === null) {
            return null;
        }

        unset($weights[$first]);
        $mixing = $rng->chance(self::MIXING);

        $skill = fn (int $id): float => $ratings !== [] ? ($ratings[$id] ?? 1500.0) : $this->world->profileOf($id)->skill($discipline);

        if ($singles) {
            $opponent = $rng->weighted($this->near($skill($first), $weights, $mixing, $discipline, $ratings));

            if ($opponent === null) {
                return null;
            }

            return $rng->chance(0.5) ? [[$first], [$opponent], $discipline] : [[$opponent], [$first], $discipline];
        }

        // Doubles: partners are of a similar level (or a regular pair); the other pair is
        // usually close too, sometimes not.
        $partner = $this->partnerFor($first, $weights);

        if ($partner === null) {
            return null;
        }

        unset($weights[$partner]);
        $team = ($skill($first) + $skill($partner)) / 2;
        $third = $rng->weighted($this->near($team, $weights, $mixing, $discipline, $ratings));

        if ($third === null) {
            return null;
        }

        unset($weights[$third]);
        $fourth = $this->partnerFor($third, $weights);

        if ($fourth === null) {
            return null;
        }

        $sideA = [$first, $partner];
        $sideB = [$third, $fourth];

        if ($rng->chance(self::BALANCED_SPLIT)) {
            // Social doubles: strongest with weakest against the middle two.
            $four = [$first, $partner, $third, $fourth];
            usort($four, fn (int $a, int $b): int => $this->world->profileOf($b)->doublesSkill <=> $this->world->profileOf($a)->doublesSkill);
            $sideA = [$four[0], $four[3]];
            $sideB = [$four[1], $four[2]];
        }

        return $rng->chance(0.5) ? [$sideA, $sideB, $discipline] : [$sideB, $sideA, $discipline];
    }

    /**
     * How keen a player is to play (and log) this discipline tonight. Specialists hardly ever
     * play the other discipline, so they are ranked (and established) in one table only.
     */
    private function appetite(int $userId, Discipline $discipline, int $playedTonight): float
    {
        $player = $this->world->profileOf($userId);
        $share = $player->singlesShare;
        $taste = $discipline === Discipline::Singles
            ? ($share >= 0.45 ? 0.25 + ($share - 0.4) * 2.0 : 0.0)
            : ($share >= 0.6 ? 0.0 : max(0.1, 1.0 - $share));

        return $player->logRate * $taste * (1.0 - 0.3 * $playedTonight);
    }

    /**
     * The pool's weights, scaled by how close each player is to $target (in true skill, or in
     * visible rating on ladder nights). $mixed widens the net: anybody can end up on court.
     *
     * @param  array<int, float>  $pool  user id => appetite
     * @param  array<int, float>  $ratings  visible ratings (ladder nights), else empty
     * @return array<int, float>
     */
    private function near(float $target, array $pool, bool $mixed, Discipline $discipline, array $ratings): array
    {
        $scaled = [];

        foreach ($pool as $id => $weight) {
            $gap = $ratings !== []
                ? abs(($ratings[$id] ?? 1500.0) - $target)
                : abs($this->world->profileOf($id)->skill($discipline) - $target);
            $scale = $ratings !== [] ? 90.0 : ($mixed ? self::MIXED : self::NEAR);
            $scaled[$id] = $weight * (0.02 + exp(-$gap / $scale));
        }

        return $scaled;
    }

    /**
     * A doubles partner for $player from the pool: their regular partner when free (most of
     * the time), otherwise somebody of a similar level.
     *
     * @param  array<int, float>  $pool  user id => appetite
     */
    private function partnerFor(int $player, array $pool): ?int
    {
        $rng = $this->world->play;
        $profile = $this->world->profileOf($player);
        $regular = $profile->partnerKey !== null ? $this->world->profile($profile->partnerKey)->id : null;

        if ($regular !== null && isset($pool[$regular]) && $rng->chance(0.7)) {
            return $regular;
        }

        return $rng->weighted($this->near($profile->doublesSkill, $pool, false, Discipline::Doubles, []));
    }

    /**
     * @param  list<int>  $sideA
     * @param  list<int>  $sideB
     * @param  list<array{a: int, b: int}>  $games
     */
    private function saveFriendly(NightPlan $plan, int $round, Discipline $discipline, array $sideA, array $sideB, array $games, int $bestOf, int $target, bool $rated, ?User $logger): void
    {
        $rng = $this->world->play;
        $start = $plan->startsAt->addMinutes(10 + $round * self::SLOT + $rng->int(0, 5));
        $playedAt = $start->addMinutes(MatchSimulator::minutes($games));
        $latest = $plan->endsAt->subMinutes($rng->int(2, 7));

        if ($playedAt->greaterThan($latest)) {
            $playedAt = $latest;
        }

        $logger ??= $this->world->userById($rng->pick([...$sideA, ...$sideB]));
        $this->world->travel($this->logMoment($playedAt));

        SaveMatch::handle([
            'discipline' => $discipline,
            'side_a' => $sideA,
            'side_b' => $sideB,
            'status' => MatchStatus::Completed,
            'played_at' => $playedAt,
            'venue' => $plan->night()->venue,
            'best_of' => $bestOf,
            'points_per_game' => $target,
            'is_rated' => $rated,
            'notes' => $rated ? $this->note($games, $discipline) : "Practice game at the beginners' clinic, not rated.",
            'games' => $games,
        ], null, $logger);
    }

    /**
     * A short note on about one match in ten.
     *
     * @param  list<array{a: int, b: int}>  $games
     */
    private function note(array $games, Discipline $discipline): ?string
    {
        $rng = $this->world->play;

        if (! $rng->chance(0.1)) {
            return null;
        }

        foreach ($games as $number => $game) {
            if (max($game['a'], $game['b']) === 30) {
                return sprintf('Golden point at 29-all in game %d. Nobody in the hall was breathing.', $number + 1);
            }
        }

        if (count($games) === 3) {
            return $rng->pick(['Went the distance. Rematch already booked.', 'Third-game thriller, decided in the last few rallies.']);
        }

        if ($discipline === Discipline::Doubles && $rng->chance(0.4)) {
            return 'Mixed it up: partners drawn from a hat.';
        }

        return $rng->pick(self::GENERIC_NOTES);
    }

    // ------------------------------------------------------------------
    // Challenge matches
    // ------------------------------------------------------------------

    /**
     * @return list<ChallengePlan>
     */
    private function planChallenges(NightPlan $plan): array
    {
        if (in_array($plan->kind, [ClubNightKind::Training, ClubNightKind::Clinic], true) || $plan->flavour === 'opener') {
            return [];
        }

        $announcedAt = $plan->startsAt->subDays(3)->setTime(12, 0)->addMinutes($this->world->plan->int(0, 90));

        if ($plan->day === 'thu' && $plan->week === 7) {
            // Femke's ankle: the only challenge that never happened.
            return [new ChallengePlan(
                discipline: Discipline::Singles,
                sideA: [$this->world->profile('femke')->id],
                sideB: [$this->world->profile('elise')->id],
                scheduledAt: $plan->startsAt->addMinutes(45),
                court: 1,
                announcedAt: $announcedAt,
                announcedBy: $this->nextAnnouncer(),
                cancelledAt: $plan->startsAt->subDay()->setTime(9, 15),
                cancelNote: 'Called off: Femke rolled her ankle on Wednesday. Get well soon, Femke.',
            )];
        }

        $challenges = [];

        if ($plan->flavour === 'halloween') {
            $challenges[] = new ChallengePlan(
                discipline: Discipline::Doubles,
                sideA: [$this->world->profile('lucas')->id, $this->world->profile('mateo')->id],
                sideB: [$this->world->profile('putri')->id, $this->world->profile('rizky')->id],
                scheduledAt: $plan->startsAt->addMinutes(45),
                court: 1,
                announcedAt: $announcedAt,
                announcedBy: $this->nextAnnouncer(),
                winnerId: $this->world->profile('lucas')->id,
                note: 'Halloween challenge on court 1. Lucas and Mateo came back from a game down against the top pair.',
            );
        }

        $random = $this->randomChallenge($plan, $plan->startsAt->addMinutes($challenges === [] ? 45 : 75), $challenges === [] ? 1 : 2, $announcedAt, $challenges);

        if ($random !== null) {
            $challenges[] = $random;
        }

        foreach ($challenges as $challenge) {
            $this->usedMatchups[$challenge->key()] = true;
        }

        return $challenges;
    }

    /**
     * Two evenly matched regulars (or regular pairs) for a challenge on court 1.
     *
     * @param  list<ChallengePlan>  $taken
     */
    private function randomChallenge(NightPlan $plan, CarbonImmutable $scheduledAt, int $court, CarbonImmutable $announcedAt, array $taken): ?ChallengePlan
    {
        $rng = $this->world->plan;
        $busy = [];

        foreach ($taken as $challenge) {
            array_push($busy, ...$challenge->players());
        }

        $doubles = $plan->kind !== ClubNightKind::Ladder && $rng->chance(0.45);

        $candidates = array_filter(
            $this->world->profiles,
            fn (PlayerProfile $player): bool => $player->isActive()
                && $player->availableIn($plan->week)
                && ! $player->isBeginner()
                && $player->attendance >= 0.42
                && ($doubles || $player->singlesShare >= 0.45)
                && ($player->days === 'both' || $player->days === $plan->day)
                && ! in_array($player->id, $busy, true),
        );

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $sides = $doubles ? $this->challengePairs($candidates) : $this->challengeSingles($candidates);

            if ($sides === null) {
                continue;
            }

            $challenge = new ChallengePlan(
                discipline: $doubles ? Discipline::Doubles : Discipline::Singles,
                sideA: $sides[0],
                sideB: $sides[1],
                scheduledAt: $scheduledAt,
                court: $court,
                announcedAt: $announcedAt,
                announcedBy: $this->nextAnnouncer(),
            );

            if (! isset($this->usedMatchups[$challenge->key()])) {
                return $challenge;
            }
        }

        return null;
    }

    /**
     * @param  array<string, PlayerProfile>  $candidates
     * @return array{0: list<int>, 1: list<int>}|null
     */
    private function challengeSingles(array $candidates): ?array
    {
        $rng = $this->world->plan;
        $weights = [];

        foreach ($candidates as $key => $player) {
            $weights[$key] = $player->attendance * $player->logRate * (0.3 + $player->singlesShare);
        }

        $first = $rng->weighted($weights);

        if ($first === null) {
            return null;
        }

        $skill = $candidates[$first]->singlesSkill;
        $opponents = [];

        foreach ($candidates as $key => $player) {
            $gap = abs($player->singlesSkill - $skill);

            if ($key !== $first && $gap <= 110.0) {
                $opponents[$key] = exp(-$gap / 60.0) * (0.3 + $player->singlesShare);
            }
        }

        $second = $rng->weighted($opponents);

        return $second === null ? null : [[$candidates[$first]->id], [$candidates[$second]->id]];
    }

    /**
     * @param  array<string, PlayerProfile>  $candidates
     * @return array{0: list<int>, 1: list<int>}|null
     */
    private function challengePairs(array $candidates): ?array
    {
        $rng = $this->world->plan;
        $pairs = [];

        foreach ($candidates as $key => $player) {
            $partner = $player->partnerKey;

            if ($partner !== null && $key < $partner && isset($candidates[$partner])) {
                $pairs[] = [$player, $candidates[$partner]];
            }
        }

        if (count($pairs) < 2) {
            return null;
        }

        $strength = fn (array $pair): float => ($pair[0]->doublesSkill + $pair[1]->doublesSkill) / 2;
        $first = $rng->int(0, count($pairs) - 1);
        $weights = [];

        foreach ($pairs as $index => $pair) {
            $gap = abs($strength($pair) - $strength($pairs[$first]));

            if ($index !== $first && $gap <= 130.0) {
                $weights[$index] = exp(-$gap / 70.0);
            }
        }

        $second = $rng->weighted($weights);

        if ($second === null) {
            return null;
        }

        return [
            [$pairs[$first][0]->id, $pairs[$first][1]->id],
            [$pairs[$second][0]->id, $pairs[$second][1]->id],
        ];
    }

    private function announce(ChallengePlan $challenge): void
    {
        $challenge->match = SaveMatch::handle([
            'discipline' => $challenge->discipline,
            'side_a' => $challenge->sideA,
            'side_b' => $challenge->sideB,
            'status' => MatchStatus::Scheduled,
            'scheduled_at' => $challenge->scheduledAt,
            'court' => $challenge->court,
            'venue' => config()->string('smash.club.venue', 'BUas Sports Hall'),
            'best_of' => 3,
            'points_per_game' => 21,
            'is_rated' => true,
        ], null, $this->world->userById($challenge->announcedBy));

        $this->world->announce($challenge->match->id, $challenge->announcedAt);
    }

    private function cancel(ChallengePlan $challenge): void
    {
        SaveMatch::handle([
            'status' => MatchStatus::Cancelled,
            'notes' => $challenge->cancelNote,
        ], $challenge->created(), $this->world->chair());
    }

    private function nextAnnouncer(): int
    {
        $keys = ['noor', 'priya', 'daan'];

        return $this->world->profile($keys[$this->announcer++ % count($keys)])->id;
    }

    // ------------------------------------------------------------------
    // Upcoming nights
    // ------------------------------------------------------------------

    private function scheduleUpcoming(Timeline $timeline, NightPlan $plan, int $nth): void
    {
        $world = $this->world;
        $rng = $world->plan;
        $now = $world->calendar->now;
        $night = $plan->night();
        $challenges = $this->upcomingChallenges($plan, $nth);
        $forced = [];

        foreach ($challenges as $challenge) {
            $timeline->add($challenge->announcedAt, function () use ($challenge): void {
                $this->announce($challenge);
            });

            foreach ($challenge->players() as $id) {
                $forced[$id] ??= $challenge->announcedAt;
            }
        }

        $windowEnd = $now->subMinutes(15)->min($plan->startsAt->subHour());
        $windowStart = $plan->publishedAt->max($plan->startsAt->subDays(21))->min($windowEnd->subDay());
        $daysAhead = ($plan->startsAt->getTimestamp() - $now->getTimestamp()) / 86400;

        if ($daysAhead <= 21) {
            $forced[$world->profile('noor')->id] ??= $windowStart;
        }

        $lucas = $world->profile('lucas')->id;
        // Lucas is first on the waitlist for the next Tuesday training.
        $lucasWaits = $plan->day === 'tue' && $nth === 1 && $plan->waitlistTarget > 0;
        $entries = [];

        foreach ($forced as $id => $from) {
            $entries[$id] = $this->early($from->max($windowStart), $windowEnd, 0.3);
        }

        if ($plan->waitlistTarget > 0) {
            $target = $plan->capacity + $plan->waitlistTarget - ($lucasWaits ? 1 : 0);
            $weights = [];

            foreach ($world->profiles as $player) {
                if (isset($entries[$player->id]) || ($lucasWaits && $player->id === $lucas)) {
                    continue;
                }

                $chance = $this->rsvpChance($player, $plan);

                if ($chance > 0.0) {
                    $weights[$player->id] = $chance;
                }
            }

            while (count($entries) < $target) {
                $id = $rng->weighted($weights);

                if ($id === null) {
                    break;
                }

                unset($weights[$id]);
                $entries[$id] = $this->early($windowStart, $windowEnd->subHours(4), 1.0);
            }

            if ($lucasWaits) {
                asort($entries);
                $position = 0;

                foreach (array_keys($entries) as $id) {
                    if (++$position > $plan->capacity) {
                        $entries[$id] = $windowEnd->subMinutes(90 - 10 * ($position - $plan->capacity));
                    }
                }

                $entries[$lucas] = $windowEnd->subHours(2);
            }
        } else {
            $fill = match (true) {
                $daysAhead <= 3 => 0.9,
                $daysAhead <= 7 => 0.75,
                $daysAhead <= 14 => 0.45,
                $daysAhead <= 21 => 0.25,
                $daysAhead <= 28 => 0.14,
                default => 0.08,
            };

            foreach ($world->profiles as $player) {
                if (isset($entries[$player->id])) {
                    continue;
                }

                $chance = $this->rsvpChance($player, $plan) * $fill;

                if ($chance > 0.0 && $rng->chance($chance)) {
                    $entries[$player->id] = $this->late($windowStart, $windowEnd);
                }
            }
        }

        foreach ($this->newcomers($plan, $nth) as $key) {
            $entries[$world->profile($key)->id] = $windowEnd->subMinutes($rng->int(10, 200));
        }

        foreach ($entries as $id => $at) {
            $user = $world->userById($id);

            $timeline->add($at, function () use ($night, $user): void {
                JoinClubNight::handle($night, $user);
            });
        }
    }

    /**
     * Challenge matches already announced for the coming nights (open for pick'em).
     *
     * @return list<ChallengePlan>
     */
    private function upcomingChallenges(NightPlan $plan, int $nth): array
    {
        $specs = match (true) {
            $plan->day === 'thu' && $nth === 1 => [
                [Discipline::Singles, ['lucas'], ['emre'], 30, 1],
                [Discipline::Doubles, ['noor', 'sanne'], ['meiling', 'linh'], 60, 1],
            ],
            $plan->day === 'thu' && $nth === 2 => [
                [Discipline::Singles, ['arjun'], ['jonas'], 30, 1],
                [Discipline::Doubles, ['kacper', 'zuzanna'], ['felix', 'lena'], 60, 2],
            ],
            $plan->day === 'tue' && $nth === 2 => [
                [Discipline::Singles, ['wei'], ['mads'], 45, 1],
            ],
            default => [],
        };

        $now = $this->world->calendar->now;
        $announcedAt = $plan->startsAt->subDays(5)->setTime(12, 30)->min($now->subHours(5));
        $challenges = [];

        foreach ($specs as [$discipline, $a, $b, $offset, $court]) {
            $challenges[] = new ChallengePlan(
                discipline: $discipline,
                sideA: array_map(fn (string $key): int => $this->world->profile($key)->id, $a),
                sideB: array_map(fn (string $key): int => $this->world->profile($key)->id, $b),
                scheduledAt: $plan->startsAt->addMinutes($offset),
                court: $court,
                announcedAt: $announcedAt->addMinutes(7 * count($challenges)),
                announcedBy: $this->nextAnnouncer(),
            );
        }

        return $challenges;
    }

    /**
     * Brand-new members have RSVP'd to a few upcoming nights (but played nothing yet).
     *
     * @return list<string>
     */
    private function newcomers(NightPlan $plan, int $nth): array
    {
        return match (true) {
            $plan->day === 'sun' && $nth === 1 => ['sofia', 'yara'],
            $plan->day === 'thu' && $nth === 2 => ['tom'],
            $plan->flavour === 'christmas' => ['lukas'],
            default => [],
        };
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Matches a player plays at most on one night.
     */
    private function cap(int $userId): int
    {
        return $this->world->profileOf($userId)->key === 'noor' ? 4 : 3;
    }

    private function rounds(NightPlan $plan): int
    {
        $minutes = (int) (($plan->endsAt->getTimestamp() - $plan->startsAt->getTimestamp()) / 60);

        return max(1, intdiv($minutes - 14, self::SLOT));
    }

    private function roundAt(NightPlan $plan, CarbonImmutable $moment): int
    {
        $minutes = (int) (($moment->getTimestamp() - $plan->startsAt->getTimestamp()) / 60) - 10;

        return max(0, intdiv(max(0, $minutes), self::SLOT));
    }

    /**
     * @param  list<int>  $players
     * @param  array<int, array<int, true>>  $busy
     * @param  array<int, int>  $played
     */
    private function occupy(NightPlan $plan, array $players, int $round, array &$busy, array &$played, int $length = 1): void
    {
        foreach ($players as $id) {
            for ($r = $round; $r < $round + $length; $r++) {
                $busy[$r][$id] = true;
            }

            $played[$id] = ($played[$id] ?? 0) + 1;
        }
    }

    /**
     * Current singles ratings of tonight's players (the ladder pairs people near each other).
     *
     * @param  list<int>  $userIds
     * @return array<int, float>
     */
    private function singlesRatings(array $userIds): array
    {
        $ratings = [];

        foreach (User::query()->whereKey($userIds)->get(['id', 'singles_rating']) as $user) {
            $ratings[$user->id] = $user->singles_rating;
        }

        return $ratings;
    }

    /**
     * Results are logged a few minutes after the last rally (never in the future).
     */
    private function logMoment(CarbonImmutable $playedAt): CarbonImmutable
    {
        return $playedAt->addMinutes($this->world->play->int(1, 7))->min($this->world->calendar->now);
    }

    /**
     * A moment in the first $fraction of [$from, $to].
     */
    private function early(CarbonImmutable $from, CarbonImmutable $to, float $fraction): CarbonImmutable
    {
        $span = max(0, $to->getTimestamp() - $from->getTimestamp());

        return $from->addSeconds((int) ($span * $fraction * $this->world->plan->float()));
    }

    /**
     * A moment in [$from, $to], more likely close to $to (most people RSVP in the last days).
     */
    private function late(CarbonImmutable $from, CarbonImmutable $to): CarbonImmutable
    {
        $span = max(0, $to->getTimestamp() - $from->getTimestamp());
        $u = $this->world->plan->float();

        return $to->subSeconds((int) ($span * $u * $u));
    }
}
