<?php

namespace App\Models;

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Enums\TournamentFormat;
use Carbon\CarbonImmutable;
use Database\Factories\BadmintonMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A singles or doubles match: a friendly (no tournament) or a tournament draw match.
 *
 * PHP reserves the word "match" for class names, hence "BadmintonMatch" on table "matches".
 * Results are written through App\Actions\Matches\SaveMatch, which also recalculates ratings.
 *
 * @property int $id
 * @property Discipline $discipline
 * @property MatchStatus $status
 * @property int|null $tournament_id
 * @property int|null $round
 * @property int|null $bracket_position
 * @property int|null $entry_a_id
 * @property int|null $entry_b_id
 * @property int $best_of
 * @property int $points_per_game
 * @property bool $is_rated
 * @property int|null $court
 * @property string|null $venue
 * @property CarbonImmutable|null $scheduled_at
 * @property CarbonImmutable|null $played_at
 * @property Side|null $winning_side
 * @property string|null $notes
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Tournament|null $tournament
 * @property-read TournamentEntry|null $entryA
 * @property-read TournamentEntry|null $entryB
 * @property-read Collection<int, MatchPlayer> $participants
 * @property-read Collection<int, User> $players
 * @property-read Collection<int, MatchGame> $games
 * @property-read Collection<int, Prediction> $predictions
 * @property-read User|null $creator
 * @property-read int|null $participants_count
 * @property-read int|null $games_count
 * @property-read int|null $predictions_count
 */
#[Fillable([
    'discipline',
    'status',
    'tournament_id',
    'round',
    'bracket_position',
    'entry_a_id',
    'entry_b_id',
    'best_of',
    'points_per_game',
    'is_rated',
    'court',
    'venue',
    'scheduled_at',
    'played_at',
    'winning_side',
    'notes',
    'created_by',
])]
class BadmintonMatch extends Model
{
    /** @use HasFactory<BadmintonMatchFactory> */
    use HasFactory;

    protected $table = 'matches';

    /**
     * Defaults mirroring the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'scheduled',
        'best_of' => 3,
        'points_per_game' => 21,
        'is_rated' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discipline' => Discipline::class,
            'status' => MatchStatus::class,
            'winning_side' => Side::class,
            'tournament_id' => 'integer',
            'round' => 'integer',
            'bracket_position' => 'integer',
            'entry_a_id' => 'integer',
            'entry_b_id' => 'integer',
            'best_of' => 'integer',
            'points_per_game' => 'integer',
            'is_rated' => 'boolean',
            'court' => 'integer',
            'scheduled_at' => 'datetime',
            'played_at' => 'datetime',
            'created_by' => 'integer',
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
     * @return BelongsTo<TournamentEntry, $this>
     */
    public function entryA(): BelongsTo
    {
        return $this->belongsTo(TournamentEntry::class, 'entry_a_id');
    }

    /**
     * @return BelongsTo<TournamentEntry, $this>
     */
    public function entryB(): BelongsTo
    {
        return $this->belongsTo(TournamentEntry::class, 'entry_b_id');
    }

    /**
     * One row per player per match (side + rating before/after).
     *
     * @return HasMany<MatchPlayer, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(MatchPlayer::class, 'match_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function players(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'match_players', 'match_id', 'user_id')
            ->withPivot('side', 'rating_before', 'rating_after')
            ->withTimestamps();
    }

    /**
     * Games in playing order.
     *
     * @return HasMany<MatchGame, $this>
     */
    public function games(): HasMany
    {
        return $this->hasMany(MatchGame::class, 'match_id')->orderBy('number');
    }

    /**
     * @return HasMany<Prediction, $this>
     */
    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class, 'match_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ------------------------------------------------------------------
    // Sides & participants
    // ------------------------------------------------------------------

    /**
     * Players on a side, in the order they were added (loads participants.user when missing).
     *
     * @return Collection<int, User>
     */
    public function playersOn(Side $side): Collection
    {
        $this->loadMissing('participants.user');

        return new Collection(
            $this->participants
                ->filter(fn (MatchPlayer $participant): bool => $participant->side === $side)
                ->sortBy('id')
                ->map(fn (MatchPlayer $participant): User => $participant->user)
                ->values()
                ->all()
        );
    }

    /**
     * The tournament entry on a side (null for friendlies and open draw slots).
     */
    public function entryOn(Side $side): ?TournamentEntry
    {
        return $side === Side::A ? $this->entryA : $this->entryB;
    }

