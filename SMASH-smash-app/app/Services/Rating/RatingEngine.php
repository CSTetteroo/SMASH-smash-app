<?php

namespace App\Services\Rating;

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\RatingAdjustment;
use App\Models\User;
use App\Support\Database\BulkUpdate;
use App\Support\SmashConfig;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * The single source of truth for ratings.
 *
 * recalculate() replays every completed, rated match in order (played_at, then id; matches
 * without a date first) starting from the initial rating, and writes the outcome: each player's
 * rating / peak / played / wins per discipline, and rating_before / rating_after on every
 * participant row (null for matches that are not rated, not completed, walkovers or cancelled).
 * Doubles sides are rated as the mean of both partners' doubles ratings; every player moves by
 * their own K-factor. It is deterministic, so editing or deleting an old match and recalculating
 * gives exactly the numbers as if history had always been that way.
 *
 * Manual rating adjustments (App\Models\RatingAdjustment: placements, corrections, penalties)
 * are merged into the same replay by date: an adjustment effective at t counts before every match
 * played at or after t (on a tie the adjustment goes first; matches without a date replay before
 * every adjustment). It moves the player's rating, and the peak when it goes higher, but never
 * matches played or won. With no adjustments the replay is exactly the match-only replay.
 *
 * Everything is computed in memory from five reads; only rows whose values change are written,
 * with bulk CASE updates. simulate() runs the same replay as a dry run (to preview an adjustment).
 */
final class RatingEngine
{
    /**
     * Rating values closer than this are treated as unchanged (stored doubles are exact).
     */
    private const TOLERANCE = 1e-9;

    private static int $deferrals = 0;

    private static bool $pending = false;

    /**
     * Replay all rated matches and write the ratings (inside a transaction).
     * While deferred() is running this only marks the ratings as stale.
     */
    public static function recalculate(): void
    {
        if (self::$deferrals > 0) {
            self::$pending = true;

            return;
        }

        DB::transaction(static function (): void {
            self::replay();
        });
    }

    /**
     * Run $callback with recalculation postponed, then recalculate once if anything asked for it.
     * Useful when saving many results in a row (seeding, imports).
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function deferred(callable $callback): mixed
    {
        self::$deferrals++;

        try {
            $result = $callback();
        } catch (Throwable $exception) {
            self::$deferrals--;

            if (self::$deferrals === 0) {
                self::$pending = false;
            }

            throw $exception;
        }

        self::$deferrals--;

        if (self::$deferrals === 0 && self::$pending) {
            self::$pending = false;
            self::recalculate();
        }

        return $result;
    }

    /**
     * Dry run of recalculate(): every player's ratings as they would come out with the stored
     * adjustments changed as described. Nothing is written.
     *
     * Each $with item is a RatingAdjustment (saved or not) or an array with user_id (int|User),
     * discipline (Discipline|string), amount (float|int), effective_at (DateTimeInterface|string)
     * and optionally id. To preview an edit, pass the edited copy with its stored id in $with and
     * that id in $without. Without an id it goes after stored adjustments at the same moment,
     * exactly where it lands once saved.
     *
     * @param  iterable<RatingAdjustment|array<string, mixed>>  $with  adjustments to add
     * @param  list<int>  $without  ids of stored adjustments to leave out
     * @return array<string, array<int, array{rating: float, peak: float, played: int, wins: int}>> discipline value => user id => state (a player missing from a discipline is at the initial rating, nothing played)
     */
    public static function simulate(iterable $with = [], array $without = []): array
    {
        $without = array_map(intval(...), $without);

        $adjustments = array_filter(
            self::storedAdjustments(),
            fn (array $adjustment): bool => ! in_array($adjustment['id'], $without, true),
        );

        foreach ($with as $adjustment) {
            $normalised = self::normaliseAdjustment($adjustment);

            if ($normalised !== null) {
                $adjustments[] = $normalised;
            }
        }

        return self::run(self::inReplayOrder($adjustments))['state'];
    }

