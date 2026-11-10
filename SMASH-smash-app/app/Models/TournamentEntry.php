<?php

namespace App\Models;

use App\Enums\Discipline;
use Carbon\CarbonImmutable;
use Database\Factories\TournamentEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A registration in a tournament: one player (singles) or a pair (doubles).
 *
 * @property int $id
 * @property int $tournament_id
 * @property int $player_id
 * @property int|null $partner_id
 * @property int|null $seed
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Tournament $tournament
 * @property-read User $player
 * @property-read User|null $partner
 */
#[Fillable(['tournament_id', 'player_id', 'partner_id', 'seed'])]
class TournamentEntry extends Model
{
    /** @use HasFactory<TournamentEntryFactory> */
    use HasFactory;

    protected $table = 'tournament_entries';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tournament_id' => 'integer',
            'player_id' => 'integer',
            'partner_id' => 'integer',
            'seed' => 'integer',
        ];
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(User::class, 'player_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_id');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * The player, plus the partner for doubles.
     *
     * @return Collection<int, User>
     */
    public function members(): Collection
    {
        $members = [$this->player];

        if ($this->partner !== null) {
            $members[] = $this->partner;
        }

        return new Collection($members);
    }

    /**
     * "Sam de Vries" or "Sam de Vries & Lotte Jansen".
     */
    public function name(): string
    {
        return $this->members()->pluck('name')->join(' & ');
    }

    /**
     * Family names for tight spaces (brackets): "De Vries" or "Jansen / De Vries".
     */
    public function shortName(): string
    {
        return $this->members()
            ->map(fn (User $member): string => Str::ucfirst($member->lastName()))
            ->join(' / ');
    }

    /**
     * Discipline of the tournament (inferred from the partner when the tournament is not loaded).
     */
    public function discipline(): Discipline
    {
        if ($this->relationLoaded('tournament')) {
            return $this->tournament->discipline;
        }

        return $this->partner_id === null ? Discipline::Singles : Discipline::Doubles;
    }

    /**
     * Entry strength: the player's singles rating, or the mean doubles rating of the pair.
     */
    public function rating(): float
    {
        $discipline = $this->discipline();
        $members = $this->members();

        if ($members->isEmpty()) {
            return (float) config()->integer('smash.rating.initial', 1500);
        }

        return (float) $members->avg(fn (User $member): float => $member->ratingExact($discipline));
    }

    public function hasPlayer(User $user): bool
    {
        return $this->player_id === $user->id || $this->partner_id === $user->id;
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    /**
     * Entries where the user is the player or the partner.
     *
     * @param  Builder<TournamentEntry>  $query
     */
    #[Scope]
    protected function forPlayer(Builder $query, User|int $user): void
    {
        $userId = $user instanceof User ? $user->id : $user;

        $query->where(function (Builder $query) use ($userId): void {
            $query->where($query->qualifyColumn('player_id'), $userId)
                ->orWhere($query->qualifyColumn('partner_id'), $userId);
        });
    }

    /**
     * Seeded entries first (1, 2, 3…), then unseeded in registration order.
     *
     * @param  Builder<TournamentEntry>  $query
     */
    #[Scope]
    protected function seeded(Builder $query): void
    {
        $query->orderByRaw('tournament_entries.seed is null')
            ->orderBy($query->qualifyColumn('seed'))
            ->orderBy($query->qualifyColumn('id'));
    }
}
