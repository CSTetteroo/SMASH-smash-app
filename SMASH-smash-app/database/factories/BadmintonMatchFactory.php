<?php

namespace Database\Factories;

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\MatchPlayer;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Matches without players by default (a scheduled singles friendly). Use withPlayers()
 * or withRandomPlayers() to add participants; completed() adds random players when none
 * were given. Factories never compute ratings: realistic rated results go through
 * App\Actions\Matches\SaveMatch.
 *
 * @extends Factory<BadmintonMatch>
 */
class BadmintonMatchFactory extends Factory
{
    /**
     * @var class-string<BadmintonMatch>
     */
    protected $model = BadmintonMatch::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'discipline' => Discipline::Singles,
            'status' => MatchStatus::Scheduled,
            'tournament_id' => null,
            'round' => null,
            'bracket_position' => null,
            'entry_a_id' => null,
            'entry_b_id' => null,
            'best_of' => 3,
            'points_per_game' => 21,
            'is_rated' => true,
            'court' => fake()->numberBetween(1, 4),
            'venue' => config('smash.club.venue'),
            'scheduled_at' => $this->evening(fake()->numberBetween(1, 14)),
            'played_at' => null,
            'winning_side' => null,
            'notes' => null,
            'created_by' => null,
        ];
    }

    public function singles(): static
    {
        return $this->state(fn (array $attributes) => [
            'discipline' => Discipline::Singles,
        ]);
    }

    public function doubles(): static
    {
        return $this->state(fn (array $attributes) => [
            'discipline' => Discipline::Doubles,
        ]);
    }

    /**
     * A fixture in the coming two weeks.
     */
    public function scheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MatchStatus::Scheduled,
            'scheduled_at' => $this->evening(fake()->numberBetween(1, 14)),
            'played_at' => null,
            'winning_side' => null,
        ]);
    }

    /**
     * Being played right now (no games recorded yet).
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MatchStatus::InProgress,
            'scheduled_at' => CarbonImmutable::instance(now())->subMinutes(20)->startOfMinute(),
            'played_at' => null,
            'winning_side' => null,
        ]);
    }

    /**
     * A finished match won in straight games (21–x) by $winner (side A by default).
     * Adds the games, and random players for friendlies without participants.
     * Ratings are not computed (rating_before/after stay null).
     */
    public function completed(Side $winner = Side::A): static
    {
        return $this->state(function (array $attributes) use ($winner) {
            $scheduledAt = $this->evening(-fake()->numberBetween(1, 45));

            return [
                'status' => MatchStatus::Completed,
                'scheduled_at' => $scheduledAt,
                'played_at' => $scheduledAt->addMinutes(fake()->numberBetween(5, 40)),
                'winning_side' => $winner,
            ];
        })->afterCreating(function (BadmintonMatch $match): void {
            if ($match->games()->doesntExist()) {
                $this->createStraightGames($match, $match->winning_side ?? Side::A);
            }

            if ($match->isFriendly()) {
                $this->ensurePlayers($match);
            }
        });
    }

    /**
     * Awarded without play: no games, never changes ratings.
     */
    public function walkover(Side $winner = Side::A): static
    {
        return $this->state(function (array $attributes) use ($winner) {
            $scheduledAt = $this->evening(-fake()->numberBetween(1, 45));

            return [
                'status' => MatchStatus::Walkover,
                'scheduled_at' => $scheduledAt,
                'played_at' => $scheduledAt,
                'winning_side' => $winner,
            ];
        });
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MatchStatus::Cancelled,
            'played_at' => null,
            'winning_side' => null,
        ]);
    }

    /**
     * A friendly that does not count towards ratings.
     */
    public function unrated(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_rated' => false,
        ]);
    }

    /**
     * Put the given players (models or ids) on side A and side B.
     *
     * @param  User|int|iterable<User|int>  $sideA
     * @param  User|int|iterable<User|int>  $sideB
     */
    public function withPlayers(User|int|iterable $sideA, User|int|iterable $sideB): static
    {
        $rows = [];

        foreach ([Side::A->value => $sideA, Side::B->value => $sideB] as $side => $players) {
            foreach (is_iterable($players) ? $players : [$players] as $player) {
                $rows[] = [
                    'user_id' => $player instanceof User ? $player->id : $player,
                    'side' => Side::from($side),
                ];
            }
        }

        return $this->has(
            MatchPlayer::factory()->count(count($rows))->sequence(...$rows),
            'participants',
        );
    }

    /**
     * Fill both sides with newly created players (1 or 2 per side, by discipline).
     */
    public function withRandomPlayers(): static
    {
        return $this->afterCreating(function (BadmintonMatch $match): void {
            $this->ensurePlayers($match);
        });
    }

    /**
     * Make it a match in a tournament draw (copies discipline and match format).
     */
    public function inTournament(Tournament $tournament, int $round = 1, ?int $bracketPosition = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'tournament_id' => $tournament->id,
            'discipline' => $tournament->discipline,
            'round' => $round,
            'bracket_position' => $bracketPosition,
            'best_of' => $tournament->best_of,
            'points_per_game' => $tournament->points_per_game,
            'is_rated' => $tournament->is_rated,
            'venue' => $tournament->venue ?? $attributes['venue'] ?? null,
        ]);
    }

    /**
     * Set the two tournament entries (null = open slot). When both are known, the
     * entries' players become the match participants, like the draw does.
     */
    public function betweenEntries(?TournamentEntry $entryA, ?TournamentEntry $entryB): static
    {
        return $this->state(fn (array $attributes) => [
            'entry_a_id' => $entryA?->id,
            'entry_b_id' => $entryB?->id,
        ])->afterCreating(function (BadmintonMatch $match) use ($entryA, $entryB): void {
            if ($entryA === null || $entryB === null || $match->participants()->exists()) {
                return;
            }

            foreach ([Side::A->value => $entryA, Side::B->value => $entryB] as $side => $entry) {
                foreach ($entry->members() as $member) {
                    $match->participants()->create(['user_id' => $member->id, 'side' => Side::from($side)]);
                }
            }
        });
    }

    /**
     * A first-round bye: the entry advances without playing (walkover, side B empty, not rated).
     */
    public function bye(TournamentEntry $entry): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MatchStatus::Walkover,
            'entry_a_id' => $entry->id,
            'entry_b_id' => null,
            'winning_side' => Side::A,
            'is_rated' => false,
            'played_at' => null,
        ]);
    }

    private function createStraightGames(BadmintonMatch $match, Side $winner): void
    {
        $target = $match->points_per_game;
        $gamesToWin = (int) ceil($match->best_of / 2);

        for ($number = 1; $number <= $gamesToWin; $number++) {
            $loserPoints = fake()->numberBetween(max(0, $target - 13), $target - 2);

            $match->games()->create([
                'number' => $number,
                'side_a_points' => $winner === Side::A ? $target : $loserPoints,
                'side_b_points' => $winner === Side::B ? $target : $loserPoints,
            ]);
        }
    }

    private function ensurePlayers(BadmintonMatch $match): void
    {
        if ($match->participants()->exists()) {
            return;
        }

        foreach (Side::cases() as $side) {
            foreach (User::factory()->count($match->discipline->playersPerSide())->create() as $user) {
                $match->participants()->create(['user_id' => $user->id, 'side' => $side]);
            }
        }
    }

    /**
     * A club-night time slot $days from today (negative = in the past).
     */
    private function evening(int $days): CarbonImmutable
    {
        return CarbonImmutable::instance(now())
            ->addDays($days)
            ->setTime(fake()->randomElement([19, 20, 21]), fake()->randomElement([0, 30]));
    }
}