    /**
     * What each player stands to gain or lose: rating change per user id if side A wins and if
     * side B wins, from current ratings and each player's own K (typical margin 1.0).
     *
     * @param  iterable<User|int>  $sideA
     * @param  iterable<User|int>  $sideB
     * @return array{a_wins: array<int, float>, b_wins: array<int, float>}
     */
    public static function stakes(iterable $sideA, iterable $sideB, Discipline $discipline, bool $tournament = false): array
    {
        $playersA = self::users($sideA);
        $playersB = self::users($sideB);
        $played = [];

        foreach ([...$playersA, ...$playersB] as $user) {
            $played[$user->id] = $user->played($discipline);
        }

        return self::stakesFor(
            self::ratingsOf($playersA, $discipline),
            self::ratingsOf($playersB, $discipline),
            $played,
            $tournament,
        );
    }

    /**
     * stakes() from explicit pre-match ratings (user id => rating) and rated matches played so far.
     *
     * @param  array<int, float>  $sideA
     * @param  array<int, float>  $sideB
     * @param  array<int, int>  $played
     * @return array{a_wins: array<int, float>, b_wins: array<int, float>}
     */
    public static function stakesFor(array $sideA, array $sideB, array $played, bool $tournament = false): array
    {
        $teamA = self::teamRating($sideA);
        $teamB = self::teamRating($sideB);
        $aWins = [];
        $bWins = [];

        foreach ([[1.0, $sideA], [-1.0, $sideB]] as [$sign, $side]) {
            foreach (array_keys($side) as $userId) {
                $k = EloCalculator::kFactor($played[$userId] ?? 0, $tournament);
                $aWins[$userId] = $sign * EloCalculator::delta($teamA, $teamB, Side::A, $k, 1.0);
                $bWins[$userId] = $sign * EloCalculator::delta($teamA, $teamB, Side::B, $k, 1.0);
            }
        }

        return ['a_wins' => $aWins, 'b_wins' => $bWins];
    }

    /**
     * A side's strength: the mean of its players' ratings (the initial rating for an empty side).
     *
     * @param  array<int, float>  $ratings
     */
    public static function teamRating(array $ratings): float
    {
        return $ratings === [] ? SmashConfig::initialRating() : array_sum($ratings) / count($ratings);
    }

    /**
     * Resolve players given as models or ids (ids are loaded in one query). Models are used as
     * given, duplicates are dropped, order is kept and unknown ids are skipped.
     *
     * @param  iterable<User|int>  $players
     * @return list<User>
     */
    public static function users(iterable $players): array
    {
        $models = [];
        $missing = [];

        foreach ($players as $player) {
            if ($player instanceof User) {
                $models[$player->id] = $player;

                continue;
            }

            $id = (int) $player;

            if (! array_key_exists($id, $models)) {
                $models[$id] = null;
                $missing[] = $id;
            }
        }

        if ($missing !== []) {
            foreach (User::query()->whereKey($missing)->get() as $user) {
                $models[$user->id] ??= $user;
            }
        }

        return array_values(array_filter($models, fn (?User $user): bool => $user !== null));
    }

    private static function replay(): void
    {
        $outcome = self::run(self::storedAdjustments());

        self::writeParticipants($outcome['participants'], $outcome['rated']);
        self::writeUsers($outcome['state'], SmashConfig::initialRating());
    }

