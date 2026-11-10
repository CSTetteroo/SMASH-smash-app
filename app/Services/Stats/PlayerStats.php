<?php

namespace App\Services\Stats;

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\MatchPlayer;
use App\Models\User;
use App\Services\Rating\Leaderboard;
use App\Services\Rating\RatedHistory;
use App\Services\Rating\RatedResult;
use App\Support\SmashConfig;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Player profile numbers. Rating, played and wins come from the rating engine (the same values
 * as the rankings); everything else is counted from the player's completed, rated matches in
 * the discipline, in rating replay order.
 */
final class PlayerStats
{
    /**
     * Results needed against an opponent before they count as nemesis / favourite opponent.
     */
    public const RIVAL_MINIMUM = 2;

    public static function for(User $user, Discipline $discipline): PlayerStatsSummary
    {
        $ranked = array_values(User::query()->ranked($discipline)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
        $position = array_search($user->id, $ranked, true);

        $history = RatedHistory::load($discipline, [$user->id]);
        $results = $history->for($user->id);

        $matches = BadmintonMatch::query()
            ->whereKey(array_map(fn (RatedResult $result): int => $result->matchId, $results))
            ->with(['participants.user', 'games'])
            ->get()
            ->keyBy('id');

        $run = 0;
        $longestWinStreak = 0;
        $ratingHistory = [];
        $totals = ['gamesWon' => 0, 'gamesLost' => 0, 'pointsWon' => 0, 'pointsLost' => 0, 'decidersWon' => 0, 'decidersPlayed' => 0];
        /** @var array<int, array{user: User, played: int, wins: int}> $partners */
        $partners = [];
        /** @var array<int, array{user: User, played: int, wins: int, losses: int}> $opponents */
        $opponents = [];
        $bestWin = null;

        foreach ($results as $result) {
            $match = $matches->get($result->matchId);

            if (! $match instanceof BadmintonMatch) {
                continue;
            }

            $side = $result->side;
            $won = $result->won;
            $run = $won ? $run + 1 : 0;
            $longestWinStreak = max($longestWinStreak, $run);

            if ($result->ratingBefore !== null && $result->ratingAfter !== null) {
                $date = $match->played_at ?? $match->scheduled_at ?? $match->created_at ?? CarbonImmutable::now();

                if ($ratingHistory === []) {
                    $ratingHistory[] = ['date' => $date, 'rating' => $result->ratingBefore, 'change' => 0.0, 'match_id' => $match->id];
                }

                $ratingHistory[] = ['date' => $date, 'rating' => $result->ratingAfter, 'change' => $result->ratingAfter - $result->ratingBefore, 'match_id' => $match->id];
            }

            self::countGames($match, $side, $won, $totals);

            foreach ($match->playersOn($side) as $partner) {
                if ($partner->id !== $user->id) {
                    $partners[$partner->id] ??= ['user' => $partner, 'played' => 0, 'wins' => 0];
                    $partners[$partner->id]['played']++;
                    $partners[$partner->id]['wins'] += $won ? 1 : 0;
                }
            }

            foreach ($match->playersOn($side->opposite()) as $opponent) {
                $opponents[$opponent->id] ??= ['user' => $opponent, 'played' => 0, 'wins' => 0, 'losses' => 0];
                $opponents[$opponent->id]['played']++;
                $opponents[$opponent->id]['wins'] += $won ? 1 : 0;
                $opponents[$opponent->id]['losses'] += $won ? 0 : 1;
            }

            if ($won) {
                $bestWin = self::betterWin($bestWin, $match, $side->opposite());
            }
        }

        $initial = SmashConfig::initialRating();
        $rating = $user->ratingExact($discipline);

        return new PlayerStatsSummary(
            rank: $position === false ? null : $position + 1,
            rankedPlayers: count($ranked),
            rating: $user->rating($discipline),
            peak: $user->peak($discipline),
            played: $user->played($discipline),
            wins: $user->wins($discipline),
            losses: $user->losses($discipline),
            winRate: $user->winRate($discipline),
            currentStreak: self::currentStreak($results),
            longestWinStreak: $longestWinStreak,
            form: array_map(
                fn (RatedResult $result): array => ['result' => $result->won ? 'W' : 'L', 'match_id' => $result->matchId],
                RatedHistory::latest($results, Leaderboard::FORM_LENGTH),
            ),
            ratingHistory: $ratingHistory,
            change30d: $results === [] ? 0.0 : $rating - $history->ratingAt($user->id, now()->subDays(Leaderboard::PERIOD_DAYS), $initial),
            bestWin: $bestWin === null ? null : ['match_id' => $bestWin['match_id'], 'opponent' => $bestWin['opponent'], 'opponent_rating' => (int) round($bestWin['rating'])],
            gamesWon: $totals['gamesWon'],
            gamesLost: $totals['gamesLost'],
            pointsWon: $totals['pointsWon'],
            pointsLost: $totals['pointsLost'],
            decidersWon: $totals['decidersWon'],
            decidersPlayed: $totals['decidersPlayed'],
            partners: self::sortPartners($partners),
            nemesis: self::nemesis($opponents),
            favouriteOpponent: self::favouriteOpponent($opponents),
            discipline: $discipline,
            provisional: $user->isProvisional($discipline),
        );
    }

    /**
     * Completed matches with $a and $b on opposite sides (any discipline unless given), newest first.
     */
    public static function headToHead(User $a, User $b, ?Discipline $discipline = null): HeadToHead
    {
        if ($a->is($b)) {
            return new HeadToHead(new Collection, 0, 0, 0, 0, 0, 0, null);
        }

        $matches = BadmintonMatch::query()
            ->where('matches.status', MatchStatus::Completed->value)
            ->whereNotNull('matches.winning_side')
            ->forPlayer($a)
            ->forPlayer($b)
            ->when($discipline !== null, fn (Builder $query) => $query->where('matches.discipline', $discipline?->value))
            ->withDetails()
            ->latestFirst()
            ->get()
            ->filter(function (BadmintonMatch $match) use ($a, $b): bool {
                $sideA = $match->sideOf($a);
                $sideB = $match->sideOf($b);

                return $sideA !== null && $sideB !== null && $sideA !== $sideB;
            })
            ->values();

        $wins = ['a' => 0, 'b' => 0];
        $games = ['a' => 0, 'b' => 0];
        $points = ['a' => 0, 'b' => 0];

        foreach ($matches as $match) {
            $sideA = $match->sideOf($a) ?? Side::A;
            $sideB = $sideA->opposite();

            $wins[$match->winning_side === $sideA ? 'a' : 'b']++;
            $games['a'] += $match->gamesWon($sideA);
            $games['b'] += $match->gamesWon($sideB);
            $points['a'] += $match->pointsWon($sideA);
            $points['b'] += $match->pointsWon($sideB);
        }

        return new HeadToHead(
            matches: $matches,
            winsA: $wins['a'],
            winsB: $wins['b'],
            gamesA: $games['a'],
            gamesB: $games['b'],
            pointsA: $points['a'],
            pointsB: $points['b'],
            lastMeeting: $matches->first(),
        );
    }

    /**
     * @param  array{gamesWon: int, gamesLost: int, pointsWon: int, pointsLost: int, decidersWon: int, decidersPlayed: int}  $totals
     */
    private static function countGames(BadmintonMatch $match, Side $side, bool $won, array &$totals): void
    {
        foreach ($match->games as $game) {
            $for = $game->pointsFor($side);
            $against = $game->pointsFor($side->opposite());

            $totals['pointsWon'] += $for;
            $totals['pointsLost'] += $against;
            $totals['gamesWon'] += $for > $against ? 1 : 0;
            $totals['gamesLost'] += $against > $for ? 1 : 0;
        }

        if ($match->best_of > 1 && $match->games->count() >= $match->best_of) {
            $totals['decidersPlayed']++;
            $totals['decidersWon'] += $won ? 1 : 0;
        }
    }

    /**
     * Keep the win over the strongest side (mean pre-match rating of the opponents).
     *
     * @param  array{match_id: int, opponent: string, rating: float}|null  $best
     * @return array{match_id: int, opponent: string, rating: float}|null
     */
    private static function betterWin(?array $best, BadmintonMatch $match, Side $opponents): ?array
    {
        $rows = $match->participants->filter(fn (MatchPlayer $participant): bool => $participant->side === $opponents);
        $ratings = $rows->map(fn (MatchPlayer $participant): ?float => $participant->rating_before);

        if ($rows->isEmpty() || $ratings->contains(null)) {
            return $best;
        }

        $rating = (float) $ratings->avg();

        if ($best !== null && $rating <= $best['rating']) {
            return $best;
        }

        return [
            'match_id' => $match->id,
            'opponent' => $match->playersOn($opponents)->pluck('name')->join(' & '),
            'rating' => $rating,
        ];
    }

    /**
     * +n for n wins in a row, −n for n losses in a row, counted back from the latest result.
     *
     * @param  list<RatedResult>  $results  oldest first
     */
    private static function currentStreak(array $results): int
    {
        $streak = 0;
        $last = null;

        foreach (array_reverse($results) as $result) {
            if ($last !== null && $result->won !== $last) {
                break;
            }

            $last = $result->won;
            $streak += $result->won ? 1 : -1;
        }

        return $streak;
    }

    /**
     * @param  array<int, array{user: User, played: int, wins: int}>  $partners
     * @return list<array{user: User, played: int, wins: int}>
     */
    private static function sortPartners(array $partners): array
    {
        usort($partners, fn (array $a, array $b): int => [$b['played'], $b['wins'], $a['user']->name, $a['user']->id]
            <=> [$a['played'], $a['wins'], $b['user']->name, $b['user']->id]);

        return $partners;
    }

    /**
     * @param  array<int, array{user: User, played: int, wins: int, losses: int}>  $opponents
     * @return array{user: User, played: int, losses: int}|null
     */
    private static function nemesis(array $opponents): ?array
    {
        $candidates = array_filter($opponents, fn (array $row): bool => $row['losses'] >= self::RIVAL_MINIMUM && $row['losses'] > $row['wins']);

        usort($candidates, fn (array $a, array $b): int => [$b['losses'], $a['wins'], $a['user']->name, $a['user']->id]
            <=> [$a['losses'], $b['wins'], $b['user']->name, $b['user']->id]);

        $top = $candidates[0] ?? null;

        return $top === null ? null : ['user' => $top['user'], 'played' => $top['played'], 'losses' => $top['losses']];
    }

    /**
     * @param  array<int, array{user: User, played: int, wins: int, losses: int}>  $opponents
     * @return array{user: User, played: int, wins: int}|null
     */
    private static function favouriteOpponent(array $opponents): ?array
    {
        $candidates = array_filter($opponents, fn (array $row): bool => $row['wins'] >= self::RIVAL_MINIMUM && $row['wins'] > $row['losses']);

        usort($candidates, fn (array $a, array $b): int => [$b['wins'], $a['losses'], $a['user']->name, $a['user']->id]
            <=> [$a['wins'], $b['losses'], $b['user']->name, $b['user']->id]);

        $top = $candidates[0] ?? null;

        return $top === null ? null : ['user' => $top['user'], 'played' => $top['played'], 'wins' => $top['wins']];
    }
}
