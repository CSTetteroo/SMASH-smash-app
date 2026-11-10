<?php

namespace App\Models;

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\RatingAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A manual change to one player's singles or doubles rating, made by an admin with a reason:
 * a placement for an experienced newcomer, a correction after a data mistake, a penalty.
 *
 * Ratings are never edited by hand. The rating engine replays matches and adjustments in date
 * order, so an adjustment counts from effective_at on: before every match played at or after that
 * moment. It moves the rating (and the peak when it goes higher), never matches played or won.
 * Run RatingEngine::recalculate() after saving or deleting one (the admin page does).
 *
 * @property int $id
 * @property int $user_id
 * @property Discipline $discipline
 * @property float $amount
 * @property string $reason
 * @property CarbonImmutable $effective_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read User|null $creator
 */
#[Fillable(['user_id', 'discipline', 'amount', 'reason', 'effective_at', 'created_by'])]
class RatingAdjustment extends Model
{
    /** @use HasFactory<RatingAdjustmentFactory> */
    use HasFactory;

    /**
     * Largest change one adjustment may make, either way (rating points).
     */
    public const MAX_AMOUNT = 500;

    /**
     * Longest reason (the column length).
     */
    public const REASON_LENGTH = 160;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'discipline' => Discipline::class,
            'amount' => 'float',
            'effective_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    // ------------------------------------------------------------------
    // Relations
    // ------------------------------------------------------------------

    /**
     * The player whose rating changes.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The admin who made the adjustment (null once that account is deleted, or for seeded data).
     *
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
     * Signed amount for display: "+150", "−25" (Unicode minus), "+12.5".
     */
    public function formattedAmount(): string
    {
        return self::format($this->amount);
    }

    /**
     * Signed rating points for display: "+150", "−25" (Unicode minus), "+12.5", "0".
     */
    public static function format(float $amount): string
    {
        $amount = round($amount, 2);
        $number = rtrim(rtrim(number_format(abs($amount), 2, '.', ''), '0'), '.');

        return match (true) {
            $amount > 0 => '+'.$number,
            $amount < 0 => '−'.$number,
            default => '0',
        };
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    /**
     * Changed after it was first saved (shown in the audit log).
     */
    public function wasEdited(): bool
    {
        return $this->created_at !== null
            && $this->updated_at !== null
            && $this->updated_at->greaterThan($this->created_at);
    }

    /**
     * The usual moment for a placement: one minute before the player's first rated match in the
     * discipline, so it counts from their very first result. The season start when they have not
     * played one yet.
     */
    public static function beforeFirstMatch(User|int $user, Discipline $discipline): CarbonImmutable
    {
        $first = self::firstRatedMatchAt($user, $discipline);

        return $first === null ? self::seasonStart() : $first->subMinute();
    }

    /**
     * When the player's first completed, rated match in the discipline was played (null: none yet).
     */
    public static function firstRatedMatchAt(User|int $user, Discipline $discipline): ?CarbonImmutable
    {
        $playedAt = DB::table('match_players')
            ->join('matches', 'matches.id', '=', 'match_players.match_id')
            ->where('match_players.user_id', $user instanceof User ? $user->id : $user)
            ->where('matches.discipline', $discipline->value)
            ->where('matches.status', MatchStatus::Completed->value)
            ->where('matches.is_rated', true)
            ->whereNotNull('matches.winning_side')
            ->whereNotNull('matches.played_at')
            ->min('matches.played_at');

        return is_string($playedAt) && $playedAt !== '' ? CarbonImmutable::parse($playedAt) : null;
    }

    /**
     * 00:00 on 1 September of the season $at falls in (seasons run September to August).
     */
    public static function seasonStart(?CarbonInterface $at = null): CarbonImmutable
    {
        $at = CarbonImmutable::instance($at ?? now());
        $year = $at->month >= 9 ? $at->year : $at->year - 1;

        return $at->setDate($year, 9, 1)->startOfDay();
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    /**
     * @param  Builder<RatingAdjustment>  $query
     */
    #[Scope]
    protected function forDiscipline(Builder $query, Discipline $discipline): void
    {
        $query->where($query->qualifyColumn('discipline'), $discipline->value);
    }

    /**
     * @param  Builder<RatingAdjustment>  $query
     */
    #[Scope]
    protected function forPlayer(Builder $query, User|int $user): void
    {
        $query->where($query->qualifyColumn('user_id'), $user instanceof User ? $user->id : $user);
    }

    /**
     * Most recent effective moment first (ties: newest record first).
     *
     * @param  Builder<RatingAdjustment>  $query
     */
    #[Scope]
    protected function latestFirst(Builder $query): void
    {
        $query->orderByDesc($query->qualifyColumn('effective_at'))
            ->orderByDesc($query->qualifyColumn('id'));
    }

    /**
     * The order the rating engine applies them in: effective moment, then id.
     *
     * @param  Builder<RatingAdjustment>  $query
     */
    #[Scope]
    protected function inReplayOrder(Builder $query): void
    {
        $query->orderBy($query->qualifyColumn('effective_at'))
            ->orderBy($query->qualifyColumn('id'));
    }
}
