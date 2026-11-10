<?php

namespace App\Services\Prediction;

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\User;
use App\Services\Rating\EloCalculator;
use App\Services\Rating\RatedHistory;
use App\Services\Rating\RatedResult;
use App\Services\Rating\RatingEngine;
use App\Support\Badminton\Probability;
use App\Support\Badminton\Scoring;
use App\Support\SmashConfig;
use Illuminate\Support\Facades\DB;

/**
 * "Who wins?": win probabilities for a matchup.
 *
 * effective diff = (team rating A − team rating B) + form shift + head-to-head shift, and
 * P(A wins) = Elo expected score of that diff. Form: each side's mean net wins (wins − losses)
 * over the last 5 rated matches in the discipline, × 8 Elo points, capped at ±40. Head-to-head:
 * the Laplace-smoothed win rate of earlier meetings between exactly these sides,
 * (rate − 0.5) · 120 · min(n, 5) / 5, capped at ±50. The match probability is then broken down
 * into a per-game probability (bisection on best-of-N) and a per-rally probability (bisection on
 * a dynamic programme of rally scoring to the target, with win-by-two and the cap), which give
 * the likely scorelines and the favourite's typical winning game.
 */
final class MatchPredictor
{
    /**
     * Head-to-head weight in Elo points for a perfect record, reached after this many meetings.
     */
    public const H2H_WEIGHT = 120.0;

    public const H2H_FULL_WEIGHT_MEETINGS = 5;

    /**
     * Below this distance from 50 % there is no favourite (it rounds to 50–50).
     */
    private const TOSS_UP_MARGIN = 0.005;

    /**
     * Predict a matchup from current ratings and form (e.g. the "Who wins?" page).
     *
     * @param  iterable<User|int>  $sideA
     * @param  iterable<User|int>  $sideB
     */
    public static function predict(iterable $sideA, iterable $sideB, Discipline $discipline, int $bestOf = 3, int $pointsPerGame = 21): MatchPrediction
    {
        return self::fromCurrent(RatingEngine::users($sideA), RatingEngine::users($sideB), $discipline, $bestOf, $pointsPerGame, false);
    }

    /**
     * Predict a stored match. Unfinished matches use current ratings and form; finished ones are
     * predicted as they stood beforehand (rating_before, form and meetings before the match).
     * Tournament matches whose participants are not synced yet use their entries' players.
     */
    public static function forMatch(BadmintonMatch $match): MatchPrediction
    {
        $match->loadMissing(['participants.user', 'entryA.player', 'entryA.partner', 'entryB.player', 'entryB.partner']);

        $playersA = self::sidePlayers($match, Side::A);
        $playersB = self::sidePlayers($match, Side::B);
        $tournament = $match->tournament_id !== null;

        if (! $match->isFinished()) {
            return self::fromCurrent($playersA, $playersB, $match->discipline, $match->best_of, $match->points_per_game, $tournament);
        }

        return self::beforeMatch($match, $playersA, $playersB, $tournament);
    }

    /**
     * Live chance (0–1) that side A wins, from the score so far: completed games plus the game in
     * progress, with the pre-match rally strength from forMatch(). Finished matches give 1 or 0.
     */
    public static function liveProbability(BadmintonMatch $match): float
    {
        if ($match->isFinished()) {
            return match ($match->winning_side) {
                Side::A => 1.0,
                Side::B => 0.0,
                default => 0.5,
            };
        }

        $games = Scoring::fromModels($match->games);
        $won = Scoring::tally($games, $match->points_per_game);
        $last = $games === [] ? null : $games[array_key_last($games)];
        $current = $last !== null && Scoring::isValidInProgressGame($last['a'], $last['b'], $match->points_per_game)
            ? $last
            : ['a' => 0, 'b' => 0];

        return Probability::matchFromScore(
            self::forMatch($match)->rallyProbabilityA,
            $match->best_of,
            $match->points_per_game,
            $won['a'],
            $won['b'],
            $current['a'],
            $current['b'],
        );
    }

