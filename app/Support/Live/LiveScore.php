<?php

namespace App\Support\Live;

use App\Enums\Side;
use App\Support\Badminton\Scoring;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * The umpire's scoresheet for a match in play (BWF rally scoring).
 *
 * The state is a log: the games already on the scoresheet when the log started (the "base", whose
 * last game is the one in play), who was serving at that moment, and the winner of every rally
 * since, in order. Everything else — the score, games won, who serves from which service court,
 * intervals, change of ends, game and match points — is replayed from that log with the rules in
 * App\Support\Badminton\Scoring, so undo is simply "forget the last rally".
 *
 * Rules applied: every rally scores and its winner serves the next one (the winner of a game serves
 * first in the next game); the server serves from the right service court with an even score and
 * from the left with an odd score; a game is won per Scoring::isCompleteGame (two clear points past
 * 20–20, golden point at 29–29); the match ends per Scoring::matchWinner. When the leading score
 * first reaches 11 (half the target, rounded up) there is an interval, and in the deciding game
 * the players also change ends then.
 *
 * Immutable: every change returns a new instance. toArray()/fromArray() store it in the cache.
 */
final class LiveScore
{
    /** The last rally won a game and the match goes on (players change ends). */
    public const GAME = 'game';

    /** The last rally took the leading score to 11: interval. */
    public const INTERVAL = 'interval';

    /** As INTERVAL, in the deciding game: the players also change ends. */
    public const CHANGE_ENDS = 'change_ends';

    /** The last rally decided the match. */
    public const MATCH = 'match';

    private const SCHEMA = 1;

    /**
     * @var array{games: list<array{a: int, b: int}>, server: ?Side, event: ?string, history: list<array{game: int, a: int, b: int, winner: Side, event: ?string}>}|null
     */
    private ?array $replayed = null;

    /**
     * @param  list<array{a: int, b: int}>  $base  scoresheet when the log started; the last game is in play (or decides the match)
     * @param  list<Side>  $rallies  rally winners since then
     */
    private function __construct(
        public readonly int $bestOf,
        public readonly int $target,
        public readonly array $base,
        public readonly ?Side $server,
        public readonly bool $serverChosen,
        public readonly array $rallies,
    ) {}

    // ------------------------------------------------------------------
    // Creating
    // ------------------------------------------------------------------

    /**
     * A match about to start: game 1 at 0–0, nobody chosen to serve yet.
     */
    public static function start(int $bestOf = 3, int $target = 21): self
    {
        return self::resume([], $bestOf, $target);
    }

    /**
     * Pick up a match from the games already recorded (the last one may be in progress). The
     * server is known only at the start of a later game (the winner of the previous game serves
     * first); otherwise the umpire is asked.
     *
     * @param  list<array{a: int, b: int}>  $games
     *
     * @throws InvalidArgumentException when the games cannot be continued
     */
    public static function resume(array $games, int $bestOf = 3, int $target = 21): self
    {
        self::guardFormat($bestOf, $target);

        $base = self::open(self::rows($games), $bestOf, $target);
        $server = null;
        $count = count($base);

        if ($count > 1 && $base[$count - 1] === ['a' => 0, 'b' => 0] && Scoring::matchWinner($base, $bestOf, $target) === null) {
            $previous = $base[$count - 2];
            $server = Scoring::gameWinner($previous['a'], $previous['b']);
        }

        return new self($bestOf, $target, $base, $server, false, []);
    }

