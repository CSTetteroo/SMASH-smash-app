<?php

namespace App\Actions\Matches;

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\MatchGame;
use App\Models\TournamentEntry;
use App\Models\User;
use App\Services\Rating\RatingEngine;
use App\Services\Tournament\TournamentProgress;
use App\Support\Badminton\Scoring;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Create or update a match and everything that follows from it: participants, games, winner,
 * ratings (recalculated whenever the match is or was finished) and tournament progress.
 *
 * $data keys (all optional when updating; missing keys keep the stored value):
 *   discipline       Discipline|string     required for a new match; tournament matches keep the tournament's
 *   side_a, side_b   list<int|User>        players per side (friendlies; tournament matches use their entries)
 *   status           MatchStatus|string    default "scheduled"
 *   scheduled_at     string|DateTimeInterface|null
 *   played_at        string|DateTimeInterface|null   finished matches only; defaults to now
 *   court            int|null              1–99
 *   venue, notes     string|null
 *   best_of          int                   1, 3 or 5 (default 3)
 *   points_per_game  int                   11, 15 or 21 (default 21)
 *   is_rated         bool                  default true
 *   games            list<array{a: int, b: int}>  also accepts [21, 19] or side_a_points/side_b_points rows;
 *                                          blank rows are ignored. Missing on update = keep the stored games
 *   walkover_winner  'a'|'b'|Side|null     required for walkovers
 *
 * Completed: the games must be valid and decide the match (winner taken from them). In progress:
 * all but the last game complete, match not decided. Walkover: no games, never changes ratings.
 * Scheduled / cancelled: no games, no winner.
 *
 * @throws ValidationException keyed by the $data key at fault (games, side_a, side_b, status, walkover_winner, …)
 */
final class SaveMatch
{
    public const BEST_OF = [1, 3, 5];

    public const POINTS_PER_GAME = [11, 15, 21];