    /**
     * @param  list<User>  $playersA
     * @param  list<User>  $playersB
     */
    private static function fromCurrent(array $playersA, array $playersB, Discipline $discipline, int $bestOf, int $pointsPerGame, bool $tournament): MatchPrediction
    {
        $history = RatedHistory::load($discipline, self::ids([...$playersA, ...$playersB]));
        $ratings = [Side::A->value => [], Side::B->value => []];
        $played = [];
        $results = [];

        foreach ([Side::A->value => $playersA, Side::B->value => $playersB] as $side => $players) {
            foreach ($players as $player) {
                $ratings[$side][$player->id] = $player->ratingExact($discipline);
                $played[$player->id] = $player->played($discipline);
                $results[$player->id] = $history->for($player->id);
            }
        }

        return self::build(
            $playersA, $playersB, $ratings[Side::A->value], $ratings[Side::B->value], $played, $results,
            self::meetings(self::ids($playersA), self::ids($playersB), $discipline, null),
            $discipline, $bestOf, $pointsPerGame, $tournament, false,
        );
    }

    /**
     * @param  list<User>  $playersA
     * @param  list<User>  $playersB
     */
    private static function beforeMatch(BadmintonMatch $match, array $playersA, array $playersB, bool $tournament): MatchPrediction
    {
        $discipline = $match->discipline;
        $history = RatedHistory::load($discipline, self::ids([...$playersA, ...$playersB]));
        $playedAt = $match->played_at === null ? null : RatedHistory::format($match->played_at);
        $initial = SmashConfig::initialRating();
        $ratings = [Side::A->value => [], Side::B->value => []];
        $played = [];
        $results = [];

        foreach ([Side::A->value => $playersA, Side::B->value => $playersB] as $side => $players) {
            foreach ($players as $player) {
                $before = $history->before($player->id, $playedAt, $match->id);
                $stored = $match->participantFor($player)?->rating_before;

                $ratings[$side][$player->id] = $stored ?? self::lastRating($before) ?? $initial;
                $played[$player->id] = count($before);
                $results[$player->id] = $before;
            }
        }

        return self::build(
            $playersA, $playersB, $ratings[Side::A->value], $ratings[Side::B->value], $played, $results,
            self::meetings(self::ids($playersA), self::ids($playersB), $discipline, RatedHistory::sortKey($playedAt, $match->id)),
            $discipline, $match->best_of, $match->points_per_game, $tournament, true,
        );
    }

