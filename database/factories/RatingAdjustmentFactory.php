<?php

namespace Database\Factories;

use App\Enums\Discipline;
use App\Models\RatingAdjustment;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Manual rating adjustments. Like match factories, this never recalculates ratings:
 * call RatingEngine::recalculate() after creating adjustments in a test.
 *
 * @extends Factory<RatingAdjustment>
 */
class RatingAdjustmentFactory extends Factory
{
    /**
     * @var class-string<RatingAdjustment>
     */
    protected $model = RatingAdjustment::class;

    /**
     * Define the model's default state: a small correction for a new player, made by nobody in
     * particular (created_by null) at some point in the last two months.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->randomElement([-40, -25, -15, 15, 25, 40]);

        return [
            'user_id' => User::factory(),
            'discipline' => fake()->randomElement(Discipline::cases()),
            'amount' => (float) $amount,
            'reason' => $amount < 0
                ? 'Correction: points from a friendly that was logged twice.'
                : 'Correction: a club-night result was entered the wrong way round.',
            'effective_at' => CarbonImmutable::instance(fake()->dateTimeBetween('-8 weeks', '-1 day'))->startOfMinute(),
            'created_by' => null,
        ];
    }

    public function singles(): static
    {
        return $this->state(fn (array $attributes) => ['discipline' => Discipline::Singles]);
    }

    public function doubles(): static
    {
        return $this->state(fn (array $attributes) => ['discipline' => Discipline::Doubles]);
    }

    public function amount(float $amount): static
    {
        return $this->state(fn (array $attributes) => ['amount' => $amount]);
    }

    public function effectiveAt(DateTimeInterface|string $moment): static
    {
        return $this->state(fn (array $attributes) => [
            'effective_at' => is_string($moment) ? CarbonImmutable::parse($moment) : CarbonImmutable::instance($moment),
        ]);
    }

    /**
     * Made by this admin.
     */
    public function by(User $admin): static
    {
        return $this->state(fn (array $attributes) => ['created_by' => $admin->id]);
    }

    /**
     * A head start for an experienced newcomer.
     */
    public function placement(float $amount = 150.0): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $amount,
            'reason' => 'Placement: played league badminton for years before joining SMASH.',
        ]);
    }

    /**
     * A committee penalty (points taken off).
     */
    public function penalty(float $amount = -25.0): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => -abs($amount),
            'reason' => 'Penalty: no-show at a tournament match without letting the committee know.',
        ]);
    }
}