    /**
     * "Sam de Vries", "Sam de Vries & Lotte Jansen", the tournament entry name,
     * "Bye" for the empty side of a bye, or "TBD" for a slot that is not known yet.
     */
    public function sideLabel(Side $side): string
    {
        if ($this->tournament_id !== null) {
            $entry = $this->entryOn($side);

            if ($entry !== null) {
                return $entry->name();
            }

            if ($this->isBye()) {
                return 'Bye';
            }
        }

        $players = $this->playersOn($side);

        return $players->isEmpty() ? 'TBD' : $players->pluck('name')->join(' & ');
    }

    /**
     * "Sam de Vries vs Lotte Jansen" (for titles, search results and lists).
     */
    public function matchup(): string
    {
        return $this->sideLabel(Side::A).' vs '.$this->sideLabel(Side::B);
    }

    /**
     * The players on the other side of the net from $user (empty when they do not play).
     *
     * @return Collection<int, User>
     */
    public function opponentsOf(User $user): Collection
    {
        $side = $this->sideOf($user);

        return $side === null ? new Collection : $this->playersOn($side->opposite());
    }

    /**
     * The doubles partner of $user in this match, if any.
     */
    public function partnerOf(User $user): ?User
    {
        $side = $this->sideOf($user);

        return $side === null
            ? null
            : $this->playersOn($side)->first(fn (User $player): bool => $player->id !== $user->id);
    }

    /**
     * Whether $user's side won: null while unfinished or when they did not play.
     */
    public function isWonBy(User $user): ?bool
    {
        $side = $this->sideOf($user);

        if ($side === null || ! $this->isFinished() || $this->winning_side === null) {
            return null;
        }

        return $this->winning_side === $side;
    }

    /**
     * Games won from $user's point of view ("1–2" when they lost 1–2), "W/O" for walkovers.
     * Falls back to scoreline() (side A first) when they did not play.
     */
    public function scorelineFor(User $user): string
    {
        $side = $this->sideOf($user);

        if ($side === null || $this->status === MatchStatus::Walkover) {
            return $this->scoreline();
        }

        return $this->gamesWon($side).'–'.$this->gamesWon($side->opposite());
    }

    /**
     * Whether the user plays in this match (on either side).
     */
    public function hasParticipant(User $user): bool
    {
        return $this->participantFor($user) !== null;
    }

    /**
     * The participant row (side, ratings) for a user, if they play in this match.
     */
    public function participantFor(User $user): ?MatchPlayer
    {
        return $this->participants->first(
            fn (MatchPlayer $participant): bool => $participant->user_id === $user->id
        );
    }

    public function sideOf(User $user): ?Side
    {
        return $this->participantFor($user)?->side;
    }

    /**
     * Rating change for a user in this match (null when unrated or not yet rated).
     */
    public function ratingChangeFor(User $user): ?float
    {
        return $this->participantFor($user)?->ratingChange();
    }

    /**
     * Players on the winning side (empty while there is no winner).
     *
     * @return Collection<int, User>
     */
    public function winners(): Collection
    {
        return $this->winning_side === null ? new Collection : $this->playersOn($this->winning_side);
    }

    /**
     * The tournament entry that won this match, if any.
     */
    public function winningEntry(): ?TournamentEntry
    {
        return $this->winning_side === null ? null : $this->entryOn($this->winning_side);
    }

    /**
     * The tournament entry that lost this match, if any.
     */
    public function losingEntry(): ?TournamentEntry
    {
        return $this->winning_side === null ? null : $this->entryOn($this->winning_side->opposite());
    }

    // ------------------------------------------------------------------
    // Score
    // ------------------------------------------------------------------

    /**
     * Games won by a side. While a match is live, the game in progress does not count.
     */
    public function gamesWon(Side $side): int
    {
        $finished = $this->isFinished();

        return $this->games
            ->filter(fn (MatchGame $game): bool => ($finished || $game->isComplete($this->points_per_game))
                && $game->winner() === $side)
            ->count();
    }

    /**
     * Total rally points won by a side over all games.
     */
    public function pointsWon(Side $side): int
    {
        return (int) $this->games->sum(fn (MatchGame $game): int => $game->pointsFor($side));
    }

