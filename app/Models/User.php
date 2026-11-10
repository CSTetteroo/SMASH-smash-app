<?php

namespace App\Models;

use App\Enums\Discipline;
use App\Enums\Handedness;
use App\Enums\Programme;
use App\Enums\SkillLevel;
use App\Enums\UserRole;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * A club member (and player). Ratings are kept separately for singles and doubles
 * and are written by the rating engine; never edit them by hand.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property UserRole $role
 * @property Programme|null $programme
 * @property SkillLevel|null $level
 * @property Handedness|null $handedness
 * @property string|null $bio
 * @property bool $is_active
 * @property float $singles_rating
 * @property float $doubles_rating
 * @property float $singles_peak
 * @property float $doubles_peak
 * @property int $singles_played
 * @property int $doubles_played
 * @property int $singles_wins
 * @property int $doubles_wins
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, MatchPlayer> $matchPlayers
 * @property-read Collection<int, BadmintonMatch> $matches
 * @property-read Collection<int, TournamentEntry> $tournamentEntries
 * @property-read Collection<int, TournamentEntry> $partnerEntries
 * @property-read Collection<int, ClubNightRsvp> $rsvps
 * @property-read Collection<int, ClubNight> $clubNights
 * @property-read Collection<int, Prediction> $predictions
 * @property-read Collection<int, RatingAdjustment> $ratingAdjustments
 * @property-read int|null $match_players_count
 * @property-read int|null $predictions_count
 */