    /**
     * @param  array<string, mixed>  $data
     */
    public static function handle(array $data, ?BadmintonMatch $match = null, ?User $actor = null): BadmintonMatch
    {
        $match ??= new BadmintonMatch;
        $creating = ! $match->exists;
        $isTournament = $match->tournament_id !== null;
        $errors = [];

        $before = self::snapshot($match);

        $discipline = $isTournament ? $match->discipline : self::discipline($data, $match, $errors);
        $status = self::status($data, $match, $errors);
        $bestOf = self::choice($data, 'best_of', $creating ? 3 : $match->best_of, self::BEST_OF, 'A match is played over 1, 3 or 5 games.', $errors);
        $pointsPerGame = self::choice($data, 'points_per_game', $creating ? 21 : $match->points_per_game, self::POINTS_PER_GAME, 'Games are played to 11, 15 or 21 points.', $errors);
        $isRated = array_key_exists('is_rated', $data) ? filter_var($data['is_rated'], FILTER_VALIDATE_BOOLEAN) : ($creating || $match->is_rated);
        $scheduledAt = array_key_exists('scheduled_at', $data) ? self::date($data['scheduled_at'], 'scheduled_at', $errors) : $match->scheduled_at;
        $playedAt = array_key_exists('played_at', $data) ? self::date($data['played_at'], 'played_at', $errors) : null;
        $court = array_key_exists('court', $data) ? self::court($data['court'], $errors) : $match->court;
        $venue = array_key_exists('venue', $data) ? self::text($data['venue'], 255, 'venue', $errors) : $match->venue;
        $notes = array_key_exists('notes', $data) ? self::text($data['notes'], 5000, 'notes', $errors) : $match->notes;

        [$sideA, $sideB] = $isTournament
            ? self::entryPlayers($match)
            : self::players($data, $match, $discipline, $errors);

        $staysBye = $match->isBye() && $status === MatchStatus::Walkover;

        if ($isTournament && $status !== null && $status !== MatchStatus::Scheduled && $status !== MatchStatus::Cancelled
            && ($match->entry_a_id === null || $match->entry_b_id === null) && ! $staysBye) {
            $errors['status'][] = 'Both sides of this match must be known before a result can be entered.';
        }

        if ($actor !== null && ! $actor->isAdmin() && ! in_array($actor->id, [...$sideA, ...$sideB], true)) {
            $errors['side_a'][] = 'You can only record matches you play in.';
        }

        $games = [];
        $winner = null;

        if ($status !== null) {
            $games = match ($status) {
                MatchStatus::Completed, MatchStatus::InProgress => array_key_exists('games', $data)
                    ? self::games($data['games'], $errors)
                    : Scoring::fromModels($match->exists ? $match->games()->get() : []),
                default => [],
            };

            if (! isset($errors['games']) && in_array($status, [MatchStatus::Completed, MatchStatus::InProgress], true)) {
                foreach (Scoring::validate($games, $bestOf, $pointsPerGame, $status === MatchStatus::Completed) as $message) {
                    $errors['games'][] = $message;
                }
            }

            $winner = match ($status) {
                MatchStatus::Completed => Scoring::matchWinner($games, $bestOf, $pointsPerGame),
                MatchStatus::Walkover => self::walkoverWinner($data, $match, $errors),
                default => null,
            };
        }

        if ($errors === [] && $status !== null && $match->exists && $isTournament) {
            $advancing = $status->isFinished() && $winner !== null
                ? ($winner === Side::A ? $match->entry_a_id : $match->entry_b_id)
                : null;

            if ($advancing !== $before['advancing'] && ! TournamentProgress::canChangeWinner($match)) {
                $errors['games'][] = 'The next round has already started, so this result can no longer change who goes through. Reset the next-round match first.';
            }
        }

        if ($errors !== [] || $status === null) {
            throw ValidationException::withMessages($errors);
        }

        $finished = $status->isFinished();

        // Finished matches keep (or get) a date; byes are never "played".
        $playedAt = match (true) {
            ! $finished => null,
            $staysBye => $playedAt ?? $match->played_at,
            default => $playedAt ?? $match->played_at ?? CarbonImmutable::now(),
        };

        DB::transaction(static function () use (
            $match, $creating, $actor, $discipline, $status, $bestOf, $pointsPerGame, $isRated, $scheduledAt,
            $playedAt, $court, $venue, $notes, $sideA, $sideB, $games, $winner, $finished, $isTournament, $before,
        ): void {
            $match->fill([
                'discipline' => $discipline,
                'status' => $status,
                'best_of' => $bestOf,
                'points_per_game' => $pointsPerGame,
                'is_rated' => $isRated,
                'scheduled_at' => $scheduledAt,
                'played_at' => $playedAt,
                'court' => $court,
                'venue' => $venue,
                'notes' => $notes,
                'winning_side' => $winner,
            ]);

            if ($creating) {
                $match->created_by = $actor?->id;
            }

            $match->save();

            if ($isTournament) {
                TournamentProgress::syncParticipants($match);
            } else {
                self::syncPlayers($match, $sideA, $sideB);
            }

            self::replaceGames($match, $games);

            if ($isTournament) {
                TournamentProgress::matchUpdated($match);
            }

            if ($finished || $before['finished']) {
                RatingEngine::recalculate();
            }
        });

        $match->refresh();
        $match->load(['participants.user', 'games']);

        return $match;
    }

