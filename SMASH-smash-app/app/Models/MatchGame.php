<?php

namespace App\Models;

use App\Enums\Side;
use Carbon\CarbonImmutable;
use Database\Factories\MatchGameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One game of a match (rally scoring, e.g. 21–17).
 *
 * @property int $id
 * @property int $match_id
 * @property int $number
 * @property int $side_a_points
 * @property int $side_b_points
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read BadmintonMatch $match
 */
#[Fillable(['match_id', 'number', 'side_a_points', 'side_b_points'])]
class MatchGame extends Model
{
    /** @use HasFactory<MatchGameFactory> */
    use HasFactory;

    protected $table = 'match_games';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'match_id' => 'integer',
            'number' => 'integer',
            'side_a_points' => 'integer',
            'side_b_points' => 'integer',
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
     * The side with more points. Only meaningful for a completed game (a level game returns A).
     */
    public function winner(): Side
    {
        return $this->side_b_points > $this->side_a_points ? Side::B : Side::A;
    }

    /**
     * The side currently ahead, or null when level.
     */
    public function leader(): ?Side
    {
        return match (true) {
            $this->side_a_points > $this->side_b_points => Side::A,
            $this->side_b_points > $this->side_a_points => Side::B,
            default => null,
        };
    }

    public function pointsFor(Side $side): int
    {
        return $side === Side::A ? $this->side_a_points : $this->side_b_points;
    }

    /**
     * Whether the score is a valid final score for a game to $target points
     * (mirrors App\Support\Badminton\Scoring::isCompleteGame()): reach the target
     * with a two-point lead, extend to a two-point lead, or win the golden point at the cap.
     */
    public function isComplete(int $target = 21): bool
    {
        $high = max($this->side_a_points, $this->side_b_points);
        $low = min($this->side_a_points, $this->side_b_points);
        $cap = self::cap($target);

        return ($high === $target && $low <= $target - 2)
            || ($high > $target && $high <= $cap && $high - $low === 2)
            || ($high === $cap && $low === $cap - 1);
    }

    /**
     * Score at which the golden point is played: 21 => 30, 15 => 21, 11 => 15.
     */
    public static function cap(int $target): int
    {
        return match ($target) {
            21 => 30,
            15 => 21,
            11 => 15,
            default => $target + 9,
        };
    }

    /**
     * "21–17" (side A first).
     */
    public function score(): string
    {
        return $this->side_a_points.'–'.$this->side_b_points;
    }
}
