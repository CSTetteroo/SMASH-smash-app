<?php

namespace App\Actions\Predictions;

use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\Prediction;
use App\Models\User;
use App\Support\Badminton\Scoring;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Make or change a pick'em prediction: who wins, and optionally how many games the loser takes
 * (best of 3: 0 = straight games, 1 = three games). One prediction per user and match.
 */
final class PlacePrediction
{
    /**
     * @throws ValidationException key "prediction" when the BadmintonMatchPolicy::predict rules say no
     *                             (playing in it, not scheduled, already started) or the line-up is not known,
     *                             key "loser_games" for an impossible game count
     */
    public static function handle(User $user, BadmintonMatch $match, Side $side, ?int $loserGames = null): Prediction
    {
        if (! Gate::forUser($user)->allows('predict', $match)) {
            throw ValidationException::withMessages(['prediction' => self::reason($user, $match)]);
        }

        if ($match->playersOn(Side::A)->isEmpty() || $match->playersOn(Side::B)->isEmpty()) {
            throw ValidationException::withMessages(['prediction' => 'The players for this match are not known yet.']);
        }

        if ($loserGames !== null && ($loserGames < 0 || $loserGames >= Scoring::gamesToWin($match->best_of))) {
            throw ValidationException::withMessages(['loser_games' => 'Pick a possible result for this match.']);
        }

        return Prediction::query()->updateOrCreate(
            ['user_id' => $user->id, 'match_id' => $match->id],
            ['predicted_side' => $side, 'predicted_loser_games' => $loserGames],
        );
    }

    private static function reason(User $user, BadmintonMatch $match): string
    {
        return match (true) {
            $match->hasParticipant($user) => 'You are playing in this match, so you cannot predict it.',
            $match->status !== MatchStatus::Scheduled => 'Predictions are closed for this match.',
            default => 'Predictions closed when this match started.',
        };
    }
}