#[Fillable([
    'name',
    'email',
    'password',
    'role',
    'programme',
    'level',
    'handedness',
    'bio',
    'is_active',
    'singles_rating',
    'doubles_rating',
    'singles_peak',
    'doubles_peak',
    'singles_played',
    'doubles_played',
    'singles_wins',
    'doubles_wins',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Surname particles kept with the family name ("de Vries", "van der Berg", "di Stefano").
     */
    private const NAME_PARTICLES = [
        "'t", 'al', 'bin', 'binti', 'da', 'das', 'de', 'del', 'della', 'den', 'der', 'di',
        'do', 'dos', 'du', 'el', 'la', 'le', 'lo', 'te', 'ten', 'ter', 'van', 'von', 'zu',
    ];

    /**
     * Defaults mirroring the database, so a freshly created (not yet refreshed) user is complete.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'member',
        'is_active' => true,
        'singles_rating' => 1500.0,
        'doubles_rating' => 1500.0,
        'singles_peak' => 1500.0,
        'doubles_peak' => 1500.0,
        'singles_played' => 0,
        'doubles_played' => 0,
        'singles_wins' => 0,
        'doubles_wins' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'programme' => Programme::class,
            'level' => SkillLevel::class,
            'handedness' => Handedness::class,
            'is_active' => 'boolean',
            'singles_rating' => 'float',
            'doubles_rating' => 'float',
            'singles_peak' => 'float',
            'doubles_peak' => 'float',
            'singles_played' => 'integer',
            'doubles_played' => 'integer',
            'singles_wins' => 'integer',
            'doubles_wins' => 'integer',
        ];
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    /**
     * @return HasMany<MatchPlayer, $this>
     */
    public function matchPlayers(): HasMany
    {
        return $this->hasMany(MatchPlayer::class);
    }

    /**
     * Matches this user played in (any side, any status).
     *
     * @return BelongsToMany<BadmintonMatch, $this>
     */
    public function matches(): BelongsToMany
    {
        return $this->belongsToMany(BadmintonMatch::class, 'match_players', 'user_id', 'match_id')
            ->withPivot('side', 'rating_before', 'rating_after')
            ->withTimestamps();
    }

    /**
     * Tournament entries where this user is the (first) player.
     *
     * @return HasMany<TournamentEntry, $this>
     */
    public function tournamentEntries(): HasMany
    {
        return $this->hasMany(TournamentEntry::class, 'player_id');
    }

    /**
     * Doubles tournament entries where this user is the partner.
     *
     * @return HasMany<TournamentEntry, $this>
     */
    public function partnerEntries(): HasMany
    {
        return $this->hasMany(TournamentEntry::class, 'partner_id');
    }

    /**
     * @return HasMany<ClubNightRsvp, $this>
     */
    public function rsvps(): HasMany
    {
        return $this->hasMany(ClubNightRsvp::class);
    }

    /**
     * Club nights this user RSVP'd to (going or waitlist).
     *
     * @return BelongsToMany<ClubNight, $this>
     */
    public function clubNights(): BelongsToMany
    {
        return $this->belongsToMany(ClubNight::class, 'club_night_rsvps', 'user_id', 'club_night_id')
            ->withPivot('status', 'checked_in_at')
            ->withTimestamps();
    }

    /**
     * Pick'em predictions made by this user.
     *
     * @return HasMany<Prediction, $this>
     */
    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    /**
     * Manual rating adjustments (placements, corrections, penalties) for this player.
     *
     * @return HasMany<RatingAdjustment, $this>
     */
    public function ratingAdjustments(): HasMany
    {
        return $this->hasMany(RatingAdjustment::class);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Current rating, rounded for display.
     */
    public function rating(Discipline $discipline): int
    {
        return (int) round($this->ratingExact($discipline));
    }

    /**
     * Current rating as stored (unrounded), for calculations.
     */
    public function ratingExact(Discipline $discipline): float
    {
        return match ($discipline) {
            Discipline::Singles => (float) $this->singles_rating,
            Discipline::Doubles => (float) $this->doubles_rating,
        };
    }

    /**
     * Highest rating ever reached, rounded.
     */
    public function peak(Discipline $discipline): int
    {
        $peak = match ($discipline) {
            Discipline::Singles => (float) $this->singles_peak,
            Discipline::Doubles => (float) $this->doubles_peak,
        };

        return (int) round($peak);
    }

    /**
     * Rated matches played in the discipline.
     */
    public function played(Discipline $discipline): int
    {
        return match ($discipline) {
            Discipline::Singles => (int) $this->singles_played,
            Discipline::Doubles => (int) $this->doubles_played,
        };
    }

    /**
     * Rated matches won in the discipline.
     */
    public function wins(Discipline $discipline): int
    {
        return match ($discipline) {
            Discipline::Singles => (int) $this->singles_wins,
            Discipline::Doubles => (int) $this->doubles_wins,
        };
    }

    public function losses(Discipline $discipline): int
    {
        return max(0, $this->played($discipline) - $this->wins($discipline));
    }

    /**
     * Share of rated matches won (0–1), or null when nothing has been played yet.
     */
    public function winRate(Discipline $discipline): ?float
    {
        $played = $this->played($discipline);

        return $played === 0 ? null : $this->wins($discipline) / $played;
    }

    /**
     * A rating is provisional until the player has completed enough rated matches.
     */
    public function isProvisional(Discipline $discipline): bool
    {
        return $this->played($discipline) < config()->integer('smash.rating.provisional_matches', 10);
    }

    /**
     * "Sam de Vries" => "Sam".
     */
    public function firstName(): string
    {
        return $this->nameParts()[0] ?? '';
    }

    /**
     * Family name including particles: "Sam de Vries" => "de Vries", "Lotte Jansen" => "Jansen".
     */
    public function lastName(): string
    {
        $parts = $this->nameParts();
        $count = count($parts);

        if ($count < 2) {
            return $parts[0] ?? '';
        }

        for ($i = 1; $i < $count - 1; $i++) {
            if (in_array(mb_strtolower($parts[$i]), self::NAME_PARTICLES, true)) {
                return implode(' ', array_slice($parts, $i));
            }
        }

        return $parts[$count - 1];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * @return list<string>
     */
    private function nameParts(): array
    {
        $parts = preg_split('/\s+/u', trim((string) $this->name), -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : $parts;
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    /**
     * Members who currently play (inactive players are hidden from rankings and pickers).
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function admins(Builder $query): void
    {
        $query->where($query->qualifyColumn('role'), UserRole::Admin->value);
    }

    /**
     * Active players with at least $minMatches rated matches in the discipline,
     * best rating first (ties: name, then id).
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function ranked(Builder $query, Discipline $discipline, int $minMatches = 1): void
    {
        $query->where($query->qualifyColumn('is_active'), true)
            ->where($query->qualifyColumn($discipline->playedColumn()), '>=', $minMatches)
            ->orderByDesc($query->qualifyColumn($discipline->ratingColumn()))
            ->orderBy($query->qualifyColumn('name'))
            ->orderBy($query->qualifyColumn('id'));
    }
}