    /**
     * The replay: every rated match in order, with the adjustments merged in by date.
     *
     * @param  list<array{id: int, user: int, discipline: string, amount: float, at: string}>  $adjustments  in replay order
     * @return array{state: array<string, array<int, array{rating: float, peak: float, played: int, wins: int}>>, rated: array<int, array{0: float, 1: float}>, participants: Collection<int, stdClass>}
     */
    private static function run(array $adjustments): array
    {
        $initial = SmashConfig::initialRating();

        $matches = DB::table('matches')
            ->where('status', MatchStatus::Completed->value)
            ->where('is_rated', true)
            ->whereNotNull('winning_side')
            ->orderByRaw('played_at is null desc')
            ->orderBy('played_at')
            ->orderBy('id')
            ->get(['id', 'discipline', 'tournament_id', 'best_of', 'winning_side', 'played_at']);

        $participants = DB::table('match_players')
            ->orderBy('id')
            ->get(['id', 'match_id', 'user_id', 'side', 'rating_before', 'rating_after']);

        /** @var array<int, array<string, list<array{row: int, user: int}>>> $sides */
        $sides = [];

        foreach ($participants as $participant) {
            $sides[(int) $participant->match_id][(string) $participant->side][] = [
                'row' => (int) $participant->id,
                'user' => (int) $participant->user_id,
            ];
        }

        /** @var array<int, list<array{a: int, b: int}>> $games */
        $games = [];

        $gameRows = DB::table('match_games')
            ->join('matches', 'matches.id', '=', 'match_games.match_id')
            ->where('matches.status', MatchStatus::Completed->value)
            ->where('matches.is_rated', true)
            ->orderBy('match_games.match_id')
            ->orderBy('match_games.number')
            ->get(['match_games.match_id', 'match_games.side_a_points', 'match_games.side_b_points']);

        foreach ($gameRows as $game) {
            $games[(int) $game->match_id][] = ['a' => (int) $game->side_a_points, 'b' => (int) $game->side_b_points];
        }

        /** @var array<string, array<int, array{rating: float, peak: float, played: int, wins: int}>> $state */
        $state = [];

        /** @var array<int, array{0: float, 1: float}> $rated match_players.id => [before, after] */
        $rated = [];

        $next = 0;
        $pending = count($adjustments);

        foreach ($matches as $match) {
            // Adjustments effective at or before this match's moment count first.
            // Matches without a date replay before every adjustment.
            $playedAt = self::moment($match->played_at);

            while ($playedAt !== null && $next < $pending && strcmp($adjustments[$next]['at'], $playedAt) <= 0) {
                self::adjust($state, $adjustments[$next], $initial);
                $next++;
            }

            $discipline = Discipline::tryFrom((string) $match->discipline);
            $winner = Side::tryFrom((string) $match->winning_side);

            if ($discipline === null || $winner === null) {
                continue;
            }

            $matchId = (int) $match->id;
            $sideA = $sides[$matchId][Side::A->value] ?? [];
            $sideB = $sides[$matchId][Side::B->value] ?? [];
            $perSide = $discipline->playersPerSide();

            if (count($sideA) !== $perSide || count($sideB) !== $perSide) {
                continue;
            }

            $key = $discipline->value;
            $fresh = ['rating' => $initial, 'peak' => $initial, 'played' => 0, 'wins' => 0];

            $teamA = self::mean(array_map(fn (array $player): float => ($state[$key][$player['user']] ?? $fresh)['rating'], $sideA));
            $teamB = self::mean(array_map(fn (array $player): float => ($state[$key][$player['user']] ?? $fresh)['rating'], $sideB));
            $margin = self::margin($games[$matchId] ?? [], $winner, (int) $match->best_of);
            $tournament = $match->tournament_id !== null;

            foreach ([Side::A->value => $sideA, Side::B->value => $sideB] as $side => $players) {
                $sign = $side === Side::A->value ? 1.0 : -1.0;
                $won = $side === $winner->value;

                foreach ($players as $player) {
                    $current = $state[$key][$player['user']] ?? $fresh;
                    $k = EloCalculator::kFactor($current['played'], $tournament);
                    $after = $current['rating'] + $sign * EloCalculator::delta($teamA, $teamB, $winner, $k, $margin);

                    $rated[$player['row']] = [$current['rating'], $after];
                    $state[$key][$player['user']] = [
                        'rating' => $after,
                        'peak' => max($current['peak'], $after),
                        'played' => $current['played'] + 1,
                        'wins' => $current['wins'] + ($won ? 1 : 0),
                    ];
                }
            }
        }

        // Adjustments after the last match (all of them when no match has been played yet).
        while ($next < $pending) {
            self::adjust($state, $adjustments[$next], $initial);
            $next++;
        }

        return ['state' => $state, 'rated' => $rated, 'participants' => $participants];
    }