    /**
     * Restore a state stored with toArray(). Anything malformed or impossible gives null, so a
     * corrupted cache entry is simply ignored.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ($data['v'] ?? null) !== self::SCHEMA) {
            return null;
        }

        $bestOf = $data['best_of'] ?? null;
        $target = $data['target'] ?? null;
        $base = $data['base'] ?? null;
        $server = $data['server'] ?? null;
        $chosen = $data['chosen'] ?? null;
        $rallies = $data['rallies'] ?? null;

        if (! is_int($bestOf) || ! is_int($target) || ! is_array($base) || ! is_bool($chosen) || ! is_string($rallies)
            || ($server !== null && ! is_string($server)) || preg_match('/^[ab]*$/', $rallies) !== 1) {
            return null;
        }

        try {
            self::guardFormat($bestOf, $target);
            $rows = self::rows(array_values($base));

            // The stored base is already opened; re-opening must not change it.
            if (self::open($rows, $bestOf, $target) !== $rows) {
                return null;
            }

            $serverSide = $server === null ? null : Side::tryFrom($server);

            if ($server !== null && $serverSide === null) {
                return null;
            }

            $state = new self(
                $bestOf,
                $target,
                $rows,
                $serverSide,
                $chosen && $serverSide !== null,
                array_map(fn (string $side): Side => Side::from($side), $rallies === '' ? [] : str_split($rallies)),
            );

            $state->replay();
        } catch (Throwable) {
            return null;
        }

        return $state;
    }

    /**
     * @return array{v: int, best_of: int, target: int, base: list<array{a: int, b: int}>, server: ?string, chosen: bool, rallies: string}
     */
    public function toArray(): array
    {
        return [
            'v' => self::SCHEMA,
            'best_of' => $this->bestOf,
            'target' => $this->target,
            'base' => $this->base,
            'server' => $this->server?->value,
            'chosen' => $this->serverChosen,
            'rallies' => implode('', array_map(fn (Side $side): string => $side->value, $this->rallies)),
        ];
    }

    // ------------------------------------------------------------------
    // Changes
    // ------------------------------------------------------------------

    /**
     * The umpire says who serves (only before the first rally of this log; after that the rally
     * winner always serves).
     */
    public function withServer(Side $side): self
    {
        if ($this->rallies !== []) {
            throw new LogicException('The server follows the rallies once play is under way.');
        }

        if ($this->isDecided()) {
            throw new LogicException('The match is already decided.');
        }

        return new self($this->bestOf, $this->target, $this->base, $side, true, []);
    }

    /**
     * $side won the rally.
     */
    public function point(Side $side): self
    {
        if ($this->isDecided()) {
            throw new LogicException('The match is already decided.');
        }

        if ($this->serving() === null) {
            throw new LogicException('Choose who is serving first.');
        }

        return new self($this->bestOf, $this->target, $this->base, $this->server, $this->serverChosen, [...$this->rallies, $side]);
    }

    /**
     * Forget the last rally (or, before the first rally, the server choice).
     */
    public function undo(): self
    {
        if ($this->rallies !== []) {
            return new self($this->bestOf, $this->target, $this->base, $this->server, $this->serverChosen, array_slice($this->rallies, 0, -1));
        }

        if ($this->serverChosen) {
            return new self($this->bestOf, $this->target, $this->base, null, false, []);
        }

        throw new LogicException('There is nothing to undo.');
    }

    public function canUndo(): bool
    {
        return $this->rallies !== [] || $this->serverChosen;
    }

    // ------------------------------------------------------------------
    // Score
    // ------------------------------------------------------------------

    /**
     * The whole scoresheet, side A first: complete games, then the game in play (a fresh 0–0
     * game right after a game ends) — or, once decided, ending with the deciding game.
     *
     * @return list<array{a: int, b: int}>
     */
    public function games(): array
    {
        return $this->replay()['games'];
    }

    /**
     * Games before the one in play (all games once the match is decided).
     *
     * @return list<array{a: int, b: int}>
     */
    public function completedGames(): array
    {
        $games = $this->games();

        return $this->isDecided() ? $games : array_slice($games, 0, -1);
    }

    /**
     * Score of the game in play (or of the deciding game once decided).
     *
     * @return array{a: int, b: int}
     */
    public function current(): array
    {
        $games = $this->games();

        return $games[count($games) - 1];
    }

    public function pointsFor(Side $side): int
    {
        return $this->current()[$side->value];
    }

    /**
     * Number of the game in play, 1-based.
     */
    public function gameNumber(): int
    {
        return count($this->games());
    }

    /**
     * Complete games won by each side.
     *
     * @return array{a: int, b: int}
     */
    public function gamesWon(): array
    {
        return Scoring::tally($this->games(), $this->target);
    }

    public function gamesToWin(): int
    {
        return Scoring::gamesToWin($this->bestOf);
    }

    public function winner(): ?Side
    {
        return Scoring::matchWinner($this->games(), $this->bestOf, $this->target);
    }

    public function isDecided(): bool
    {
        return $this->winner() !== null;
    }

