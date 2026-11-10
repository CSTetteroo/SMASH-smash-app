<?php

namespace App\Models;

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TournamentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A club tournament: singles or doubles, knockout (single elimination) or round robin.
 * Routes bind it by slug: /tournaments/{tournament:slug}.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property Discipline $discipline
 * @property TournamentFormat $format
 * @property TournamentStatus $status
 * @property string|null $venue
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property CarbonImmutable|null $registration_closes_at
 * @property int|null $max_entries
 * @property int $best_of
 * @property int $points_per_game
 * @property bool $is_rated
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, TournamentEntry> $entries
 * @property-read Collection<int, BadmintonMatch> $matches
 * @property-read User|null $creator
 * @property-read int|null $entries_count
 * @property-read int|null $matches_count
 */
#[Fillable([
    'name',
    'slug',
    'description',
    'discipline',
    'format',
    'status',
    'venue',
    'starts_at',
    'ends_at',
    'registration_closes_at',
    'max_entries',
    'best_of',
    'points_per_game',
    'is_rated',
    'created_by',
])]
class Tournament extends Model
{
    /** @use HasFactory<TournamentFactory> */
    use HasFactory;

    /**
     * Slugs that would shadow static routes (/tournaments/create).
     */
    public const RESERVED_SLUGS = ['create'];

    /**
     * Defaults mirroring the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'best_of' => 3,
        'points_per_game' => 21,
        'is_rated' => true,
    ];

    /**
     * Total rounds of an existing draw (memoised per instance; a draw's size never changes).
     */
    private ?int $drawRounds = null;

