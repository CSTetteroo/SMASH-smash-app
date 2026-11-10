<?php

namespace Database\Seeders\Demo;

use App\Enums\Discipline;
use App\Enums\Handedness;
use App\Enums\Programme;
use App\Enums\SkillLevel;

/**
 * One demo player: who they are, plus the hidden behaviour that drives the simulation
 * (true skill per discipline, how often they come, what they like to play, how they predict).
 */
final class PlayerProfile
{
    /**
     * Database id, set once the user row exists.
     */
    public int $id = 0;

    /**
     * @param  string  $key  short handle used by the scripts ("noor", "lucas", …)
     * @param  int  $joinedWeek  first season week the player can attend (negative: member before the season)
     * @param  int|null  $leftWeek  last week attended; the player is inactive afterwards
     * @param  bool  $isNew  brand-new member: joined in the last few days, no matches yet
     * @param  float  $attendance  base chance of RSVPing to a regular club night
     * @param  'both'|'tue'|'thu'  $days  nights the player can make
     * @param  float  $singlesShare  share of their logged matches that are singles
     * @param  float  $logRate  how many matches they play and log on a night (relative)
     * @param  string|null  $partnerKey  regular doubles partner
     * @param  float  $predictActivity  chance of predicting an announced match (0 = never predicts)
     * @param  float  $predictSharpness  chance a pick is the true favourite
     * @param  float  $trend  skill change per season week (improving players are positive)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $email,
        public readonly Programme $programme,
        public readonly SkillLevel $level,
        public readonly Handedness $handedness,
        public readonly ?string $bio,
        public readonly bool $admin,
        public readonly int $joinedWeek,
        public readonly ?int $leftWeek,
        public readonly bool $isNew,
        public readonly float $attendance,
        public readonly string $days,
        public readonly float $singlesShare,
        public readonly float $logRate,
        public readonly float $singlesSkill,
        public readonly float $doublesSkill,
        public readonly ?string $partnerKey,
        public readonly float $predictActivity,
        public readonly float $predictSharpness,
        public readonly float $trend = 0.0,
    ) {}

    /**
     * True skill in season week $week (the base skill plus the player's trend since week 0).
     */
    public function skill(Discipline $discipline, ?int $week = null): float
    {
        $base = $discipline === Discipline::Singles ? $this->singlesSkill : $this->doublesSkill;

        return $base + $this->trend * max(0, $week ?? 0);
    }

    /**
     * Plays (and attends) in season week $week.
     */
    public function availableIn(int $week): bool
    {
        return ! $this->isNew
            && $week >= $this->joinedWeek
            && ($this->leftWeek === null || $week <= $this->leftWeek);
    }

    public function isActive(): bool
    {
        return $this->leftWeek === null;
    }

    public function isBeginner(): bool
    {
        return $this->level === SkillLevel::Beginner;
    }
}