    /**
     * The stored result before this save (raw originals, so unsaved changes on the model don't count).
     *
     * @return array{finished: bool, advancing: int|null}
     */
    private static function snapshot(BadmintonMatch $match): array
    {
        if (! $match->exists) {
            return ['finished' => false, 'advancing' => null];
        }

        $status = MatchStatus::tryFrom((string) $match->getRawOriginal('status'));
        $winner = Side::tryFrom((string) $match->getRawOriginal('winning_side'));
        $finished = $status?->isFinished() ?? false;
        $entry = $winner === Side::A ? $match->getRawOriginal('entry_a_id') : $match->getRawOriginal('entry_b_id');

        return [
            'finished' => $finished,
            'advancing' => $finished && $winner !== null && $entry !== null ? (int) $entry : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $errors
     */
    private static function discipline(array $data, BadmintonMatch $match, array &$errors): Discipline
    {
        $value = $data['discipline'] ?? null;

        if ($value === null || $value === '') {
            if ($match->exists) {
                return $match->discipline;
            }

            $errors['discipline'][] = 'Choose singles or doubles.';

            return Discipline::Singles;
        }

        $discipline = $value instanceof Discipline ? $value : Discipline::tryFrom(is_string($value) ? $value : '');

        if ($discipline === null) {
            $errors['discipline'][] = 'Choose singles or doubles.';

            return Discipline::Singles;
        }

        return $discipline;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $errors
     */
    private static function status(array $data, BadmintonMatch $match, array &$errors): ?MatchStatus
    {
        $value = $data['status'] ?? null;

        if ($value === null || $value === '') {
            return $match->exists ? $match->status : MatchStatus::Scheduled;
        }

        $status = $value instanceof MatchStatus ? $value : MatchStatus::tryFrom(is_string($value) ? $value : '');

        if ($status === null) {
            $errors['status'][] = 'Choose a valid match status.';
        }

        return $status;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $allowed
     * @param  array<string, list<string>>  $errors
     */
    private static function choice(array $data, string $key, int $default, array $allowed, string $message, array &$errors): int
    {
        $value = $data[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT);

        if (! is_int($number) || ! in_array($number, $allowed, true)) {
            $errors[$key][] = $message;

            return $default;
        }

        return $number;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private static function date(mixed $value, string $key, array &$errors): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_string($value)) {
            try {
                return CarbonImmutable::parse($value);
            } catch (Throwable) {
                // Reported below.
            }
        }

        $errors[$key][] = 'Enter a valid date and time.';

        return null;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private static function court(mixed $value, array &$errors): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $court = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99]]);

        if (! is_int($court)) {
            $errors['court'][] = 'The court is a number from 1 to 99.';

            return null;
        }

        return $court;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private static function text(mixed $value, int $max, string $key, array &$errors): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            $errors[$key][] = 'Enter text.';

            return null;
        }

        $text = trim((string) $value);

        if (mb_strlen($text) > $max) {
            $errors[$key][] = sprintf('Keep this under %d characters.', $max);
        }

