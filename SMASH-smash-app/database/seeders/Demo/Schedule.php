<?php

namespace Database\Seeders\Demo;

use App\Enums\ClubNightKind;
use App\Enums\SkillLevel;
use Carbon\CarbonImmutable;

/**
 * The club-night calendar: Tuesday 19:30–22:00 (training on even weeks, social on odd weeks),
 * Thursday 20:00–22:30 (social, a ladder night every fourth week, Halloween social in week 8)
 * and an occasional Sunday beginners' clinic, from the season start to about six weeks ahead.
 * The last Tuesday is the Christmas social; there is no Thursday in that week.
 */
final class Schedule
{
    /**
     * Season weeks with a Sunday beginners' clinic.
     */
    private const CLINIC_WEEKS = [0, 2, 5, 7, 11, 13];

    private const TRAINING_FOCUS = [
        ['footwork', 'footwork and movement around the court'],
        ['net play', 'net play: tight spinning net shots, net kills and lifts under pressure'],
        ['defence', 'defence and drives: blocking smashes and turning defence into attack'],
        ['serve & return', 'the serve and return game, in doubles and singles'],
        ['doubles rotation', 'doubles rotation: when to attack side by side and when to go front and back'],
        ['smash & follow-up', 'the smash and the follow-up to the net'],
        ['deception', 'deception: holding the shot, slices and reverse drops'],
        ['match play', 'match play: condition games with a coach on every court'],
    ];

    /**
     * @return list<NightPlan> in date order
     */
    public static function build(Calendar $calendar): array
    {
        $lastWeek = $calendar->lastWeek();
        $secondBlock = $calendar->at(6, 2, '12:00');
        $plans = [];

        for ($week = 0; $week <= $lastWeek; $week++) {
            $published = $week < 8 ? $calendar->seasonStart->subDays(16)->setTime(11, 0) : $secondBlock;

            $plans[] = self::tuesday($calendar, $week, $lastWeek, $published);

            if ($week < $lastWeek) {
                $plans[] = self::thursday($calendar, $week, $published);
            }

            if (in_array($week, self::CLINIC_WEEKS, true)) {
                $plans[] = new NightPlan(
                    week: $week,
                    day: 'sun',
                    kind: ClubNightKind::Clinic,
                    title: "Beginners' clinic",
                    description: 'A relaxed introduction for beginners: grip, serve, the basic strokes and how scoring works. Rackets and shuttles are provided, so just bring indoor shoes.',
                    startsAt: $calendar->at($week, 5, '14:00'),
                    endsAt: $calendar->at($week, 5, '16:00'),
                    courts: 3,
                    capacity: 16,
                    level: SkillLevel::Beginner,
                    flavour: 'regular',
                    waitlistTarget: 0,
                    publishedAt: $published,
                );
            }
        }

        return self::markFullNights($plans, $calendar->now);
    }

    private static function tuesday(Calendar $calendar, int $week, int $lastWeek, CarbonImmutable $published): NightPlan
    {
        $startsAt = $calendar->at($week, 0, '19:30');
        $endsAt = $calendar->at($week, 0, '22:00');

        [$kind, $title, $description, $capacity, $level, $flavour] = match (true) {
            $week === 0 => [
                ClubNightKind::Social, 'Season opener',
                'First night of the season. Bring a racket (or borrow one of ours), meet the committee and play as much as you like. New members very welcome.',
                28, null, 'opener',
            ],
            $week === $lastWeek && $week >= 10 => [
                ClubNightKind::Social, 'Christmas social',
                'Last night before the break: a mixed doubles mini-tournament with partners drawn from a hat, then mulled wine (and juice) in the canteen.',
                32, null, 'christmas',
            ],
            $week % 2 === 0 => self::training($week),
            default => [
                ClubNightKind::Social, 'Tuesday social',
                'Open play for every level. Put your name on the board, rotate on and off court and mix up your partners.',
                24, null, 'regular',
            ],
        };

        return new NightPlan(
            week: $week,
            day: 'tue',
            kind: $kind,
            title: $title,
            description: $description,
            startsAt: $startsAt,
            endsAt: $endsAt,
            courts: 4,
            capacity: $capacity,
            level: $level,
            flavour: $flavour,
            waitlistTarget: 0,
            publishedAt: $published,
        );
    }

    /**
     * @return array{0: ClubNightKind, 1: string, 2: string, 3: int, 4: SkillLevel|null, 5: 'regular'}
     */
    private static function training(int $week): array
    {
        [$short, $long] = self::TRAINING_FOCUS[intdiv($week, 2) % count(self::TRAINING_FOCUS)];

        return [
            ClubNightKind::Training,
            'Tuesday training: '.$short,
            sprintf('Coached session on %s. Warm-up at 19:30 sharp, drills until 21:00, then condition games. Best for intermediate players and up.', $long),
            20,
            SkillLevel::Intermediate,
            'regular',
        ];
    }

    private static function thursday(Calendar $calendar, int $week, CarbonImmutable $published): NightPlan
    {
        [$kind, $title, $description, $capacity, $flavour] = match (true) {
            $week === 8 => [
                ClubNightKind::Social, 'Halloween social',
                'Costumes optional, competitive spirit mandatory. Prizes for the best-dressed doubles pair.',
                28, 'halloween',
            ],
            $week % 4 === 1 => [
                ClubNightKind::Ladder, 'Ladder night',
                'Challenge players near you in the rankings: one game to 21 per match. Log your results before you leave so the ladder moves tonight.',
                24, 'regular',
            ],
            default => [
                ClubNightKind::Social, 'Thursday social',
                'Open play for every level, with a challenge match or two on court 1. Stay for a drink afterwards.',
                24, 'regular',
            ],
        };

        return new NightPlan(
            week: $week,
            day: 'thu',
            kind: $kind,
            title: $title,
            description: $description,
            startsAt: $calendar->at($week, 2, '20:00'),
            endsAt: $calendar->at($week, 2, '22:30'),
            courts: 4,
            capacity: $capacity,
            level: null,
            flavour: $flavour,
            waitlistTarget: 0,
            publishedAt: $published,
        );
    }

    /**
     * The next Tuesday and the next Thursday, and the Christmas social, are already full with a waitlist.
     *
     * @param  list<NightPlan>  $plans
     * @return list<NightPlan>
     */
    private static function markFullNights(array $plans, CarbonImmutable $now): array
    {
        $full = [];

        foreach (['tue' => 2, 'thu' => 3] as $day => $waitlist) {
            foreach ($plans as $index => $plan) {
                if ($plan->day === $day && $plan->isUpcoming($now)) {
                    $full[$index] = $waitlist;

                    break;
                }
            }
        }

        foreach ($plans as $index => $plan) {
            if ($plan->flavour === 'christmas' && $plan->isUpcoming($now)) {
                $full[$index] = 4;
            }
        }

        foreach ($full as $index => $waitlist) {
            $plan = $plans[$index];
            $plans[$index] = new NightPlan(
                week: $plan->week,
                day: $plan->day,
                kind: $plan->kind,
                title: $plan->title,
                description: $plan->description,
                startsAt: $plan->startsAt,
                endsAt: $plan->endsAt,
                courts: $plan->courts,
                capacity: $plan->capacity,
                level: $plan->level,
                flavour: $plan->flavour,
                waitlistTarget: $waitlist,
                publishedAt: $plan->publishedAt,
            );
        }

        return array_values($plans);
    }
}
