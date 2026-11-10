<?php

namespace Database\Factories;

use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\MatchPlayer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchPlayer>
 */
class MatchPlayerFactory extends Factory
{
    /**
     * @var class-string<MatchPlayer>
     */
    protected $model = MatchPlayer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'match_id' => BadmintonMatch::factory(),
            'user_id' => User::factory(),
            'side' => Side::A,
            'rating_before' => null,
            'rating_after' => null,
        ];
    }

    public function side(Side $side): static
    {
        return $this->state(fn (array $attributes) => [
            'side' => $side,
        ]);
    }

    /**
     * Ratings as the rating engine would have written them.
     */
    public function rated(float $before, float $after): static
    {
        return $this->state(fn (array $attributes) => [
            'rating_before' => $before,
            'rating_after' => $after,
        ]);
    }
}