    protected static function booted(): void
    {
        static::creating(function (Tournament $tournament): void {
            $source = filled($tournament->getAttribute('slug'))
                ? (string) $tournament->getAttribute('slug')
                : (string) $tournament->getAttribute('name');

            $tournament->slug = static::uniqueSlug($source);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discipline' => Discipline::class,
            'format' => TournamentFormat::class,
            'status' => TournamentStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'registration_closes_at' => 'datetime',
            'max_entries' => 'integer',
            'best_of' => 'integer',
            'points_per_game' => 'integer',
            'is_rated' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * A URL slug for $value that is not reserved and not used by another tournament:
     * "Halloween Smash" => "halloween-smash", then "halloween-smash-2", …
     */
    public static function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = rtrim(Str::limit(Str::slug($value), 80, ''), '-');

        if ($base === '') {
            $base = 'tournament';
        }

        $slug = $base;
        $suffix = 2;

        while (in_array($slug, self::RESERVED_SLUGS, true) || self::slugTaken($slug, $ignoreId)) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private static function slugTaken(string $slug, ?int $ignoreId): bool
    {
        return static::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    /**
     * @return HasMany<TournamentEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(TournamentEntry::class);
    }

    /**
     * @return HasMany<BadmintonMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(BadmintonMatch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ------------------------------------------------------------------
    // Format
    // ------------------------------------------------------------------

    public function isKnockout(): bool
    {
        return $this->format === TournamentFormat::SingleElimination;
    }

    public function isRoundRobin(): bool
    {
        return $this->format === TournamentFormat::RoundRobin;
    }

    public function isDoubles(): bool
    {
        return $this->discipline === Discipline::Doubles;
    }

    // ------------------------------------------------------------------
    // Registration
    // ------------------------------------------------------------------

    /**
     * When registration closes: registration_closes_at, or the start when no deadline is set.
     */
    public function registrationDeadline(): ?CarbonImmutable
    {
        return $this->registration_closes_at ?? $this->starts_at;
    }

    /**
     * Open status, deadline not passed and spots left.
     */
    public function isOpenForRegistration(): bool
    {
        $deadline = $this->registrationDeadline();

        return $this->status === TournamentStatus::Open
            && ($deadline === null || $deadline->isFuture())
            && ! $this->isFull();
    }

    /**
     * Number of entries (uses withCount('entries') or loaded entries when available).
     */
    public function entryCount(): int
    {
        if (array_key_exists('entries_count', $this->attributes)) {
            return (int) $this->attributes['entries_count'];
        }

        if ($this->relationLoaded('entries')) {
            return $this->entries->count();
        }

        return $this->entries()->count();
    }

    /**
     * Places left, or null when the tournament has no maximum.
     */
    public function spotsLeft(): ?int
    {
        if ($this->max_entries === null) {
            return null;
        }

        return max(0, $this->max_entries - $this->entryCount());
    }

    public function isFull(): bool
    {
        return $this->spotsLeft() === 0;
    }

    /**
     * The entry this user plays in, as player or doubles partner.
     */
    public function entryFor(User $user): ?TournamentEntry
    {
        if ($this->relationLoaded('entries')) {
            return $this->entries->first(fn (TournamentEntry $entry): bool => $entry->hasPlayer($user));
        }

        return $this->entries()->forPlayer($user)->first();
    }

    // ------------------------------------------------------------------
    // Draw & rounds
    // ------------------------------------------------------------------

    /**
     * Whether a draw (any match) has been generated.
     */
    public function hasDraw(): bool
    {
        if (array_key_exists('matches_count', $this->attributes)) {
            return (int) $this->attributes['matches_count'] > 0;
        }

        if ($this->relationLoaded('matches')) {
            return $this->matches->isNotEmpty();
        }

        return $this->matches()->exists();
    }

    /**
     * Knockout: rounds needed for the draw size (16 entries => 4). Round robin: matchdays.
     * Taken from the draw when one exists, otherwise from the current number of entries.
     */
    public function totalRounds(): int
    {
        if ($this->relationLoaded('matches')) {
            return $this->matches->isNotEmpty()
                ? (int) $this->matches->max('round')
                : self::roundsFor($this->format, $this->entryCount());
        }

        if ($this->drawRounds !== null) {
            return $this->drawRounds;
        }

        if ($this->exists) {
            $maxRound = $this->matches()->max('round');

            if ($maxRound !== null) {
                return $this->drawRounds = (int) $maxRound;
            }
        }

        return self::roundsFor($this->format, $this->entryCount());
    }

    /**
     * Rounds needed for $entries entries: knockout => log2 of the bracket size, round robin => matchdays.
     */
    public static function roundsFor(TournamentFormat $format, int $entries): int
    {
        if ($entries < 2) {
            return 0;
        }

        if ($format === TournamentFormat::RoundRobin) {
            return $entries % 2 === 0 ? $entries - 1 : $entries;
        }

        $rounds = 0;

        for ($size = 1; $size < $entries; $size *= 2) {
            $rounds++;
        }

        return $rounds;
    }

    /**
     * "Final", "Semi-final", "Quarter-final", "Round of 16", "Round of 32"… (knockout)
     * or "Matchday 3" (round robin). $plural gives section headings: "Semi-finals".
     */
    public function roundLabel(int $round, bool $plural = false): string
    {
        if ($this->format === TournamentFormat::RoundRobin) {
            return 'Matchday '.$round;
        }

        $remaining = $this->totalRounds() - $round + 1;

        return match (true) {
            $remaining <= 1 => 'Final',
            $remaining === 2 => $plural ? 'Semi-finals' : 'Semi-final',
            $remaining === 3 => $plural ? 'Quarter-finals' : 'Quarter-final',
            default => 'Round of '.(2 ** $remaining),
        };
    }

    // ------------------------------------------------------------------
    // Results
    // ------------------------------------------------------------------

    /**
     * Share of draw matches finished (0–1). Byes and cancelled matches are not counted.
     */
    public function progress(): float
    {
        $matches = $this->relationLoaded('matches')
            ? $this->matches
            : $this->matches()->get(['id', 'tournament_id', 'status', 'entry_a_id', 'entry_b_id']);

        $playable = $matches->reject(
            fn (BadmintonMatch $match): bool => $match->isBye() || $match->status === MatchStatus::Cancelled
        );

        if ($playable->isEmpty()) {
            return 0.0;
        }

        return $playable->filter(fn (BadmintonMatch $match): bool => $match->isFinished())->count() / $playable->count();
    }

    /**
     * Winner of the final (knockout) or top of the table once every match is finished (round robin).
     */
    public function champion(): ?TournamentEntry
    {
        return $this->podium()[0] ?? null;
    }

    /**
     * Loser of the final (knockout) or second in the table once every match is finished (round robin).
     */
    public function runnerUp(): ?TournamentEntry
    {
        return $this->podium()[1] ?? null;
    }

    /**
     * @return array<int, TournamentEntry> [0 => champion, 1 => runner-up] once decided, else []
     */
    private function podium(): array
    {
        return $this->isKnockout() ? $this->knockoutPodium() : $this->roundRobinPodium();
    }

    /**
     * @return array<int, TournamentEntry>
     */
    private function knockoutPodium(): array
    {
        $final = $this->finalMatch();

        if ($final === null || ! $final->isFinished() || $final->winning_side === null) {
            return [];
        }

        return array_filter([0 => $final->winningEntry(), 1 => $final->losingEntry()]);
    }

    /**
     * The knockout final (last round), if the draw exists.
     */
    public function finalMatch(): ?BadmintonMatch
    {
        if (! $this->isKnockout()) {
            return null;
        }

        if ($this->relationLoaded('matches')) {
            $lastRound = $this->matches->max('round');

            return $this->matches
                ->filter(fn (BadmintonMatch $match): bool => $match->round === $lastRound)
                ->sortBy('bracket_position')
                ->first();
        }

        $lastRound = $this->matches()->max('round');

        if ($lastRound === null) {
            return null;
        }

        return $this->matches()
            ->where('round', $lastRound)
            ->orderBy('bracket_position')
            ->first();
    }

    /**
     * Round robin table order: wins, game difference, point difference, then name.
     *
     * @return array<int, TournamentEntry>
     */
    private function roundRobinPodium(): array
    {
        $matches = $this->relationLoaded('matches') ? $this->matches : $this->matches()->get();
        $matches->loadMissing('games');

        $played = $matches->reject(fn (BadmintonMatch $match): bool => $match->status === MatchStatus::Cancelled);

        if ($played->isEmpty() || $played->contains(fn (BadmintonMatch $match): bool => ! $match->isFinished())) {
            return [];
        }

        $entries = $this->relationLoaded('entries') ? $this->entries : $this->entries()->get();
        $entries->loadMissing(['player', 'partner']);

        /** @var array<int, array{won: int, games: int, points: int}> $table */
        $table = [];

        foreach ($entries as $entry) {
            $table[$entry->id] = ['won' => 0, 'games' => 0, 'points' => 0];
        }

        foreach ($played as $match) {
            foreach ([Side::A, Side::B] as $side) {
                $entryId = $side === Side::A ? $match->entry_a_id : $match->entry_b_id;

                if ($entryId === null || ! isset($table[$entryId])) {
                    continue;
                }

                $opponent = $side->opposite();
                $table[$entryId]['won'] += $match->winning_side === $side ? 1 : 0;
                $table[$entryId]['games'] += $match->gamesWon($side) - $match->gamesWon($opponent);
                $table[$entryId]['points'] += $match->pointsWon($side) - $match->pointsWon($opponent);
            }
        }

        return $entries
            ->sort(function (TournamentEntry $a, TournamentEntry $b) use ($table): int {
                return [$table[$b->id]['won'], $table[$b->id]['games'], $table[$b->id]['points'], $a->name()]
                    <=> [$table[$a->id]['won'], $table[$a->id]['games'], $table[$a->id]['points'], $b->name()];
            })
            ->take(2)
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    /**
     * Hide drafts from everybody but admins.
     *
     * @param  Builder<Tournament>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, ?User $user): void
    {
        if ($user?->isAdmin() === true) {
            return;
        }

        $query->where($query->qualifyColumn('status'), '!=', TournamentStatus::Draft->value);
    }

    /**
     * Not started yet (draft or open for registration), soonest first.
     *
     * @param  Builder<Tournament>  $query
     */
    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->whereIn($query->qualifyColumn('status'), [TournamentStatus::Draft->value, TournamentStatus::Open->value])
            ->orderBy($query->qualifyColumn('starts_at'))
            ->orderBy($query->qualifyColumn('id'));
    }

    /**
     * Draw generated and being played.
     *
     * @param  Builder<Tournament>  $query
     */
    #[Scope]
    protected function ongoing(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), TournamentStatus::InProgress->value)
            ->orderBy($query->qualifyColumn('starts_at'))
            ->orderBy($query->qualifyColumn('id'));
    }

    /**
     * Completed tournaments, most recent first.
     *
     * @param  Builder<Tournament>  $query
     */
    #[Scope]
    protected function past(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), TournamentStatus::Completed->value)
            ->orderByDesc($query->qualifyColumn('starts_at'))
            ->orderByDesc($query->qualifyColumn('id'));
    }
}