    /**
     * @param  list<User>  $playersA
     * @param  list<User>  $playersB
     * @param  array<int, float>  $ratingsA
     * @param  array<int, float>  $ratingsB
     * @param  array<int, int>  $played  rated matches played (so far) per user id
     * @param  array<int, list<RatedResult>>  $results  rated results per user id, oldest first
     * @param  array{meetings: int, winsA: int, winsB: int}  $meetings
     */
    private static function build(
        array $playersA,
        array $playersB,
        array $ratingsA,
        array $ratingsB,
        array $played,
        array $results,
        array $meetings,
        Discipline $discipline,
        int $bestOf,
        int $pointsPerGame,
        bool $tournament,
        bool $beforeMatch,
    ): MatchPrediction {
        $ratingA = RatingEngine::teamRating($ratingsA);
        $ratingB = RatingEngine::teamRating($ratingsB);

        $window = max(1, SmashConfig::int('prediction.form_window', 5));
        $formA = self::form($playersA, $results, $window);
        $formB = self::form($playersB, $results, $window);
        $formShift = self::limit(
            ($formA['net'] - $formB['net']) * SmashConfig::float('prediction.form_points_per_net_win', 8.0),
            SmashConfig::float('prediction.form_cap', 40.0),
        );
        $headToHeadShift = self::headToHeadShift($meetings['meetings'], $meetings['winsA']);

        $effective = ($ratingA - $ratingB) + $formShift + $headToHeadShift;
        $probabilityA = EloCalculator::expected($effective, 0.0);
        $gameProbability = Probability::gameFromMatch($probabilityA, $bestOf);
        $rallyProbability = Probability::rallyFromGame($gameProbability, $pointsPerGame);

        $favourite = match (true) {
            abs($probabilityA - 0.5) < self::TOSS_UP_MARGIN => null,
            $probabilityA > 0.5 => Side::A,
            default => Side::B,
        };

        $factors = [
            self::ratingFactor($playersA, $playersB, $ratingA, $ratingB, $beforeMatch),
            self::formFactor($formA, $formB, $formShift, $discipline, $window),
            self::headToHeadFactor($playersA, $playersB, $meetings, $headToHeadShift),
        ];

        $provisional = self::provisionalFactor([...$playersA, ...$playersB], $played);

        if ($provisional !== null) {
            $factors[] = $provisional;
        }

        return new MatchPrediction(
            probabilityA: $probabilityA,
            probabilityB: 1.0 - $probabilityA,
            eloProbabilityA: EloCalculator::expected($ratingA, $ratingB),
            ratingA: $ratingA,
            ratingB: $ratingB,
            favourite: $favourite,
            confidence: self::confidence($probabilityA),
            factors: $factors,
            gameProbabilityA: $gameProbability,
            rallyProbabilityA: $rallyProbability,
            scoreDistribution: Probability::scoreDistribution($gameProbability, $bestOf),
            expectedGameScore: Probability::typicalWinningScore($rallyProbability, $pointsPerGame, $favourite ?? Side::A),
            stakes: RatingEngine::stakesFor($ratingsA, $ratingsB, $played, $tournament),
            effectiveDiff: $effective,
            bestOf: $bestOf,
            pointsPerGame: $pointsPerGame,
        );
    }

    /**
     * "Toss-up" < 55 %, "Slight edge" < 65 %, "Favourite" < 80 %, else "Strong favourite".
     */
    public static function confidence(float $probabilityA): string
    {
        $best = max($probabilityA, 1.0 - $probabilityA);

        return match (true) {
            $best < 0.55 => 'Toss-up',
            $best < 0.65 => 'Slight edge',
            $best < 0.80 => 'Favourite',
            default => 'Strong favourite',
        };
    }

    /**
     * Head-to-head shift for side A: (smoothed win rate − 0.5) · 120 · min(n, 5) / 5, capped.
     */
    public static function headToHeadShift(int $meetings, int $winsA): float
    {
        if ($meetings <= 0) {
            return 0.0;
        }

        $rate = ($winsA + 1) / ($meetings + 2);
        $weight = min($meetings, self::H2H_FULL_WEIGHT_MEETINGS) / self::H2H_FULL_WEIGHT_MEETINGS;

        return self::limit(($rate - 0.5) * self::H2H_WEIGHT * $weight, SmashConfig::float('prediction.h2h_max_shift', 50.0));
    }

    /**
     * Wins and losses over each player's last $window results, and the side's mean net wins.
     *
     * @param  list<User>  $players
     * @param  array<int, list<RatedResult>>  $results
     * @return array{wins: int, losses: int, net: float}
     */
    private static function form(array $players, array $results, int $window): array
    {
        $wins = 0;
        $losses = 0;

        foreach ($players as $player) {
            foreach (RatedHistory::latest($results[$player->id] ?? [], $window) as $result) {
                $result->won ? $wins++ : $losses++;
            }
        }

        return [
            'wins' => $wins,
            'losses' => $losses,
            'net' => $players === [] ? 0.0 : ($wins - $losses) / count($players),
        ];
    }

