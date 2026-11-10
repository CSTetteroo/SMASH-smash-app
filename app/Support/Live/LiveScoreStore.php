<?php

namespace App\Support\Live;

use App\Enums\MatchStatus;
use App\Models\BadmintonMatch;
use App\Support\Badminton\Scoring;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Where the umpire's log of a live match is kept between requests: one cache key per match
 * ("live-score:{id}"), so a reload restores it and undo works across reloads and devices.
 * The games themselves are always saved on the match too (SaveMatch), which stays the source of
 * truth: a cached log that no longer matches the stored games is ignored.
 */
final class LiveScoreStore
{
    /**
     * How long an untouched log is kept. Long enough for any match; after that the console picks
     * the match up from the stored games and asks who is serving.
     */
    public const TTL_HOURS = 48;

    public static function key(BadmintonMatch|int $match): string
    {
        return 'live-score:'.($match instanceof BadmintonMatch ? $match->getKey() : $match);
    }

    /**
     * The live state of an in-progress match: the cached log while it still describes the stored
     * games, otherwise the stored games picked up afresh. Null when the match is not in progress.
     * Reads only (uses the loaded games relation).
     *
     * @throws InvalidArgumentException when the stored games cannot be continued
     */
    public static function load(BadmintonMatch $match): ?LiveScore
    {
        if ($match->status !== MatchStatus::InProgress) {
            return null;
        }

        $games = Scoring::fromModels($match->games);
        $cached = self::cached($match);

        if ($cached !== null
            && $cached->bestOf === $match->best_of
            && $cached->target === $match->points_per_game
            && $cached->isRecordedAs($games)) {
            return $cached;
        }

        return LiveScore::resume($games, $match->best_of, $match->points_per_game);
    }

    /**
     * The cached log, if there is a valid one.
     */
    public static function cached(BadmintonMatch|int $match): ?LiveScore
    {
        return LiveScore::fromArray(Cache::get(self::key($match)));
    }

    public static function put(BadmintonMatch|int $match, LiveScore $state): void
    {
        Cache::put(self::key($match), $state->toArray(), now()->addHours(self::TTL_HOURS));
    }

    public static function forget(BadmintonMatch|int $match): void
    {
        Cache::forget(self::key($match));
    }

    /**
     * Run $callback while holding the match's scoring lock, so two devices never save over
     * each other.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws LiveScoringException when the lock stays taken
     */
    public static function locked(BadmintonMatch|int $match, Closure $callback): mixed
    {
        try {
            return Cache::lock(self::key($match).':lock', 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            throw new LiveScoringException('Another device is saving a rally for this match. Try again in a moment.');
        }
    }
}
