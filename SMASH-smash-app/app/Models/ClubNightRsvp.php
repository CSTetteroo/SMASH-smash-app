<?php

namespace App\Models;

use App\Enums\RsvpStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ClubNightRsvpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's RSVP for a club night (going or waitlist), with optional check-in time.
 *
 * @property int $id
 * @property int $club_night_id
 * @property int $user_id
 * @property RsvpStatus $status
 * @property CarbonImmutable|null $checked_in_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ClubNight $clubNight
 * @property-read User $user
 */
#[Fillable(['club_night_id', 'user_id', 'status', 'checked_in_at'])]
class ClubNightRsvp extends Model
{
    /** @use HasFactory<ClubNightRsvpFactory> */
    use HasFactory;

    protected $table = 'club_night_rsvps';

    /**
     * Defaults mirroring the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'going',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'club_night_id' => 'integer',
            'user_id' => 'integer',
            'status' => RsvpStatus::class,
            'checked_in_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ClubNight, $this>
     */
    public function clubNight(): BelongsTo
    {
        return $this->belongsTo(ClubNight::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isGoing(): bool
    {
        return $this->status === RsvpStatus::Going;
    }

    public function isWaitlisted(): bool
    {
        return $this->status === RsvpStatus::Waitlist;
    }

    public function isCheckedIn(): bool
    {
        return $this->checked_in_at !== null;
    }

    /**
     * @param  Builder<ClubNightRsvp>  $query
     */
    #[Scope]
    protected function going(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), RsvpStatus::Going->value);
    }

    /**
     * Waitlisted RSVPs, first come first served.
     *
     * @param  Builder<ClubNightRsvp>  $query
     */
    #[Scope]
    protected function waitlisted(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), RsvpStatus::Waitlist->value)
            ->orderBy($query->qualifyColumn('created_at'))
            ->orderBy($query->qualifyColumn('id'));
    }
}
