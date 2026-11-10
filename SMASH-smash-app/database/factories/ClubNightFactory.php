<?php

namespace Database\Factories;

use App\Enums\ClubNightKind;
use App\Enums\RsvpStatus;
use App\Enums\SkillLevel;
use App\Models\ClubNight;
use App\Models\ClubNightRsvp;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A social night next week by default (19:30–22:00, 4 courts, 24 places).
 * The title and end time follow the kind and start unless given explicitly.
 *
 * @extends Factory<ClubNight>
 */
class ClubNightFactory extends Factory
{
    /**
     * @var class-string<ClubNight>
     */
    protected $model = ClubNight::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fn (array $attributes) => self::titleFor($attributes['kind'], $attributes['starts_at']),
            'kind' => ClubNightKind::Social,
            'description' => null,
            'venue' => config('smash.club.venue'),
            'starts_at' => $this->evening(fake()->numberBetween(1, 7)),
            'ends_at' => fn (array $attributes) => CarbonImmutable::parse($attributes['starts_at'])->addMinutes(150),
            'courts' => 4,
            'capacity' => 24,
            'level' => null,
            'created_by' => null,
        ];
    }

    /**
     * Starting within the next two weeks.
     */
    public function upcoming(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => $this->evening(fake()->numberBetween(1, 14)),
        ]);
    }

    /**
     * Ended some days ago.
     */
    public function past(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => $this->evening(-fake()->numberBetween(1, 30)),
        ]);
    }

    /**
     * Started half an hour ago and still on.
     */
    public function ongoing(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => CarbonImmutable::instance(now())->subMinutes(30)->startOfMinute(),
        ]);
    }

    public function kind(ClubNightKind $kind): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => $kind,
        ]);
    }

    /**
     * Sunday beginners' clinic.
     */
    public function clinic(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => ClubNightKind::Clinic,
            'level' => SkillLevel::Beginner,
            'capacity' => 16,
            'courts' => 3,
        ]);
    }

    public function capacity(int $capacity): static
    {
        return $this->state(fn (array $attributes) => [
            'capacity' => $capacity,
        ]);
    }

    /**
     * Add RSVPs from new members: $going going, then $waitlist on the waitlist.
     */
    public function withRsvps(int $going, int $waitlist = 0): static
    {
        return $this->afterCreating(function (ClubNight $night) use ($going, $waitlist): void {
            if ($going > 0) {
                ClubNightRsvp::factory()->count($going)->for($night)->create(['status' => RsvpStatus::Going]);
            }

            if ($waitlist > 0) {
                ClubNightRsvp::factory()->count($waitlist)->for($night)->create(['status' => RsvpStatus::Waitlist]);
            }
        });
    }

    /**
     * "Tuesday social", "Thursday training", "Beginners' clinic", "Ladder night".
     */
    private static function titleFor(mixed $kind, mixed $startsAt): string
    {
        $kind = $kind instanceof ClubNightKind ? $kind : ClubNightKind::tryFrom((string) $kind) ?? ClubNightKind::Social;
        $day = CarbonImmutable::parse($startsAt)->format('l');

        return match ($kind) {
            ClubNightKind::Social => $day.' social',
            ClubNightKind::Training => $day.' training',
            ClubNightKind::Clinic => "Beginners' clinic",
            ClubNightKind::Ladder => 'Ladder night',
        };
    }

    /**
     * 19:30 on the day $days from today (negative = in the past).
     */
    private function evening(int $days): CarbonImmutable
    {
        return CarbonImmutable::instance(now())->addDays($days)->setTime(19, 30);
    }
}
