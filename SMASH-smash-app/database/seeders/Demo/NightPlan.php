<?php

namespace Database\Seeders\Demo;

use App\Enums\ClubNightKind;
use App\Enums\SkillLevel;
use App\Models\ClubNight;
use Carbon\CarbonImmutable;

/**
 * One club night in the demo calendar (the row is created from it).
 */
final class NightPlan
{
    public ?ClubNight $model = null;

    /**
     * @param  'tue'|'thu'|'sun'  $day
     * @param  'regular'|'opener'|'halloween'|'christmas'  $flavour
     * @param  int  $waitlistTarget  for upcoming nights: fill it up and put this many on the waitlist (0 = not full)
     */
    public function __construct(
        public readonly int $week,
        public readonly string $day,
        public readonly ClubNightKind $kind,
        public readonly string $title,
        public readonly string $description,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
        public readonly int $courts,
        public readonly int $capacity,
        public readonly ?SkillLevel $level,
        public readonly string $flavour,
        public readonly int $waitlistTarget,
        public readonly CarbonImmutable $publishedAt,
    ) {}

    public function night(): ClubNight
    {
        return $this->model ?? throw new \LogicException('The club night has not been created yet.');
    }

    public function isPast(CarbonImmutable $now): bool
    {
        return $this->endsAt->lessThanOrEqualTo($now);
    }

    public function isUpcoming(CarbonImmutable $now): bool
    {
        return $this->startsAt->greaterThan($now);
    }

    public function isSpecial(): bool
    {
        return $this->flavour !== 'regular';
    }
}
