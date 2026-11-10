<?php

namespace App\Models;

use App\Enums\MatchStatus;
use App\Enums\Side;
use Carbon\CarbonImmutable;
use Database\Factories\PredictionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pick'em prediction: who wins a match, optionally how many games the loser takes.
 *
 * Scoring once the match is completed: right winner 3 points, plus 2 when the
 * number of games is right too (5). Wrong winner 0. Walkovers and cancelled matches are void.
 *
 * @property int $id
 * @property int $user_id
 * @property int $match_id
 * @property Side $predicted_side
 * @property int|null $predicted_loser_games
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read BadmintonMatch $match
 */
#[Fillable(['user_id', 'match_id', 'predicted_side', 'predicted_loser_games'])]
class Prediction extends Model
{
    /** @use HasFactory<PredictionFactory> */
    use HasFactory;

    public const POINTS_CORRECT_WINNER = 3;

    public const POINTS_EXACT_BONUS = 2;

    protected $table = 'predictions';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'match_id' => 'integer',
            'predicted_side' => Side::class,
            'predicted_loser_games' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<BadmintonMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(BadmintonMatch::class, 'match_id');
    }

    /**
     * The match has been played to a result (completed with a winner), so the prediction scores.
     */
    public function isSettled(): bool
    {
        return $this->match->status === MatchStatus::Completed && $this->match->winning_side !== null;
    }

    /**
     * The match ended without a contest (walkover) or was cancelled: no points either way.
     */
    public function isVoid(): bool
    {
        return in_array($this->match->status, [MatchStatus::Walkover, MatchStatus::Cancelled], true);
    }

    /**
     * Right winner? Null until the match is settled (and for void matches).
     */
    public function isCorrect(): ?bool
    {
        if (! $this->isSettled()) {
            return null;
        }

        return $this->predicted_side === $this->match->winning_side;
    }

    /**
     * Right winner and right number of games? Null until the match is settled.
     */
    public function isExact(): ?bool
    {
        if (! $this->isSettled()) {
            return null;
        }

        return $this->isCorrect() === true
            && $this->predicted_loser_games !== null
            && $this->predicted_loser_games === $this->match->loserGames();
    }

    /**
     * Null until settled (or void); 0 wrong winner; 3 right winner; 5 right winner and game count.
     */
    public function points(): ?int
    {
        if (! $this->isSettled()) {
            return null;
        }

        if ($this->isCorrect() !== true) {
            return 0;
        }

        return self::POINTS_CORRECT_WINNER + ($this->isExact() === true ? self::POINTS_EXACT_BONUS : 0);
    }

    /**
     * The predicted result from the picked side's point of view, e.g. "2–1"; null without a game count.
     */
    public function predictedScore(): ?string
    {
        if ($this->predicted_loser_games === null) {
            return null;
        }

        $gamesToWin = (int) ceil($this->match->best_of / 2);

        return $gamesToWin.'–'.$this->predicted_loser_games;
    }

    /**
     * Predictions on matches that have been completed (so they score).
     *
     * @param  Builder<Prediction>  $query
     */
    #[Scope]
    protected function settled(Builder $query): void
    {
        $query->whereHas('match', fn (Builder $match) => $match
            ->where('status', MatchStatus::Completed->value)
            ->whereNotNull('winning_side'));
    }

    /**
     * Predictions on matches that have not been played yet.
     *
     * @param  Builder<Prediction>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereHas('match', fn (Builder $match) => $match
            ->whereIn('status', [MatchStatus::Scheduled->value, MatchStatus::InProgress->value]));
    }
}
