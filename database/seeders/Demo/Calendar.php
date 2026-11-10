<?php

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Every date in the demo world, anchored on the moment the seed runs (Europe/Amsterdam), so a
 * re-seed on another day gives the same story shifted in time: the season started on the
 * Tuesday about nine weeks ago, club nights run up to six weeks ahead and the Halloween Smash
 * quarter-finals are always four days away.
 */
final class Calendar
{
    /**
     * Weeks of club nights from the season start (week 0) to about six weeks ahead.
     */
    public const WEEKS_AHEAD = 6;

    public readonly CarbonImmutable $now;

    public readonly CarbonImmutable $today;

    /**
     * Tuesday 00:00 of the first week of the season.
     */
    public readonly CarbonImmutable $seasonStart;

    public function __construct(CarbonImmutable $now)
    {
        $this->now = $now->startOfMinute();
        $this->today = $now->startOfDay();

        $start = $this->today->subWeeks(9);
        $this->seasonStart = $start->isTuesday() ? $start : $start->previous(CarbonInterface::TUESDAY);
    }

    /**
     * Whole weeks since the season started (9 on the first seeding day).
     */
    public function weeksElapsed(): int
    {
        return intdiv((int) $this->seasonStart->diffInDays($this->today), 7);
    }

    /**
     * The last week (index) that has club nights.
     */
    public function lastWeek(): int
    {
        return $this->weeksElapsed() + self::WEEKS_AHEAD;
    }

    /**
     * $days after the Tuesday of $week, at $time ("19:30").
     */
    public function at(int $week, int $days, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $this->seasonStart->addWeeks($week)->addDays($days)->setTime($hour, $minute);
    }

    /**
     * The week index a moment falls in (negative before the season).
     */
    public function weekOf(CarbonImmutable $moment): int
    {
        return (int) floor($this->seasonStart->diffInDays($moment, false) / 7);
    }

    /**
     * Welcome Week Open: the second Saturday of the season.
     */
    public function welcomeWeekOpen(): CarbonImmutable
    {
        return $this->at(1, 4, '10:00');
    }

    /**
     * Autumn Doubles Cup: the Saturday of week 5.
     */
    public function autumnDoublesCup(): CarbonImmutable
    {
        return $this->at(5, 4, '10:00');
    }

    /**
     * Halloween Smash day one (round of 32 and round of 16): three days ago.
     */
    public function halloweenStart(): CarbonImmutable
    {
        return $this->today->subDays(3)->setTime(10, 0);
    }

    /**
     * Halloween Smash finals day (quarter-finals, semi-finals, final): four days ahead.
     */
    public function halloweenFinalsDay(): CarbonImmutable
    {
        return $this->today->addDays(4)->setTime(10, 0);
    }

    /**
     * Winter Doubles Showdown: the first Saturday at least 38 days ahead (12 Dec on 3 Nov).
     */
    public function winterDoubles(): CarbonImmutable
    {
        return $this->saturdayFrom($this->today->addDays(38))->setTime(10, 0);
    }

    /**
     * New Year Ladder Classic: the first Saturday at least 73 days ahead (mid-January).
     */
    public function newYearLadder(): CarbonImmutable
    {
        return $this->saturdayFrom($this->today->addDays(73))->setTime(10, 0);
    }

    private function saturdayFrom(CarbonImmutable $day): CarbonImmutable
    {
        return $day->isSaturday() ? $day : $day->next(CarbonInterface::SATURDAY);
    }
}
