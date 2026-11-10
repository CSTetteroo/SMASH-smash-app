<?php

namespace Database\Factories;

use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\Prediction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prediction>
 */
class PredictionFactory extends Factory
{
    /**
     * @var class-string<Prediction>
     */
    protected $model = Prediction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'match_id' => BadmintonMatch::factory(),
            'predicted_side' => fake()->randomElement(Side::cases()),
            'predicted_loser_games' => fake()->randomElement([null, 0, 1]),
        ];
    }

    /**
     * Pick $side to win; $loserGames = games the loser takes (best of 3: 0 = 2–0, 1 = 2–1; null = winner only).
     */
    public function picking(Side $side, ?int $loserGames = null): static
    {
        return $this->state(fn (array $attributes) => [
            'predicted_side' => $side,
            'predicted_loser_games' => $loserGames,
        ]);
    }
}
