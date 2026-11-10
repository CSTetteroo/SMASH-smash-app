<?php

namespace App\Services\Stats;

use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Enums\TournamentStatus;
use App\Models\BadmintonMatch;
use App\Models\ClubNight;
use App\Models\Tournament;
use App\Models\User;
use App\Support\SmashConfig;
use Illuminate\Support\Facades\DB;

/**
 * Club-wide numbers for the landing page.
 */
final class ClubStats
{
    /**
     * members: active players · matches: completed matches · games / points: played in them ·
     * tournaments: published (not draft) · club_nights: nights that have started ·
     * biggest_upset: the completed rated match won by the side with the largest pre-match rating
     * deficit (at least config smash.upset_threshold) · longest_match: the most points played ·
     * upsets: how many such upsets there have been.
     *
     * @return array{
     *     members: int,
     *     matches: int,
     *     games: int,
     *     points: int,
     *     tournaments: int,
     *     club_nights: int,
     *     biggest_upset: BadmintonMatch|null,
     *     longest_match: BadmintonMatch|null,
     *     upsets: int,
     * }
     */
    public static function summary(): array
    {
        $totals = DB::table('match_games')
            ->join('matches', 'matches.id', '=', 'match_games.match_id')
            ->where('matches.status', MatchStatus::Completed->value)
            ->selectRaw('count(*) as games, coalesce(sum(match_games.side_a_points + match_games.side_b_points), 0) as points')
            ->first();

        $longestId = DB::table('match_games')
            ->join('matches', 'matches.id', '=', 'match_games.match_id')
            ->where('matches.status', MatchStatus::Completed->value)
            ->groupBy('match_games.match_id')
            ->orderByRaw('sum(match_games.side_a_points + match_games.side_b_points) desc')
            ->orderByDesc('match_games.match_id')
            ->value('match_games.match_id');

        [$upsetId, $upsets] = self::upsets();

        return [
            'members' => User::query()->active()->count(),
            'matches' => BadmintonMatch::query()->where('status', MatchStatus::Completed->value)->count(),
            'games' => (int) ($totals->games ?? 0),
            'points' => (int) ($totals->points ?? 0),
            'tournaments' => Tournament::query()->where('status', '!=', TournamentStatus::Draft->value)->count(),
            'club_nights' => ClubNight::query()->where('starts_at', '<=', now())->count(),
            'biggest_upset' => self::find($upsetId),
            'longest_match' => self::find($longestId === null ? null : (int) $longestId),
            'upsets' => $upsets,
        ];
    }

    /**
     * Rating gap the winner overcame in a completed, rated match (pre-match team ratings), per match id.
     * Positive = the winner was the underdog.
     *
     * @return array<int, float>
     */
    public static function underdogGaps(): array
    {
        $rows = DB::table('match_players')
            ->join('matches', 'matches.id', '=', 'match_players.match_id')
            ->where('matches.status', MatchStatus::Completed->value)
            ->where('matches.is_rated', true)
            ->whereNotNull('matches.winning_side')
            ->get(['matches.id as match_id', 'matches.winning_side', 'match_players.side', 'match_players.rating_before']);

        /** @var array<int, array{winner: string, ratings: array<string, list<float|null>>}> $matches */
        $matches = [];

        foreach ($rows as $row) {
            $id = (int) $row->match_id;
            $matches[$id] ??= ['winner' => (string) $row->winning_side, 'ratings' => [Side::A->value => [], Side::B->value => []]];
            $matches[$id]['ratings'][(string) $row->side === Side::B->value ? Side::B->value : Side::A->value][] = is_numeric($row->rating_before) ? (float) $row->rating_before : null;
        }

        $gaps = [];

        foreach ($matches as $id => $match) {
            $winner = $match['ratings'][$match['winner']] ?? [];
            $loser = $match['ratings'][$match['winner'] === Side::A->value ? Side::B->value : Side::A->value];

            if ($winner === [] || $loser === [] || in_array(null, $winner, true) || in_array(null, $loser, true)) {
                continue;
            }

            $gaps[$id] = array_sum($loser) / count($loser) - array_sum($winner) / count($winner);
        }

        return $gaps;
    }

    /**
     * @return array{0: int|null, 1: int} biggest upset's match id and the number of upsets
     */
    private static function upsets(): array
    {
        $threshold = SmashConfig::float('upset_threshold', 100.0);
        $upsets = array_filter(self::underdogGaps(), fn (float $gap): bool => $gap >= $threshold);

        if ($upsets === []) {
            return [null, 0];
        }

        uksort($upsets, fn (int $a, int $b): int => [$upsets[$b], $b] <=> [$upsets[$a], $a]);

        return [array_key_first($upsets), count($upsets)];
    }

    private static function find(?int $id): ?BadmintonMatch
    {
        return $id === null ? null : BadmintonMatch::query()->withDetails()->find($id);
    }
}