    /**
     * The game in play is the last one possible (1–1 in a best of three, every best-of-one game).
     */
    public function isDecidingGame(): bool
    {
        return ! $this->isDecided() && $this->decidingFor($this->games());
    }

    /**
     * The leading score at which the interval falls: 11 in games to 21 (8 to 15, 6 to 11).
     */
    public function intervalAt(): int
    {
        return intdiv($this->target + 1, 2);
    }

    /**
     * Past 20–20 (a two-point lead is needed, up to the cap).
     */
    public function isExtended(): bool
    {
        $current = $this->current();

        return ! $this->isDecided() && min($current['a'], $current['b']) >= $this->target - 1;
    }

    /**
     * 29–29 in a game to 21: the next rally wins the game.
     */
    public function isGoldenPoint(): bool
    {
        $current = $this->current();
        $cap = Scoring::cap($this->target);

        return ! $this->isDecided() && $current['a'] === $cap - 1 && $current['b'] === $cap - 1;
    }

    /**
     * Winning the next rally wins $side the game.
     */
    public function hasGamePoint(Side $side): bool
    {
        if ($this->isDecided()) {
            return false;
        }

        $current = $this->current();
        $current[$side->value]++;

        return Scoring::isCompleteGame($current['a'], $current['b'], $this->target);
    }

    /**
     * Winning the next rally wins $side the match.
     */
    public function hasMatchPoint(Side $side): bool
    {
        return $this->hasGamePoint($side) && $this->gamesWon()[$side->value] + 1 === $this->gamesToWin();
    }

    // ------------------------------------------------------------------
    // Service
    // ------------------------------------------------------------------

    /**
     * Who serves the next rally: the winner of the last rally, or the side the umpire chose.
     * Null when nobody has been chosen yet, and once the match is decided.
     */
    public function serving(): ?Side
    {
        return $this->isDecided() ? null : $this->replay()['server'];
    }

    public function needsServer(): bool
    {
        return ! $this->isDecided() && $this->serving() === null;
    }

    /**
     * Service court of the server: right with an even score, left with an odd one.
     *
     * @return 'right'|'left'|null
     */
    public function serviceCourt(): ?string
    {
        $server = $this->serving();

        if ($server === null) {
            return null;
        }

        return $this->pointsFor($server) % 2 === 0 ? 'right' : 'left';
    }

    // ------------------------------------------------------------------
    // The log
    // ------------------------------------------------------------------

    public function lastRally(): ?Side
    {
        return $this->rallies === [] ? null : $this->rallies[count($this->rallies) - 1];
    }

    /**
     * What the last rally brought: GAME, INTERVAL, CHANGE_ENDS, MATCH or null.
     */
    public function event(): ?string
    {
        return $this->replay()['event'];
    }

    /**
     * Every rally of this log: the game it was played in, the score after it, its winner and
     * what it brought (see event()).
     *
     * @return list<array{game: int, a: int, b: int, winner: Side, event: ?string}>
     */
    public function history(): array
    {
        return $this->replay()['history'];
    }

    /**
     * The scoresheet as it belongs in the database while the match is live: games() — except
     * that the rally that decides the match is only kept here until the result is confirmed.
     *
     * @return list<array{a: int, b: int}>
     */
    public function recorded(): array
    {
        if ($this->isDecided() && $this->rallies !== []) {
            return $this->undo()->games();
        }

        return $this->games();
    }

    /**
     * Whether $games (as stored, e.g. Scoring::fromModels()) is the scoresheet this state
     * recorded. A trailing 0–0 game counts as nothing.
     *
     * @param  list<array{a: int, b: int}>  $games
     */
    public function isRecordedAs(array $games): bool
    {
        return self::trim($this->recorded()) === self::trim($games);
    }

