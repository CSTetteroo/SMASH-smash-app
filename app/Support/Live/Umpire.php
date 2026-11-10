<?php

namespace App\Support\Live;

use App\Actions\Matches\SaveMatch;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\User;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * Live scoring (umpire mode): every change is saved on the match through SaveMatch, so the public
 * match page shows the score rally by rally, while the rally log (who served, undo history) is
 * kept in LiveScoreStore. Authorisation is the caller's job (BadmintonMatchPolicy::score); SaveMatch
 * additionally only accepts members who play in the match.
 *
 * Every method runs under the match's scoring lock and throws LiveScoringException with a message
 * for the umpire when the request cannot be carried out (ScoreConflict when another device scored
 * in between).
 */
final class Umpire
{
    /**
     * Both sides are known (tournament slots filled, friendly line-up complete).
     */
    public static function lineUpKnown(BadmintonMatch $match): bool
    {
        if ($match->tournament_id !== null) {
            return $match->entry_a_id !== null && $match->entry_b_id !== null;
        }

        $perSide = $match->discipline->playersPerSide();

        return $match->playersOn(Side::A)->count() === $perSide
            && $match->playersOn(Side::B)->count() === $perSide;
    }

    /**
     * Start a scheduled match: it goes live (in progress, game 1 at 0–0) with $server to serve.
     */
    public static function start(BadmintonMatch $match, Side $server, User $actor): LiveScore
    {
        return LiveScoreStore::locked($match, function () use ($match, $server, $actor): LiveScore {
            $match->refresh();

            if ($match->status === MatchStatus::InProgress) {
                throw new LiveScoringException('This match has already started.');
            }

            if ($match->status !== MatchStatus::Scheduled) {
                throw new LiveScoringException('Only a scheduled match can be started.');
            }

            if (! self::lineUpKnown($match)) {
                throw new LiveScoringException('Both sides must be known before the match can start.');
            }

            $state = LiveScore::start($match->best_of, $match->points_per_game)->withServer($server);

            self::save($match, MatchStatus::InProgress, $state->recorded(), $actor);
            LiveScoreStore::put($match, $state);

            return $state;
        });
    }

    /**
     * Apply one change (a rally, an undo, the server choice) to a live match. $seen is the
     * version() the console showed; null skips that check.
     *
     * @param  Closure(LiveScore): LiveScore  $change
     */
    public static function apply(BadmintonMatch $match, User $actor, ?string $seen, Closure $change): LiveScore
    {
        return LiveScoreStore::locked($match, function () use ($match, $actor, $seen, $change): LiveScore {
            $state = self::current($match, $seen);

            try {
                $next = $change($state);
            } catch (LogicException $exception) {
                throw new LiveScoringException($exception->getMessage(), previous: $exception);
            }

            if ($next->recorded() !== $state->recorded()) {
                self::save($match, MatchStatus::InProgress, $next->recorded(), $actor);
            }

            LiveScoreStore::put($match, $next);

            return $next;
        });
    }

    public static function point(BadmintonMatch $match, Side $side, User $actor, ?string $seen = null): LiveScore
    {
        return self::apply($match, $actor, $seen, fn (LiveScore $state): LiveScore => $state->point($side));
    }

    public static function undo(BadmintonMatch $match, User $actor, ?string $seen = null): LiveScore
    {
        return self::apply($match, $actor, $seen, fn (LiveScore $state): LiveScore => $state->undo());
    }

    public static function serve(BadmintonMatch $match, Side $side, User $actor, ?string $seen = null): LiveScore
    {
        return self::apply($match, $actor, $seen, fn (LiveScore $state): LiveScore => $state->withServer($side));
    }

    /**
     * Confirm the result of a decided match: saved as completed through SaveMatch (winner from the
     * games, ratings recalculated, the winner moves on in a knockout) and the log is cleared.
     */
    public static function finish(BadmintonMatch $match, User $actor, ?string $seen = null): BadmintonMatch
    {
        return LiveScoreStore::locked($match, function () use ($match, $actor, $seen): BadmintonMatch {
            $state = self::current($match, $seen);

            if (! $state->isDecided()) {
                throw new LiveScoringException('The match is not decided yet.');
            }

            $saved = self::save($match, MatchStatus::Completed, $state->games(), $actor);
            LiveScoreStore::forget($match);

            return $saved;
        });
    }

    /**
     * The state of the live match as stored right now (fresh from the database).
     */
    private static function current(BadmintonMatch $match, ?string $seen): LiveScore
    {
        $match->refresh();
        $match->load('games');

        try {
            $state = LiveScoreStore::load($match);
        } catch (InvalidArgumentException $exception) {
            throw new LiveScoringException('The saved score of this match cannot be continued here: '.$exception->getMessage(), previous: $exception);
        }

        if ($state === null) {
            throw new LiveScoringException($match->isFinished()
                ? 'This match is already finished.'
                : 'This match is not being played right now.');
        }

        if ($seen !== null && $seen !== $state->version()) {
            throw new ScoreConflict('The score was updated on another device. Check it and tap again.');
        }

        return $state;
    }

    /**
     * @param  list<array{a: int, b: int}>  $games
     */
    private static function save(BadmintonMatch $match, MatchStatus $status, array $games, User $actor): BadmintonMatch
    {
        try {
            return SaveMatch::handle(['status' => $status, 'games' => $games], $match, $actor);
        } catch (ValidationException $exception) {
            $message = Arr::first(Arr::flatten($exception->errors()));

            throw new LiveScoringException(is_string($message) ? $message : 'The score could not be saved.', previous: $exception);
        }
    }
}
