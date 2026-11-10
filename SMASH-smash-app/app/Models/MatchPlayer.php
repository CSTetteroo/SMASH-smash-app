<?php

namespace App\Models;

use App\Enums\Side;
use Carbon\CarbonImmutable;
use Database\Factories\MatchPlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player taking part in a match, on side A or B. The rating engine fills
 * rating_before / rating_after for completed, rated matches (null otherwise).
 *
 * @property int $id
 * @property int $match_id
 * @property int $user_id
 * @property Side $side
 * @property float|null $rating_before
 * @property float|null $rating_after
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read BadmintonMatch $match
 * @property-read User $user
 */
#[Fillable(['match_id', 'user_id', 'side', 'rating_before', 'rating_after'])]
class MatchPlayer extends Model
{
    /** @use HasFactory<MatchPlayerFactory> */
    use HasFactory;

    protected $table = 'match_players';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'match_id' => 'integer',
            'user_id' => 'integer',
            'side' => Side::class,
            'rating_before' => 'float',
            'rating_after' => 'float',
        ];
    }

    /**
     * @return BelongsTo<BadmintonMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(BadmintonMatch::class, 'match_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Rating change in this match (after − before), or null when not rated.
     */
    public function ratingChange(): ?float
    {
        if ($this->rating_before === null || $this->rating_after === null) {
            return null;
        }

        return $this->rating_after - $this->rating_before;
    }
}