    /**
     * Apply one adjustment: the rating moves and the peak follows when it goes higher;
     * matches played and won stay as they are.
     *
     * @param  array<string, array<int, array{rating: float, peak: float, played: int, wins: int}>>  $state
     * @param  array{id: int, user: int, discipline: string, amount: float, at: string}  $adjustment
     */
    private static function adjust(array &$state, array $adjustment, float $initial): void
    {
        $current = $state[$adjustment['discipline']][$adjustment['user']]
            ?? ['rating' => $initial, 'peak' => $initial, 'played' => 0, 'wins' => 0];
        $rating = $current['rating'] + $adjustment['amount'];

        $state[$adjustment['discipline']][$adjustment['user']] = [
            'rating' => $rating,
            'peak' => max($current['peak'], $rating),
            'played' => $current['played'],
            'wins' => $current['wins'],
        ];
    }

    /**
     * Stored adjustments in replay order (effective moment, then id).
     *
     * @return list<array{id: int, user: int, discipline: string, amount: float, at: string}>
     */
    private static function storedAdjustments(): array
    {
        $rows = DB::table('rating_adjustments')
            ->orderBy('effective_at')
            ->orderBy('id')
            ->get(['id', 'user_id', 'discipline', 'amount', 'effective_at']);

        $adjustments = [];

        foreach ($rows as $row) {
            $adjustment = self::normaliseAdjustment([
                'id' => $row->id,
                'user_id' => $row->user_id,
                'discipline' => $row->discipline,
                'amount' => $row->amount,
                'effective_at' => $row->effective_at,
            ]);

            if ($adjustment !== null) {
                $adjustments[] = $adjustment;
            }
        }

        return self::inReplayOrder($adjustments);
    }

    /**
     * An adjustment in the replay's shape, or null when it cannot be applied (unknown discipline,
     * missing player, amount or date). Without an id it sorts after stored ones at the same moment.
     *
     * @param  RatingAdjustment|array<string, mixed>  $adjustment
     * @return array{id: int, user: int, discipline: string, amount: float, at: string}|null
     */
    private static function normaliseAdjustment(RatingAdjustment|array $adjustment): ?array
    {
        if ($adjustment instanceof RatingAdjustment) {
            $adjustment = [
                'id' => $adjustment->getKey(),
                'user_id' => $adjustment->getAttribute('user_id'),
                'discipline' => $adjustment->getAttribute('discipline'),
                'amount' => $adjustment->getAttribute('amount'),
                'effective_at' => $adjustment->getAttribute('effective_at'),
            ];
        }

        $user = $adjustment['user_id'] ?? null;
        $user = $user instanceof User ? $user->id : $user;
        $discipline = $adjustment['discipline'] ?? null;
        $discipline = $discipline instanceof Discipline ? $discipline : Discipline::tryFrom(is_string($discipline) ? $discipline : '');
        $amount = $adjustment['amount'] ?? null;
        $at = self::moment($adjustment['effective_at'] ?? null);
        $id = $adjustment['id'] ?? null;

        if (! is_numeric($user) || $discipline === null || ! is_numeric($amount) || $at === null) {
            return null;
        }

        return [
            'id' => is_numeric($id) ? (int) $id : PHP_INT_MAX,
            'user' => (int) $user,
            'discipline' => $discipline->value,
            'amount' => (float) $amount,
            'at' => $at,
        ];
    }

    /**
     * @param  array<array-key, array{id: int, user: int, discipline: string, amount: float, at: string}>  $adjustments
     * @return list<array{id: int, user: int, discipline: string, amount: float, at: string}>
     */
    private static function inReplayOrder(array $adjustments): array
    {
        usort($adjustments, fn (array $a, array $b): int => strcmp($a['at'], $b['at']) ?: $a['id'] <=> $b['id']);

        return $adjustments;
    }

    /**
     * A datetime as stored ("Y-m-d H:i:s", in the app timezone), so moments compare as strings;
     * null when empty. Carbon values keep their own wall-clock time, as Eloquent stores them.
     */
    private static function moment(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = str_replace('T', ' ', trim($value));

        return match (strlen($value)) {
            10 => $value.' 00:00:00',
            16 => $value.':00',
            default => substr($value, 0, 19),
        };
    }

