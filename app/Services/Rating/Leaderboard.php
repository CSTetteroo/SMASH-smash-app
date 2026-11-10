<?php

namespace App\Services\Rating;

use App\Enums\Discipline;
use App\Enums\Programme;
use App\Models\User;
use App\Support\SmashConfig;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The rankings for one discipline: active players with at least $minMatches rated matches,
 * best rating first (ties: name, then id), with form, 30-day change and rank movement.
 *
 * Two queries in total, whatever the number of players.
 */
final class Leaderboard
{
    /**
     * Rated results shown as form.
     */
    public const FORM_LENGTH = 5;

    /**
     * Days compared for change30d and movement.
     */
    public const PERIOD_DAYS = 30;

    /**
     * Positions are computed over the full ranking first, so searching or filtering by programme
     * keeps everybody's real position (a search for "Sam" may return a single row at position 17).
     *
     * @return Collection<int, LeaderboardRow>
     */
    public static function for(Discipline $discipline, int $minMatches = 1, ?string $search = null, ?Programme $programme = null): Collection
    {
        $minMatches = max(1, $minMatches);
        $users = User::query()->ranked($discipline, $minMatches)->get();

        if ($users->isEmpty()) {
            return new Collection;
        }

        $history = RatedHistory::load($discipline);
        $initial = SmashConfig::initialRating();
        $then = now()->subDays(self::PERIOD_DAYS);
        $previousRanks = self::ranksAt($users, $history, $minMatches, $then, $initial);

        $rows = [];

        foreach ($users->values() as $index => $user) {
            $position = $index + 1;
            $results = $history->for($user->id);
            $rating = $user->ratingExact($discipline);

            $rows[] = new LeaderboardRow(
                position: $position,
                user: $user,
                rating: $user->rating($discipline),
                played: $user->played($discipline),
                wins: $user->wins($discipline),
                losses: $user->losses($discipline),
                winRate: $user->winRate($discipline),
                form: array_map(
                    fn (RatedResult $result): string => $result->won ? 'W' : 'L',
                    RatedHistory::latest($results, self::FORM_LENGTH),
                ),
                change30d: $results === [] ? 0.0 : $rating - $history->ratingAt($user->id, $then, $initial),
                movement: isset($previousRanks[$user->id]) ? $previousRanks[$user->id] - $position : null,
                provisional: $user->isProvisional($discipline),
                peak: $user->peak($discipline),
            );
        }

        $needle = mb_strtolower(trim((string) $search));

        return (new Collection($rows))
            ->filter(fn (LeaderboardRow $row): bool => ($needle === '' || str_contains(mb_strtolower($row->user->name), $needle))
                && ($programme === null || $row->user->programme === $programme))
            ->values();
    }

    /**
     * Rank of each (currently active) player at $moment: ratings and match counts as they were then.
     *
     * @param  EloquentCollection<int, User>  $users
     * @return array<int, int> user id => position
     */
    private static function ranksAt(EloquentCollection $users, RatedHistory $history, int $minMatches, DateTimeInterface $moment, float $initial): array
    {
        $ranked = [];

        foreach ($users as $user) {
            if ($history->playedBefore($user->id, $moment) >= $minMatches) {
                $ranked[] = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'rating' => $history->ratingAt($user->id, $moment, $initial),
                ];
            }
        }

        usort($ranked, fn (array $a, array $b): int => [$b['rating'], $a['name'], $a['id']] <=> [$a['rating'], $b['name'], $b['id']]);

        $positions = [];

        foreach ($ranked as $index => $row) {
            $positions[$row['id']] = $index + 1;
        }

        return $positions;
    }
}
