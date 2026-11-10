<?php

namespace Database\Factories;

use App\Enums\Discipline;
use App\Enums\Handedness;
use App\Enums\Programme;
use App\Enums\SkillLevel;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state: an active, verified member with no matches yet
     * (ratings at the initial 1500).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->firstName().' '.fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'role' => UserRole::Member,
            'programme' => fake()->randomElement(Programme::cases()),
            'level' => fake()->randomElement(SkillLevel::cases()),
            'handedness' => fake()->boolean(85) ? Handedness::Right : Handedness::Left,
            'bio' => null,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * A committee member with access to every admin screen.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
        ]);
    }

    public function member(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Member,
        ]);
    }

    /**
     * A former / paused player, hidden from rankings and player pickers.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function level(SkillLevel $level): static
    {
        return $this->state(fn (array $attributes) => [
            'level' => $level,
        ]);
    }

    public function programme(Programme $programme): static
    {
        return $this->state(fn (array $attributes) => [
            'programme' => $programme,
        ]);
    }

    public function withBio(): static
    {
        return $this->state(fn (array $attributes) => [
            'bio' => fake()->randomElement([
                'Picked up a racket in first year and never looked back.',
                'Doubles specialist. Will chase every drop shot.',
                'Here for the rallies and the post-training drinks.',
                'Former club player back on court after a long break.',
                'Working on my backhand clear, one session at a time.',
            ]),
        ]);
    }

    /**
     * Pre-set rating stats for a discipline (for tests and demo data). Real ratings are
     * computed by the rating engine from match results, which overwrites these on recalculation.
     */
    public function withRating(Discipline $discipline, float $rating, int $played = 12, ?int $wins = null): static
    {
        return $this->state(fn (array $attributes) => [
            $discipline->ratingColumn() => $rating,
            $discipline->peakColumn() => max($rating, (float) config()->integer('smash.rating.initial', 1500)),
            $discipline->playedColumn() => $played,
            $discipline->winsColumn() => $wins ?? intdiv($played, 2),
        ]);
    }
}