    /**
     * Store rating_before / rating_after where they changed (null for every row that was not rated).
     *
     * @param  Collection<int, stdClass>  $participants
     * @param  array<int, array{0: float, 1: float}>  $rated  match_players.id => [before, after]
     */
    private static function writeParticipants(Collection $participants, array $rated): void
    {
        $changes = [];

        foreach ($participants as $participant) {
            $id = (int) $participant->id;
            [$before, $after] = $rated[$id] ?? [null, null];

            if (! self::sameFloat($participant->rating_before, $before) || ! self::sameFloat($participant->rating_after, $after)) {
                $changes[$id] = ['rating_before' => $before, 'rating_after' => $after];
            }
        }

        BulkUpdate::byId('match_players', $changes);
    }

    /**
     * @param  array<string, array<int, array{rating: float, peak: float, played: int, wins: int}>>  $state
     */
    private static function writeUsers(array $state, float $initial): void
    {
        $columns = ['id'];

        foreach (Discipline::cases() as $discipline) {
            array_push($columns, $discipline->ratingColumn(), $discipline->peakColumn(), $discipline->playedColumn(), $discipline->winsColumn());
        }

        $changes = [];

        foreach (DB::table('users')->get($columns) as $user) {
            $id = (int) $user->id;
            $stored = (array) $user;
            $values = [];
            $changed = false;

            foreach (Discipline::cases() as $discipline) {
                $target = $state[$discipline->value][$id] ?? ['rating' => $initial, 'peak' => $initial, 'played' => 0, 'wins' => 0];

                $values[$discipline->ratingColumn()] = $target['rating'];
                $values[$discipline->peakColumn()] = $target['peak'];
                $values[$discipline->playedColumn()] = $target['played'];
                $values[$discipline->winsColumn()] = $target['wins'];

                $changed = $changed
                    || ! self::sameFloat($stored[$discipline->ratingColumn()] ?? null, $target['rating'])
                    || ! self::sameFloat($stored[$discipline->peakColumn()] ?? null, $target['peak'])
                    || (int) ($stored[$discipline->playedColumn()] ?? -1) !== $target['played']
                    || (int) ($stored[$discipline->winsColumn()] ?? -1) !== $target['wins'];
            }

            if ($changed) {
                $changes[$id] = $values;
            }
        }

        BulkUpdate::byId('users', $changes);
    }

    /**
     * @param  list<array{a: int, b: int}>  $games
     */
    private static function margin(array $games, Side $winner, int $bestOf): float
    {
        if ($games === []) {
            return 1.0;
        }

        $won = [Side::A->value => 0, Side::B->value => 0];
        $points = [Side::A->value => 0, Side::B->value => 0];

        foreach ($games as $game) {
            $points[Side::A->value] += $game['a'];
            $points[Side::B->value] += $game['b'];

            if ($game['a'] !== $game['b']) {
                $won[$game['a'] > $game['b'] ? Side::A->value : Side::B->value]++;
            }
        }

        $loser = $winner->opposite()->value;

        return EloCalculator::marginMultiplier(
            $won[$winner->value],
            $won[$loser],
            $points[$winner->value] - $points[$loser],
            $bestOf,
        );
    }

    /**
     * @param  list<float>  $values
     */
    private static function mean(array $values): float
    {
        return $values === [] ? SmashConfig::initialRating() : array_sum($values) / count($values);
    }

    private static function sameFloat(mixed $stored, ?float $computed): bool
    {
        if ($stored === null || $computed === null) {
            return $stored === null && $computed === null;
        }

        return is_numeric($stored) && abs((float) $stored - $computed) <= self::TOLERANCE;
    }

    /**
     * @param  list<User>  $users
     * @return array<int, float>
     */
    private static function ratingsOf(array $users, Discipline $discipline): array
    {
        $ratings = [];

        foreach ($users as $user) {
            $ratings[$user->id] = $user->ratingExact($discipline);
        }

        return $ratings;
    }
}