    /**
     * Games won by the losing side of a completed match (0 = straight games), else null.
     */
    public function loserGames(): ?int
    {
        if ($this->status !== MatchStatus::Completed || $this->winning_side === null) {
            return null;
        }

        return $this->gamesWon($this->winning_side->opposite());
    }

    /**
     * Games won, side A first: "2–1". Walkovers read "W/O".
     */
    public function scoreline(): string
    {
        if ($this->status === MatchStatus::Walkover) {
            return 'W/O';
        }

        return $this->gamesWon(Side::A).'–'.$this->gamesWon(Side::B);
    }

    // ------------------------------------------------------------------
    // State
    // ------------------------------------------------------------------

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    public function isLive(): bool
    {
        return $this->status === MatchStatus::InProgress;
    }

    public function isFriendly(): bool
    {
        return $this->tournament_id === null;
    }

    public function isKnockout(): bool
    {
        return $this->tournament_id !== null
            && $this->tournament?->format === TournamentFormat::SingleElimination;
    }

    public function isRoundRobin(): bool
    {
        return $this->tournament_id !== null
            && $this->tournament?->format === TournamentFormat::RoundRobin;
    }

    /**
     * A bye: a tournament walkover where one of the two entries is missing.
     */
    public function isBye(): bool
    {
        return $this->tournament_id !== null
            && $this->status === MatchStatus::Walkover
            && (($this->entry_a_id === null) !== ($this->entry_b_id === null));
    }

    /**
     * "Final", "Semi-final", "Quarter-final", "Round of 16"… for knockouts,
     * "Matchday N" for round robins, null for friendlies.
     */
    public function roundLabel(): ?string
    {
        if ($this->tournament_id === null || $this->round === null) {
            return null;
        }

        return $this->tournament?->roundLabel($this->round);
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    /**
     * Completed or walkover.
     *
     * @param  Builder<BadmintonMatch>  $query
     */
    #[Scope]
    protected function finished(Builder $query): void
    {
        $query->whereIn($query->qualifyColumn('status'), [
            MatchStatus::Completed->value,
            MatchStatus::Walkover->value,
        ]);
    }

    /**
     * Scheduled matches, soonest first (unscheduled ones last).
     *
     * @param  Builder<BadmintonMatch>  $query
     */
    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), MatchStatus::Scheduled->value)
            ->orderByRaw('matches.scheduled_at is null')
            ->orderBy($query->qualifyColumn('scheduled_at'))
            ->orderBy($query->qualifyColumn('id'));
    }

    /**
     * Matches being played right now.
     *
     * @param  Builder<BadmintonMatch>  $query
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), MatchStatus::InProgress->value);
    }

    /**
     * @param  Builder<BadmintonMatch>  $query
     */
    #[Scope]
    protected function forPlayer(Builder $query, User|int $user): void
    {
        $userId = $user instanceof User ? $user->id : $user;

        $query->whereHas('participants', fn (Builder $participants) => $participants->where('user_id', $userId));
    }

    /**
     * @param  Builder<BadmintonMatch>  $query
     */
    #[Scope]
    protected function forDiscipline(Builder $query, Discipline $discipline): void
    {
        $query->where($query->qualifyColumn('discipline'), $discipline->value);
    }

    /**
     * Matches outside tournaments.
     *
     * @param  Builder<BadmintonMatch>  $query
     */
    #[Scope]
    protected function friendly(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('tournament_id'));
    }

    /**
     * Matches that count towards ratings (when completed).
     *
     * @param  Builder<BadmintonMatch>  $query
     */
    #[Scope]
    protected function rated(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_rated'), true);
    }

    /**
     * Most recently played first, then most recently scheduled, then newest.
     *
     * @param  Builder<BadmintonMatch>  $query
     */
    #[Scope]
    protected function latestFirst(Builder $query): void
    {
        $query->orderByDesc($query->qualifyColumn('played_at'))
            ->orderByDesc($query->qualifyColumn('scheduled_at'))
            ->orderByDesc($query->qualifyColumn('id'));
    }

    /**
     * Eager-load everything the match cards/rows need (names, games, round labels).
     *
     * @param  Builder<BadmintonMatch>  $query
     */
    #[Scope]
    protected function withDetails(Builder $query): void
    {
        $query->with([
            'participants.user',
            'games',
            'tournament',
            'entryA.player',
            'entryA.partner',
            'entryB.player',
            'entryB.partner',
        ]);
    }
}