        return $text === '' ? null : $text;
    }

    /**
     * Friendly line-up from side_a / side_b (or the stored one when not given), validated.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $errors
     * @return array{0: list<int>, 1: list<int>}
     */
    private static function players(array $data, BadmintonMatch $match, Discipline $discipline, array &$errors): array
    {
        $stored = fn (Side $side): array => $match->exists
            ? self::ids($match->participants()->where('side', $side->value)->orderBy('id')->pluck('user_id')->all())
            : [];

        $sideA = array_key_exists('side_a', $data) ? self::ids($data['side_a']) : $stored(Side::A);
        $sideB = array_key_exists('side_b', $data) ? self::ids($data['side_b']) : $stored(Side::B);
        $perSide = $discipline->playersPerSide();
        $label = $perSide === 1 ? '1 player' : $perSide.' players';

        if (count($sideA) !== $perSide) {
            $errors['side_a'][] = sprintf('Choose %s for side A.', $label);
        }

        if (count($sideB) !== $perSide) {
            $errors['side_b'][] = sprintf('Choose %s for side B.', $label);
        }

        if (array_intersect($sideA, $sideB) !== []) {
            $errors['side_b'][] = 'A player cannot be on both sides.';
        }

        $everyone = array_values(array_unique([...$sideA, ...$sideB]));

        if ($everyone !== [] && User::query()->whereKey($everyone)->count() !== count($everyone)) {
            $errors['side_a'][] = 'Choose players who are club members.';
        }

        return [$sideA, $sideB];
    }

    /**
     * The players of the two entries of a tournament match.
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    private static function entryPlayers(BadmintonMatch $match): array
    {
        $ids = fn (?int $entryId): array => $entryId === null
            ? []
            : array_values(array_filter(
                TournamentEntry::query()->whereKey($entryId)->first(['player_id', 'partner_id'])?->only(['player_id', 'partner_id']) ?? [],
                fn (mixed $id): bool => $id !== null,
            ));

        /** @var list<int> $sideA */
        $sideA = array_map(fn (mixed $id): int => (int) $id, $ids($match->entry_a_id));
        /** @var list<int> $sideB */
        $sideB = array_map(fn (mixed $id): int => (int) $id, $ids($match->entry_b_id));

        return [$sideA, $sideB];
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        $ids = [];

        foreach (is_iterable($value) ? $value : [$value] as $player) {
            $id = $player instanceof User ? $player->id : filter_var($player, FILTER_VALIDATE_INT);

            if (is_int($id) && $id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Game rows from a form or an API: ['a' => 21, 'b' => 19], [21, 19] or side_a_points/side_b_points.
     * Rows with both scores blank are skipped.
     *
     * @param  array<string, list<string>>  $errors
     * @return list<array{a: int, b: int}>
     */
    private static function games(mixed $rows, array &$errors): array
    {
        if ($rows === null || $rows === '') {
            return [];
        }

        if (! is_iterable($rows)) {
            $errors['games'][] = 'Enter the scores game by game.';

            return [];
        }

        $games = [];

        foreach ($rows as $row) {
            if ($row instanceof MatchGame) {
                $games[] = ['a' => $row->side_a_points, 'b' => $row->side_b_points];

                continue;
            }

            $row = is_array($row) ? $row : [];
            $a = $row['a'] ?? $row['side_a_points'] ?? $row[0] ?? null;
            $b = $row['b'] ?? $row['side_b_points'] ?? $row[1] ?? null;
            $number = count($games) + 1;

            if (($a === null || $a === '') && ($b === null || $b === '')) {
                continue;
            }

            if ($a === null || $a === '' || $b === null || $b === '') {
                $errors['games'][] = sprintf('Enter both scores for game %d.', $number);

                continue;
            }

            $pointsA = filter_var($a, FILTER_VALIDATE_INT);
            $pointsB = filter_var($b, FILTER_VALIDATE_INT);

            if (! is_int($pointsA) || ! is_int($pointsB) || $pointsA < 0 || $pointsB < 0) {
                $errors['games'][] = sprintf('Scores for game %d must be whole numbers of 0 or more.', $number);

                continue;
            }

            $games[] = ['a' => $pointsA, 'b' => $pointsB];
        }

        return $games;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $errors
     */
    private static function walkoverWinner(array $data, BadmintonMatch $match, array &$errors): ?Side
    {
        $value = array_key_exists('walkover_winner', $data)
            ? $data['walkover_winner']
            : ($match->status === MatchStatus::Walkover ? $match->winning_side : null);

        $side = $value instanceof Side ? $value : Side::tryFrom(is_string($value) ? strtolower($value) : '');

        if ($side === null) {
            $errors['walkover_winner'][] = 'Choose which side is awarded the walkover.';
        }

        return $side;
    }

    /**
     * Make the friendly's participants exactly these players (keeps existing rows where possible).
     *
     * @param  list<int>  $sideA
     * @param  list<int>  $sideB
     */
    private static function syncPlayers(BadmintonMatch $match, array $sideA, array $sideB): void
    {
        /** @var array<int, Side> $wanted */
        $wanted = [];

        foreach ($sideA as $id) {
            $wanted[$id] = Side::A;
        }

        foreach ($sideB as $id) {
            $wanted[$id] = Side::B;
        }

        foreach ($match->participants()->get() as $participant) {
            $side = $wanted[$participant->user_id] ?? null;

            if ($side === null) {
                $participant->delete();

                continue;
            }

            if ($participant->side !== $side) {
                $participant->side = $side;
                $participant->save();
            }

            unset($wanted[$participant->user_id]);
        }

        foreach ([...$sideA, ...$sideB] as $id) {
            if (isset($wanted[$id])) {
                $match->participants()->create(['user_id' => $id, 'side' => $wanted[$id]]);
            }
        }
    }

    /**
     * Store the games (numbered 1..n), leaving them alone when nothing changed.
     *
     * @param  list<array{a: int, b: int}>  $games
     */
    private static function replaceGames(BadmintonMatch $match, array $games): void
    {
        if (Scoring::fromModels($match->games()->get()) === $games) {
            return;
        }

        $match->games()->delete();

        foreach ($games as $index => $game) {
            $match->games()->create([
                'number' => $index + 1,
                'side_a_points' => $game['a'],
                'side_b_points' => $game['b'],
            ]);
        }
    }
}
