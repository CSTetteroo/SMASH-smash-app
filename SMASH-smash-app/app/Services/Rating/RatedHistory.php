<?php

namespace App\Services\Rating;

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every player's completed, rated results in one discipline, in rating replay order
 * (matches without a date first, then played_at, then id), loaded with a single query.
 *
 * Shared by the leaderboard, the predictor and the player stats so that form, streaks and
 * "rating 30 days ago" are computed the same way everywhere.
 */
final class RatedHistory
{
    /**
     * @param  array<int, list<RatedResult>>  $results  user id => results, oldest first
     */
    private function __construct(private readonly array $results) {}

    /**
     * @param  list<int>|null  $userIds  limit to these players (null = everybody)
     */
    public static function load(Discipline $discipline, ?array $userIds = null): self
    {
        if ($userIds === []) {
            return new self([]);
        }

        $rows = DB::table('match_players')
            ->join('matches', 'matches.id', '=', 'match_players.match_id')
            ->where('matches.status', MatchStatus::Completed->value)
            ->where('matches.is_rated', true)
            ->where('matches.discipline', $discipline->value)
            ->whereNotNull('matches.winning_side')
            ->when($userIds !== null, fn (Builder $query) => $query->whereIn('match_players.user_id', $userIds ?? []))
            ->orderByRaw('matches.played_at is null desc')
            ->orderBy('matches.played_at')
            ->orderBy('matches.id')
            ->get([
                'match_players.user_id',
                'match_players.side',
                'match_players.rating_before',
                'match_players.rating_after',
                'matches.id as match_id',
                'matches.played_at',
                'matches.winning_side',
            ]);

        $results = [];

        foreach ($rows as $row) {
            $side = Side::tryFrom((string) $row->side);

            if ($side === null) {
                continue;
            }

            $results[(int) $row->user_id][] = new RatedResult(
                matchId: (int) $row->match_id,
                playedAt: $row->played_at === null ? null : (string) $row->played_at,
                side: $side,
                won: (string) $row->winning_side === $side->value,
                ratingBefore: is_numeric($row->rating_before) ? (float) $row->rating_before : null,
                ratingAfter: is_numeric($row->rating_after) ? (float) $row->rating_after : null,
            );
        }

        return new self($results);
    }

    /**
     * A player's results, oldest first.
     *
     * @return list<RatedResult>
     */
    public function for(int $userId): array
    {
        return $this->results[$userId] ?? [];
    }

    /**
     * A player's results played strictly before a match (by replay order), oldest first.
     *
     * @return list<RatedResult>
     */
    public function before(int $userId, ?string $playedAt, int $matchId): array
    {
        $key = self::sortKey($playedAt, $matchId);

        return array_values(array_filter(
            $this->for($userId),
            fn (RatedResult $result): bool => $result->sortKey() < $key,
        ));
    }

    /**
     * The player's rating at a moment: rating_after of their last rated result before it,
     * or the initial rating when they had none.
     */
    public function ratingAt(int $userId, DateTimeInterface $moment, float $initial): float
    {
        $rating = $initial;
        $cutoff = self::format($moment);

        foreach ($this->for($userId) as $result) {
            if ($result->playedAt !== null && $result->playedAt >= $cutoff) {
                break;
            }

            if ($result->ratingAfter !== null) {
                $rating = $result->ratingAfter;
            }
        }

        return $rating;
    }

    /**
     * Rated results the player had before a moment.
     */
    public function playedBefore(int $userId, DateTimeInterface $moment): int
    {
        $cutoff = self::format($moment);
        $count = 0;

        foreach ($this->for($userId) as $result) {
            if ($result->playedAt !== null && $result->playedAt >= $cutoff) {
                break;
            }

            $count++;
        }

        return $count;
    }

    /**
     * The most recent results first, at most $limit.
     *
     * @param  list<RatedResult>  $results  oldest first
     * @return list<RatedResult>
     */
    public static function latest(array $results, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return array_reverse(array_slice($results, -$limit));
    }

    /**
     * @return array{0: int, 1: string, 2: int}
     */
    public static function sortKey(?string $playedAt, int $matchId): array
    {
        return [$playedAt === null ? 0 : 1, $playedAt ?? '', $matchId];
    }

    /**
     * A moment in the database's datetime format, for comparing with raw played_at values.
     */
    public static function format(DateTimeInterface $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }
}
