<?php

namespace Database\Factories;

use App\Enums\Discipline;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Entries get a partner automatically when the tournament is doubles.
 *
 * @extends Factory<TournamentEntry>
 */
class TournamentEntryFactory extends Factory
{
    /**
     * @var class-string<TournamentEntry>
     */
    protected $model = TournamentEntry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tournament_id' => Tournament::factory(),
            'player_id' => User::factory(),
            'partner_id' => fn (array $attributes) => $this->isDoubles($attributes['tournament_id'] ?? null)
                ? User::factory()
                : null,
            'seed' => null,
        ];
    }

    /**
     * A doubles pair with the given (or a new) partner.
     */
    public function withPartner(?User $partner = null): static
    {
        return $this->state(fn (array $attributes) => [
            'partner_id' => $partner->id ?? User::factory(),
        ]);
    }

    public function withoutPartner(): static
    {
        return $this->state(fn (array $attributes) => [
            'partner_id' => null,
        ]);
    }

    public function seed(?int $seed): static
    {
        return $this->state(fn (array $attributes) => [
            'seed' => $seed,
        ]);
    }

    private function isDoubles(mixed $tournamentId): bool
    {
        if (! is_int($tournamentId) && ! (is_string($tournamentId) && ctype_digit($tournamentId))) {
            return false;
        }

        return Tournament::query()->whereKey($tournamentId)->value('discipline') === Discipline::Doubles;
    }
}