    /**
     * Earlier completed meetings between exactly these two sides (same players, opposite sides,
     * nobody else on court), optionally only those before a point in the replay order.
     *
     * @param  list<int>  $idsA
     * @param  list<int>  $idsB
     * @param  array{0: int, 1: string, 2: int}|null  $before
     * @return array{meetings: int, winsA: int, winsB: int}
     */
    private static function meetings(array $idsA, array $idsB, Discipline $discipline, ?array $before): array
    {
        $result = ['meetings' => 0, 'winsA' => 0, 'winsB' => 0];

        if ($idsA === [] || $idsB === [] || array_intersect($idsA, $idsB) !== []) {
            return $result;
        }

        $everyone = [...$idsA, ...$idsB];

        $rows = DB::table('match_players')
            ->join('matches', 'matches.id', '=', 'match_players.match_id')
            ->whereIn('match_players.user_id', $everyone)
            ->where('matches.status', MatchStatus::Completed->value)
            ->where('matches.discipline', $discipline->value)
            ->whereNotNull('matches.winning_side')
            ->get(['matches.id as match_id', 'matches.played_at', 'matches.winning_side', 'match_players.user_id', 'match_players.side']);

        /** @var array<int, array{played_at: string|null, winner: string, sides: array<int, string>}> $matches */
        $matches = [];

        foreach ($rows as $row) {
            $id = (int) $row->match_id;
            $matches[$id] ??= [
                'played_at' => $row->played_at === null ? null : (string) $row->played_at,
                'winner' => (string) $row->winning_side,
                'sides' => [],
            ];
            $matches[$id]['sides'][(int) $row->user_id] = (string) $row->side;
        }

        $matches = array_filter($matches, fn (array $match): bool => count($match['sides']) === count($everyone));

        if ($matches === []) {
            return $result;
        }

        $sizes = DB::table('match_players')
            ->whereIn('match_id', array_keys($matches))
            ->groupBy('match_id')
            ->selectRaw('match_id, count(*) as players')
            ->pluck('players', 'match_id');

        foreach ($matches as $id => $match) {
            if ((int) ($sizes[$id] ?? 0) !== count($everyone)) {
                continue;
            }

            if ($before !== null && RatedHistory::sortKey($match['played_at'], $id) >= $before) {
                continue;
            }

            $sideA = $match['sides'][$idsA[0]];
            $sideB = $sideA === Side::A->value ? Side::B->value : Side::A->value;
            $together = array_values(array_unique(array_map(fn (int $userId): string => $match['sides'][$userId], $idsA))) === [$sideA];
            $opposite = array_values(array_unique(array_map(fn (int $userId): string => $match['sides'][$userId], $idsB))) === [$sideB];

            if (! $together || ! $opposite) {
                continue;
            }

            $result['meetings']++;
            $match['winner'] === $sideA ? $result['winsA']++ : $result['winsB']++;
        }

        return $result;
    }

    /**
     * @param  list<User>  $playersA
     * @param  list<User>  $playersB
     * @return array{key: string, label: string, detail: string, shift: float}
     */
    private static function ratingFactor(array $playersA, array $playersB, float $ratingA, float $ratingB, bool $beforeMatch): array
    {
        $when = $beforeMatch ? ' before the match' : '';

        $detail = match (true) {
            $playersA === [] || $playersB === [] => 'Players not known yet',
            count($playersA) === 1 && count($playersB) === 1 => sprintf(
                '%s %d vs %s %d%s', $playersA[0]->name, round($ratingA), $playersB[0]->name, round($ratingB), $when,
            ),
            default => sprintf('Team ratings %d vs %d%s (average of both partners)', round($ratingA), round($ratingB), $when),
        };

        return ['key' => 'rating', 'label' => 'Rating', 'detail' => $detail, 'shift' => $ratingA - $ratingB];
    }