    /**
     * Fingerprint of the scoresheet, so a console can tell when another device scored a rally
     * in between.
     */
    public function version(): string
    {
        return substr(sha1(json_encode([
            $this->bestOf,
            $this->target,
            $this->base,
            $this->server?->value,
            $this->serverChosen,
            array_map(fn (Side $side): string => $side->value, $this->rallies),
        ], JSON_THROW_ON_ERROR)), 0, 16);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @return array{games: list<array{a: int, b: int}>, server: ?Side, event: ?string, history: list<array{game: int, a: int, b: int, winner: Side, event: ?string}>}
     */
    private function replay(): array
    {
        if ($this->replayed !== null) {
            return $this->replayed;
        }

        $games = $this->base;
        $server = $this->server;
        $event = null;
        $history = [];
        $interval = $this->intervalAt();

        foreach ($this->rallies as $side) {
            if (Scoring::matchWinner($games, $this->bestOf, $this->target) !== null) {
                throw new LogicException('No rally can be played once the match is decided.');
            }

            $index = count($games) - 1;
            $deciding = $this->decidingFor($games);
            $game = $games[$index];
            $game[$side->value]++;
            $games[$index] = $game;

            $server = $side;
            $own = $game[$side->value];
            $opponent = $game[$side->opposite()->value];

            $event = match (true) {
                Scoring::isCompleteGame($game['a'], $game['b'], $this->target) => Scoring::matchWinner($games, $this->bestOf, $this->target) !== null
                    ? self::MATCH
                    : self::GAME,
                $own === $interval && $opponent < $interval => $deciding ? self::CHANGE_ENDS : self::INTERVAL,
                default => null,
            };

            $history[] = ['game' => $index + 1, 'a' => $game['a'], 'b' => $game['b'], 'winner' => $side, 'event' => $event];

            if ($event === self::GAME) {
                $games[] = ['a' => 0, 'b' => 0];
            }
        }

        return $this->replayed = ['games' => $games, 'server' => $server, 'event' => $event, 'history' => $history];
    }

    /**
     * Whether the last game of $games (the one in play) is the deciding game.
     *
     * @param  list<array{a: int, b: int}>  $games
     */
    private function decidingFor(array $games): bool
    {
        $won = Scoring::tally(array_slice($games, 0, -1), $this->target);
        $needed = Scoring::gamesToWin($this->bestOf) - 1;

        return $won['a'] === $needed && $won['b'] === $needed;
    }

    private static function guardFormat(int $bestOf, int $target): void
    {
        if ($bestOf < 1 || $bestOf % 2 === 0) {
            throw new InvalidArgumentException('A match is played over an odd number of games.');
        }

        if ($target < 1) {
            throw new InvalidArgumentException('Games are played to at least one point.');
        }
    }

    /**
     * Validated game rows (side A first). Accepts what Scoring::fromModels() returns.
     *
     * @param  array<mixed>  $games
     * @return list<array{a: int, b: int}>
     */
    private static function rows(array $games): array
    {
        $rows = [];

        foreach ($games as $game) {
            if (! is_array($game) || ! is_int($game['a'] ?? null) || ! is_int($game['b'] ?? null)) {
                throw new InvalidArgumentException('Every game needs a whole-number score for both sides.');
            }

            $rows[] = ['a' => $game['a'], 'b' => $game['b']];
        }

        return $rows;
    }

    /**
     * The games as a scoresheet ready for the next rally: valid so far, with a game in play
     * (a fresh 0–0 game when there is none yet or the last one is complete). A scoresheet that
     * already decides the match is returned as it is.
     *
     * @param  list<array{a: int, b: int}>  $games
     * @return list<array{a: int, b: int}>
     */
    private static function open(array $games, int $bestOf, int $target): array
    {
        if ($games !== [] && Scoring::validate($games, $bestOf, $target, true) === []) {
            return $games;
        }

        $problems = Scoring::validate($games, $bestOf, $target, false);

        if ($problems !== []) {
            throw new InvalidArgumentException($problems[0]);
        }

        $last = $games === [] ? null : $games[count($games) - 1];

        if ($last === null || Scoring::isCompleteGame($last['a'], $last['b'], $target)) {
            $games[] = ['a' => 0, 'b' => 0];
        }

        return $games;
    }

    /**
     * Drop a trailing 0–0 game (a game that has not started yet).
     *
     * @param  list<array{a: int, b: int}>  $games
     * @return list<array{a: int, b: int}>
     */
    private static function trim(array $games): array
    {
        $rows = array_map(fn (array $game): array => ['a' => (int) $game['a'], 'b' => (int) $game['b']], $games);

        if ($rows !== [] && $rows[count($rows) - 1] === ['a' => 0, 'b' => 0]) {
            array_pop($rows);
        }

        return $rows;
    }
}
