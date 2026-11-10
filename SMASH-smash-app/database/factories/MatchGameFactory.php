<?php

namespace Database\Factories;

use App\Models\BadmintonMatch;
use App\Models\MatchGame;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchGame>
 */
class MatchGameFactory extends Factory
{
    /**
     * @var class-string<MatchGame>
     */
    protected $model = MatchGame::class;

    /**
     * Define the model's default state: a game 1 won 21–x by side A.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'match_id' => BadmintonMatch::factory(),
            'number' => 1,
            'side_a_points' => 21,
            'side_b_points' => fake()->numberBetween(5, 19),
        ];
    }

    public function number(int $number): static
    {
        return $this->state(fn (array $attributes) => [
            'number' => $number,
        ]);
    }

    public function score(int $sideA, int $sideB): static
    {
        return $this->state(fn (array $attributes) => [
            'side_a_points' => $sideA,
            'side_b_points' => $sideB,
        ]);
    }
}
