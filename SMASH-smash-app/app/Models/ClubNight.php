<?php

namespace App\Models;

use App\Enums\ClubNightKind;
use App\Enums\RsvpStatus;
use App\Enums\SkillLevel;
use Carbon\CarbonImmutable;
use Database\Factories\ClubNightFactory;
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
 * A weekly club session (social play, training, clinic or ladder night) members RSVP to.
 * Going RSVPs are capped by capacity; everybody else joins the waitlist.
 *
 * @property int $id
 * @property string $title
 * @property ClubNightKind $kind
 * @property string|null $description
 * @property string $venue
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property int $courts
 * @property int $capacity
 * @property SkillLevel|null $level
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, ClubNightRsvp> $rsvps
 * @property-read Collection<int, User> $attendees
 * @property-read User|null $creator
 * @property-read int|null $rsvps_count
 * @property-read int|null $going_count
 * @property-read int|null $waitlist_count
 */
#[Fillable([
    'title',
    'kind',
    'description',
    'venue',
    'starts_at',
    'ends_at',
    'courts',
    'capacity',
    'level',
    'created_by',
])]
class ClubNight extends Model
{
    /** @use HasFactory<ClubNightFactory> */
    use HasFactory;

    protected $table = 'club_nights';

    /**
     * Defaults mirroring the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'social',
        'courts' => 4,
        'capacity' => 24,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ClubNightKind::class,
            'level' => SkillLevel::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'courts' => 'integer',
            'capacity' => 'integer',
            'created_by' => 'integer',
        ];
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    /**
     * @return HasMany<ClubNightRsvp, $this>
     */
    public function rsvps(): HasMany
    {
        return $this->hasMany(ClubNightRsvp::class);
    }

    /**
     * Everybody who RSVP'd (going or waitlist); pivot has status and checked_in_at.
     *
     * @return BelongsToMany<User, $this>
     */
    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'club_night_rsvps', 'club_night_id', 'user_id')
            ->withPivot('status', 'checked_in_at')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * RSVPs with status going (uses withRsvpCounts() or loaded rsvps when available).
     */
    public function goingCount(): int
    {
        return $this->countRsvps(RsvpStatus::Going, 'going_count');
    }

    /**
     * RSVPs on the waitlist (uses withRsvpCounts() or loaded rsvps when available).
     */
    public function waitlistCount(): int
    {
        return $this->countRsvps(RsvpStatus::Waitlist, 'waitlist_count');
    }

    /**
     * Attendees checked in at the door.
     */
    public function checkedInCount(): int
    {
        if ($this->relationLoaded('rsvps')) {
            return $this->rsvps->filter(fn (ClubNightRsvp $rsvp): bool => $rsvp->checked_in_at !== null)->count();
        }

        return $this->rsvps()->whereNotNull('checked_in_at')->count();
    }

    public function spotsLeft(): int
    {
        return max(0, $this->capacity - $this->goingCount());
    }

    public function isFull(): bool
    {
        return $this->goingCount() >= $this->capacity;
    }

    /**
     * The session has ended.
     */
    public function isPast(): bool
    {
        return $this->ends_at->isPast();
    }

    /**
     * The session is on right now.
     */
    public function isOngoing(): bool
    {
        return $this->starts_at->isPast() && ! $this->isPast();
    }

    /**
     * The user's RSVP for this night (null for guests or when they have not responded).
     */
    public function rsvpFor(?User $user): ?ClubNightRsvp
    {
        if ($user === null) {
            return null;
        }

        if ($this->relationLoaded('rsvps')) {
            return $this->rsvps->first(fn (ClubNightRsvp $rsvp): bool => $rsvp->user_id === $user->id);
        }

        return $this->rsvps()->where('user_id', $user->id)->first();
    }

    /**
     * 1-based place on the waitlist (first come, first served), or null when not waitlisted.
     */
    public function waitlistPosition(User $user): ?int
    {
        $waitlist = ($this->relationLoaded('rsvps') ? $this->rsvps : $this->rsvps()->get())
            ->filter(fn (ClubNightRsvp $rsvp): bool => $rsvp->status === RsvpStatus::Waitlist)
            ->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->values();

        $index = $waitlist->search(fn (ClubNightRsvp $rsvp): bool => $rsvp->user_id === $user->id);

        return $index === false ? null : $index + 1;
    }

    private function countRsvps(RsvpStatus $status, string $aggregate): int
    {
        if (array_key_exists($aggregate, $this->attributes)) {
            return (int) $this->attributes[$aggregate];
        }

        if ($this->relationLoaded('rsvps')) {
            return $this->rsvps->filter(fn (ClubNightRsvp $rsvp): bool => $rsvp->status === $status)->count();
        }

        return $this->rsvps()->where('status', $status->value)->count();
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    /**
     * Nights that have not ended yet, soonest first.
     *
     * @param  Builder<ClubNight>  $query
     */
    #[Scope]
    protected function upcoming(Builder $query): void
    {
        $query->where($query->qualifyColumn('ends_at'), '>=', now())
            ->orderBy($query->qualifyColumn('starts_at'))
            ->orderBy($query->qualifyColumn('id'));
    }

    /**
     * Nights that have ended, most recent first.
     *
     * @param  Builder<ClubNight>  $query
     */
    #[Scope]
    protected function past(Builder $query): void
    {
        $query->where($query->qualifyColumn('ends_at'), '<', now())
            ->orderByDesc($query->qualifyColumn('starts_at'))
            ->orderByDesc($query->qualifyColumn('id'));
    }

    /**
     * Adds going_count and waitlist_count, so goingCount()/waitlistCount()/spotsLeft() need no queries.
     *
     * @param  Builder<ClubNight>  $query
     */
    #[Scope]
    protected function withRsvpCounts(Builder $query): void
    {
        $query->withCount([
            'rsvps as going_count' => fn (Builder $rsvps) => $rsvps->where('status', RsvpStatus::Going->value),
            'rsvps as waitlist_count' => fn (Builder $rsvps) => $rsvps->where('status', RsvpStatus::Waitlist->value),
        ]);
    }
}