    /**
     * @param  array{wins: int, losses: int, net: float}  $formA
     * @param  array{wins: int, losses: int, net: float}  $formB
     * @return array{key: string, label: string, detail: string, shift: float}
     */
    private static function formFactor(array $formA, array $formB, float $shift, Discipline $discipline, int $window): array
    {
        $played = $formA['wins'] + $formA['losses'] + $formB['wins'] + $formB['losses'];

        $detail = $played === 0
            ? 'No rated matches yet'
            : sprintf(
                $discipline === Discipline::Doubles ? '%s vs %s over both partners\' last %d' : '%s vs %s in the last %d',
                $formA['wins'].'–'.$formA['losses'],
                $formB['wins'].'–'.$formB['losses'],
                $window,
            );

        return ['key' => 'form', 'label' => 'Recent form', 'detail' => $detail, 'shift' => $shift];
    }

    /**
     * @param  list<User>  $playersA
     * @param  list<User>  $playersB
     * @param  array{meetings: int, winsA: int, winsB: int}  $meetings
     * @return array{key: string, label: string, detail: string, shift: float}
     */
    private static function headToHeadFactor(array $playersA, array $playersB, array $meetings, float $shift): array
    {
        $winsA = $meetings['winsA'];
        $winsB = $meetings['winsB'];

        $detail = match (true) {
            $meetings['meetings'] === 0 => 'First meeting',
            $winsA > $winsB => sprintf('%s %d–%d in previous meetings', self::leads($playersA), $winsA, $winsB),
            $winsB > $winsA => sprintf('%s %d–%d in previous meetings', self::leads($playersB), $winsB, $winsA),
            default => sprintf('Level at %d–%d in previous meetings', $winsA, $winsB),
        };

        return ['key' => 'head_to_head', 'label' => 'Head-to-head', 'detail' => $detail, 'shift' => $shift];
    }

    /**
     * A note (no shift) when somebody's rating is still provisional.
     *
     * @param  list<User>  $players
     * @param  array<int, int>  $played
     * @return array{key: string, label: string, detail: string, shift: float}|null
     */
    private static function provisionalFactor(array $players, array $played): ?array
    {
        $threshold = SmashConfig::int('rating.provisional_matches', 10);
        $provisional = array_values(array_filter($players, fn (User $player): bool => ($played[$player->id] ?? 0) < $threshold));

        if ($provisional === []) {
            return null;
        }

        $one = count($provisional) === 1;

        return [
            'key' => 'provisional',
            'label' => 'Provisional rating',
            'detail' => sprintf(
                '%s %s played fewer than %d rated matches, so %s still settling',
                self::names($provisional), $one ? 'has' : 'have', $threshold, $one ? 'that rating is' : 'those ratings are',
            ),
            'shift' => 0.0,
        ];
    }

    /**
     * @return list<User>
     */
    private static function sidePlayers(BadmintonMatch $match, Side $side): array
    {
        $players = $match->playersOn($side)->all();

        if ($players !== []) {
            return array_values($players);
        }

        $entry = $match->entryOn($side);

        return $entry === null ? [] : array_values($entry->members()->all());
    }

    /**
     * @param  list<RatedResult>  $results
     */
    private static function lastRating(array $results): ?float
    {
        for ($i = count($results) - 1; $i >= 0; $i--) {
            if ($results[$i]->ratingAfter !== null) {
                return $results[$i]->ratingAfter;
            }
        }

        return null;
    }

    /**
     * @param  list<User>  $players
     * @return list<int>
     */
    private static function ids(array $players): array
    {
        return array_values(array_unique(array_map(fn (User $player): int => $player->id, $players)));
    }

    /**
     * @param  list<User>  $players
     */
    private static function names(array $players): string
    {
        return implode(' & ', array_map(fn (User $player): string => $player->name, $players));
    }

    /**
     * "Sam de Vries leads" / "Sam de Vries & Lotte Jansen lead".
     *
     * @param  list<User>  $players
     */
    private static function leads(array $players): string
    {
        return self::names($players).(count($players) > 1 ? ' lead' : ' leads');
    }

    private static function limit(float $value, float $cap): float
    {
        return max(-$cap, min($cap, $value));
    }
}
