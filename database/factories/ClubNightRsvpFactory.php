<?php

namespace Database\Factories;

use App\Enums\RsvpStatus;
use App\Models\ClubNight;
use App\Models\ClubNightRsvp;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClubNightRsvp>
 */
class ClubNightRsvpFactory extends Factory
{
    /**
     * @var class-string<ClubNightRsvp>
     */
    protected $model = ClubNightRsvp::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'club_night_id' => ClubNight::factory(),
            'user_id' => User::factory(),
            'status' => RsvpStatus::Going,
            'checked_in_at' => null,
        ];
    }

    public function going(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RsvpStatus::Going,
        ]);
    }

    public function waitlist(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RsvpStatus::Waitlist,
        ]);
    }

    /**
     * Present at the door (going, checked in a few minutes ago).
     */
    public function checkedIn(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RsvpStatus::Going,
            'checked_in_at' => CarbonImmutable::instance(now())->subMinutes(5)->startOfMinute(),
        ]);
    }
}
