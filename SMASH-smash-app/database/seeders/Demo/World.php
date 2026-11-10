<?php

namespace Database\Seeders\Demo;

use App\Enums\Discipline;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Shared state of the demo world while it is being built: the calendar, the players (profiles
 * and user rows), the club nights, the random streams and the simulated clock.
 *
 * Every concern draws from its own seeded stream, so tuning one part (say, predictions) never
 * changes the results of another (the matches).
 */
final class World
{
    /**
     * Elo scale of the hidden skills (the public ratings use 400).
     */
    public const TRUE_SCALE = 225.0;

    public readonly Calendar $calendar;

    /**
     * @var array<string, PlayerProfile> by key, in user id order
     */
    public readonly array $profiles;

    /**
     * @var array<int, PlayerProfile> by user id
     */
    public array $byId = [];

    /**
     * @var array<int, User> by user id
     */
    public array $users = [];

    /**
     * @var list<NightPlan>
     */
    public array $nights = [];

    /**
     * Attendance, RSVP times and challenge line-ups (decided up-front).
     */
    public readonly Rng $plan;

    /**
     * Match-making, times and notes on the night.
     */
    public readonly Rng $play;

    /**
     * Pick'em predictions.
     */
    public readonly Rng $picks;

    public readonly MatchSimulator $simulator;

    /**
     * Matches open for pick'em: match id => when the line-up became known.
     *
     * @var array<int, CarbonImmutable>
     */
    public array $announced = [];

    /**
     * The clock the caller had set (tests), restored afterwards.
     */
    private readonly mixed $originalTestNow;

    /**
     * @param  int  $variant  0 for the demo world; other numbers give alternative worlds (same rules, other dice)
     */
    public function __construct(CarbonImmutable $now, int $variant = 0)
    {
        $this->originalTestNow = Carbon::getTestNow();
        $this->calendar = new Calendar($now);
        $this->profiles = Roster::build(new Rng(20260901 + $variant));
        $this->plan = new Rng(1109 + 7919 * $variant);
        $this->play = new Rng(2604 + 7919 * $variant);
        $this->picks = new Rng(3317 + 7919 * $variant);
        $this->simulator = new MatchSimulator(new Rng(4421 + 7919 * $variant));
    }

    /**
     * Set the clock (now(), timestamps, "is it in the future?") to $moment.
     */
    public function travel(CarbonImmutable $moment): void
    {
        Carbon::setTestNow($moment);
    }

    public function travelBack(): void
    {
        Carbon::setTestNow($this->originalTestNow);
    }

    public function profile(string $key): PlayerProfile
    {
        return $this->profiles[$key] ?? throw new LogicException("Unknown demo player [{$key}].");
    }

    public function profileOf(int $userId): PlayerProfile
    {
        return $this->byId[$userId] ?? throw new LogicException("Unknown demo user [{$userId}].");
    }

    public function user(string $key): User
    {
        return $this->userById($this->profile($key)->id);
    }

    public function userById(int $id): User
    {
        return $this->users[$id] ?? throw new LogicException("Unknown demo user [{$id}].");
    }

    /**
     * The club chair (user #1), who runs the draws and enters tournament results.
     */
    public function chair(): User
    {
        return $this->user('noor');
    }

    /**
     * Mean true skill of a side, with tonight's form per player (Elo points) when given.
     *
     * @param  list<int>  $userIds
     * @param  array<int, float>  $form
     */
    public function teamSkill(array $userIds, Discipline $discipline, array $form = []): float
    {
        if ($userIds === []) {
            return 1500.0;
        }

        $total = 0.0;
        $week = $this->calendar->weekOf(CarbonImmutable::now());

        foreach ($userIds as $id) {
            $total += $this->profileOf($id)->skill($discipline, $week) + ($form[$id] ?? 0.0);
        }

        return $total / count($userIds);
    }

    /**
     * True chance that side A beats side B. Hidden skill differences decide matches more
     * firmly than the public Elo scale (400) assumes, so ratings spread out as the season goes.
     *
     * @param  list<int>  $sideA
     * @param  list<int>  $sideB
     * @param  array<int, float>  $form
     */
    public function probability(array $sideA, array $sideB, Discipline $discipline, array $form = []): float
    {
        return MatchSimulator::expected(
            $this->teamSkill($sideA, $discipline, $form),
            $this->teamSkill($sideB, $discipline, $form),
            self::TRUE_SCALE,
        );
    }

    /**
     * Open the match for predictions from $at (the moment its line-up was known).
     */
    public function announce(int $matchId, CarbonImmutable $at): void
    {
        $this->announced[$matchId] ??= $at;
    }
}
