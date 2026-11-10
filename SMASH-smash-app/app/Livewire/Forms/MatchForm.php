<?php

namespace App\Livewire\Forms;

use App\Actions\Matches\SaveMatch;
use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\MatchGame;
use App\Models\MatchPlayer;
use App\Models\User;
use App\Services\Rating\RatingEngine;
use App\Support\Badminton\Scoring;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Form;
use Throwable;

/**
 * The match form shared by pages::matches.create and pages::matches.edit: its state, the rules
 * (members log their own friendlies as Completed or Scheduled; admins may enter anything), the
 * live checks shown while typing (scores, winner, rating stakes, a preview card) and saving
 * through App\Actions\Matches\SaveMatch, whose errors are mapped back onto the form fields.
 *
 * Select and radio values arrive from the browser as strings, so the scalar fields are strings
 * and are converted when the data is handed to SaveMatch.
 */
class MatchForm extends Form
{
    /**
     * datetime-local input format.
     */
    public const DATE_FORMAT = 'Y-m-d\TH:i';

    /**
     * SaveMatch error keys => form fields.
     */
    private const ERROR_FIELDS = [
        'discipline' => 'discipline',
        'side_a' => 'sideA',
        'side_b' => 'sideB',
        'status' => 'status',
        'scheduled_at' => 'scheduledAt',
        'played_at' => 'playedAt',
        'court' => 'court',
        'venue' => 'venue',
        'notes' => 'notes',
        'best_of' => 'bestOf',
        'points_per_game' => 'pointsPerGame',
        'games' => 'games',
        'walkover_winner' => 'walkoverWinner',
    ];

    public string $discipline = 'singles';

    /**
     * Player ids for side A, one slot per player (empty string = not chosen yet).
     *
     * @var array<int, int|string|null>
     */
    public array $sideA = [''];

    /**
     * @var array<int, int|string|null>
     */
    public array $sideB = [''];

    public string $status = 'completed';

    public string $playedAt = '';

    public string $scheduledAt = '';

    public string $court = '';

    public string $venue = '';

    public string $bestOf = '3';

    public string $pointsPerGame = '21';

    public bool $isRated = true;

    public string $notes = '';

    /**
     * Game rows as typed: ['a' => '21', 'b' => '17'] (side A's points first).
     *
     * @var array<int, array<string, int|string|null>>
     */
    public array $games = [];

    public string $walkoverWinner = '';

    // ------------------------------------------------------------------
    // Filling the form
    // ------------------------------------------------------------------

    /**
     * Defaults for a new match: a completed singles result played just now at the club's venue,
     * best of three to 21. Members are always on side A.
     */
    public function start(User $actor): void
    {
        $this->discipline = Discipline::Singles->value;
        $this->sideA = [$actor->isAdmin() ? '' : (string) $actor->id];
        $this->sideB = [''];
        $this->status = MatchStatus::Completed->value;
        $this->playedAt = self::now()->format(self::DATE_FORMAT);
        $this->scheduledAt = '';
        $this->court = '';
        $this->venue = (string) config('smash.club.venue', '');
        $this->bestOf = '3';
        $this->pointsPerGame = '21';
        $this->isRated = true;
        $this->notes = '';
        $this->walkoverWinner = '';
        $this->games = [];
        $this->fitGameRows();
    }

    /**
     * Everything as stored on an existing match.
     */
    public function fillFrom(BadmintonMatch $match): void
    {
        $match->loadMissing(['participants', 'games']);

        $this->discipline = $match->discipline->value;
        $this->status = $match->status->value;
        $this->playedAt = $match->played_at?->format(self::DATE_FORMAT) ?? '';
        $this->scheduledAt = $match->scheduled_at?->format(self::DATE_FORMAT) ?? '';
        $this->court = $match->court === null ? '' : (string) $match->court;
        $this->venue = $match->venue ?? '';
        $this->bestOf = (string) $match->best_of;
        $this->pointsPerGame = (string) $match->points_per_game;
        $this->isRated = $match->is_rated;
        $this->notes = $match->notes ?? '';
        $this->walkoverWinner = $match->status === MatchStatus::Walkover ? ($match->winning_side->value ?? '') : '';

        foreach (Side::cases() as $side) {
            $ids = $match->participants
                ->filter(fn (MatchPlayer $participant): bool => $participant->side === $side)
                ->sortBy('id')
                ->map(fn (MatchPlayer $participant): string => (string) $participant->user_id)
                ->values()
                ->all();

            $this->{$side === Side::A ? 'sideA' : 'sideB'} = $ids;
        }

        $this->fitPlayerSlots();

        $this->games = $match->games
            ->map(fn (MatchGame $game): array => ['a' => (string) $game->side_a_points, 'b' => (string) $game->side_b_points])
            ->values()
            ->all();

        $this->fitGameRows();
    }

    // ------------------------------------------------------------------
    // Livewire update hooks (form objects get their own)
    // ------------------------------------------------------------------

    public function updatedDiscipline(): void
    {
        $this->fitPlayerSlots();
    }

    public function updatedBestOf(): void
    {
        $this->fitGameRows();
    }

    public function updatedStatus(): void
    {
        $status = $this->statusEnum();

        if (in_array($status, [MatchStatus::Completed, MatchStatus::Walkover], true) && $this->playedAt === '') {
            $this->playedAt = self::now()->format(self::DATE_FORMAT);
        }

        if ($this->takesScores()) {
            $this->fitGameRows();
        }
    }

    /**
     * Level after every row is filled in (1–1 in a best of 3)? Add the row for the decider.
     */
    public function updatedGames(): void
    {
        if ($this->statusEnum() !== MatchStatus::Completed) {
            return;
        }

        $scores = $this->scores();
        $target = $this->pointsNumber();
        $complete = array_filter($scores, fn (array $game): bool => Scoring::isCompleteGame($game['a'], $game['b'], $target));

        if (count($scores) === count($this->games)
            && count($complete) === count($scores)
            && Scoring::matchWinner($scores, $this->bestOfNumber(), $target) === null) {
            $this->addGame();
        }
    }

    public function addGame(): void
    {
        if (count($this->games) < $this->bestOfNumber()) {
            $this->games[] = ['a' => '', 'b' => ''];
        }
    }

    public function removeGame(int $index): void
    {
        if (! array_key_exists($index, $this->games) || count($this->games) <= 1) {
            return;
        }

        unset($this->games[$index]);
        $this->games = array_values($this->games);
    }

    /**
     * One select per player: 1 for singles, 2 for doubles (keeps what was already chosen).
     */
    public function fitPlayerSlots(): void
    {
        $perSide = $this->disciplineEnum()->playersPerSide();

        foreach (['sideA', 'sideB'] as $property) {
            $slots = array_slice($this->{$property}, 0, $perSide);

            while (count($slots) < $perSide) {
                $slots[] = '';
            }

            $this->{$property} = $slots;
        }
    }

    /**
     * Enough rows to win (2 for a best of 3), never more than the match can have.
     */
    public function fitGameRows(): void
    {
        $bestOf = $this->bestOfNumber();
        $rows = array_values($this->games);

        // Drop empty rows beyond what is needed, and anything past the maximum.
        while (count($rows) > Scoring::gamesToWin($bestOf) && self::blankRow(end($rows))) {
            array_pop($rows);
        }

        $rows = array_slice($rows, 0, $bestOf);

        while (count($rows) < Scoring::gamesToWin($bestOf)) {
            $rows[] = ['a' => '', 'b' => ''];
        }

        $this->games = $rows;
    }

    // ------------------------------------------------------------------
    // Reading the form
    // ------------------------------------------------------------------

    public function disciplineEnum(): Discipline
    {
        return Discipline::tryFrom($this->discipline) ?? Discipline::Singles;
    }

    public function statusEnum(): ?MatchStatus
    {
        return MatchStatus::tryFrom($this->status);
    }

    public function bestOfNumber(): int
    {
        $bestOf = (int) $this->bestOf;

        return in_array($bestOf, SaveMatch::BEST_OF, true) ? $bestOf : 3;
    }

    public function pointsNumber(): int
    {
        $points = (int) $this->pointsPerGame;

        return in_array($points, SaveMatch::POINTS_PER_GAME, true) ? $points : 21;
    }

    /**
     * Completed and live matches have game scores; the others have none.
     */
    public function takesScores(): bool
    {
        return in_array($this->statusEnum(), [MatchStatus::Completed, MatchStatus::InProgress], true);
    }

    /**
     * Chosen player ids on a side, in slot order (blank slots and repeats skipped).
     *
     * @return list<int>
     */
    public function playerIds(Side $side): array
    {
        $ids = [];

        foreach ($side === Side::A ? $this->sideA : $this->sideB as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);

            if (is_int($id) && $id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Ids chosen anywhere on the form (with repeats, to spot duplicates).
     *
     * @return list<int>
     */
    public function chosenIds(): array
    {
        $ids = [];

        foreach ([...$this->sideA, ...$this->sideB] as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);

            if (is_int($id) && $id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Both sides have the right number of different players.
     */
    public function sidesComplete(): bool
    {
        $perSide = $this->disciplineEnum()->playersPerSide();
        $sideA = $this->playerIds(Side::A);
        $sideB = $this->playerIds(Side::B);

        return count($sideA) === $perSide && count($sideB) === $perSide && array_intersect($sideA, $sideB) === [];
    }

    /**
     * The games typed so far, up to the first row that is not filled in (side A first).
     *
     * @return list<array{a: int, b: int}>
     */
    public function scores(): array
    {
        $scores = [];

        foreach ($this->games as $row) {
            $a = self::points($row['a'] ?? null);
            $b = self::points($row['b'] ?? null);

            if ($a === null || $b === null) {
                break;
            }

            $scores[] = ['a' => $a, 'b' => $b];
        }

        return $scores;
    }

    /**
     * What the score check says while typing: problems (wrong scores, games after the match was
     * decided, gaps), a hint while the match is still undecided, and the winner once it is.
     *
     * @return array{problems: list<string>, hint: string|null, winner: Side|null, scores: list<array{a: int, b: int}>}
     */
    public function scoreCheck(): array
    {
        $result = ['problems' => [], 'hint' => null, 'winner' => null, 'scores' => []];

        if (! $this->takesScores()) {
            return $result;
        }

        $live = $this->statusEnum() === MatchStatus::InProgress;
        $bestOf = $this->bestOfNumber();
        $target = $this->pointsNumber();
        $scores = $this->scores();
        $result['scores'] = $scores;

        // Rows after the first gap, and scores that are not whole numbers.
        foreach (array_values($this->games) as $index => $row) {
            $a = $row['a'] ?? null;
            $b = $row['b'] ?? null;
            $number = $index + 1;

            foreach ([$a, $b] as $value) {
                if (! self::blank($value) && self::points($value) === null) {
                    $result['problems'][] = sprintf('Scores for game %d must be whole numbers of 0 or more.', $number);

                    continue 2;
                }
            }

            if ($index > count($scores) && ! self::blankRow($row)) {
                $result['problems'][] = sprintf('Fill in game %d before game %d.', count($scores) + 1, $number);

                break;
            }
        }

        if ($scores === []) {
            return $result;
        }

        $winner = Scoring::matchWinner($scores, $bestOf, $target);

        // A result must be decided by complete games; a live match may have a game in play.
        foreach (Scoring::validate($scores, $bestOf, $target, ! $live) as $message) {
            // "Not decided yet" is not a mistake while typing: it becomes the hint below.
            if (! $live && $winner === null && str_starts_with($message, 'The match is not decided yet')) {
                continue;
            }

            $result['problems'][] = $message;
        }

        if ($result['problems'] !== []) {
            return $result;
        }

        if ($winner !== null) {
            $result['winner'] = $live ? null : $winner;

            return $result;
        }

        $tally = Scoring::tally($scores, $target);
        $needed = Scoring::gamesToWin($bestOf);
        $last = $scores[array_key_last($scores)];

        $result['hint'] = match (true) {
            $live && ! Scoring::isCompleteGame($last['a'], $last['b'], $target) => sprintf(
                'Game %d is in play at %s. Games so far: %d–%d.', count($scores), Scoring::format($last['a'], $last['b']), $tally['a'], $tally['b'],
            ),
            $live => sprintf('Games so far: %d–%d. Add the next game when it starts.', $tally['a'], $tally['b']),
            $tally['a'] === $tally['b'] => sprintf('Level at %d–%d in games: add the deciding game.', $tally['a'], $tally['b']),
            default => sprintf('%d–%d in games so far: a side needs %d %s to win a best of %d.', $tally['a'], $tally['b'], $needed, $needed === 1 ? 'game' : 'games', $bestOf),
        };

        return $result;
    }

    /**
     * What each player stands to gain or lose (current ratings), or null when it does not apply.
     *
     * @return array{a_wins: array<int, float>, b_wins: array<int, float>}|null
     */
    public function stakes(bool $tournament = false): ?array
    {
        if (! $this->isRated || ! $this->sidesComplete()) {
            return null;
        }

        if (! in_array($this->statusEnum(), [MatchStatus::Completed, MatchStatus::Scheduled, MatchStatus::InProgress], true)) {
            return null;
        }

        return RatingEngine::stakes($this->playerIds(Side::A), $this->playerIds(Side::B), $this->disciplineEnum(), $tournament);
    }

    /**
     * An unsaved match built from the form (for the x-smash.match-card preview). With $stakes and
     * a decided result, the participants carry the projected rating change.
     *
     * @param  \Illuminate\Support\Collection<int, User>  $people  players keyed by id
     * @param  array{a_wins: array<int, float>, b_wins: array<int, float>}|null  $stakes
     */
    public function preview(\Illuminate\Support\Collection $people, ?BadmintonMatch $existing = null, ?array $stakes = null): BadmintonMatch
    {
        $status = $this->statusEnum() ?? MatchStatus::Scheduled;
        $check = $this->scoreCheck();
        $winner = match ($status) {
            MatchStatus::Completed => $check['winner'],
            MatchStatus::Walkover => Side::tryFrom($this->walkoverWinner),
            default => null,
        };

        $match = new BadmintonMatch;
        $match->forceFill([
            'discipline' => $existing?->tournament_id !== null ? $existing->discipline : $this->disciplineEnum(),
            'status' => $status,
            'best_of' => $this->bestOfNumber(),
            'points_per_game' => $this->pointsNumber(),
            'is_rated' => $this->isRated,
            'court' => filter_var($this->court, FILTER_VALIDATE_INT) ?: null,
            'venue' => trim($this->venue) === '' ? null : trim($this->venue),
            'scheduled_at' => self::parseDate($this->scheduledAt),
            'played_at' => $status->isFinished() ? (self::parseDate($this->playedAt) ?? self::now()) : null,
            'winning_side' => $winner,
        ]);

        if ($existing !== null && $existing->tournament_id !== null) {
            $match->forceFill([
                'tournament_id' => $existing->tournament_id,
                'round' => $existing->round,
                'bracket_position' => $existing->bracket_position,
                'entry_a_id' => $existing->entry_a_id,
                'entry_b_id' => $existing->entry_b_id,
            ]);

            foreach (['tournament', 'entryA', 'entryB'] as $relation) {
                if ($existing->relationLoaded($relation)) {
                    $match->setRelation($relation, $existing->getRelation($relation));
                }
            }
        }

        $participants = new Collection;
        $projected = $status === MatchStatus::Completed && $this->isRated && $winner !== null && $stakes !== null;

        foreach (Side::cases() as $side) {
            foreach ($this->playerIds($side) as $id) {
                $user = $people->get($id);

                if (! $user instanceof User) {
                    continue;
                }

                $participant = new MatchPlayer;
                $participant->forceFill(['user_id' => $user->id, 'side' => $side]);

                if ($projected) {
                    $before = $user->ratingExact($match->discipline);
                    $change = $stakes[$winner === Side::A ? 'a_wins' : 'b_wins'][$user->id] ?? 0.0;
                    $participant->forceFill(['rating_before' => $before, 'rating_after' => $before + $change]);
                }

                $participant->setRelation('user', $user);
                $participants->push($participant);
            }
        }

        $games = new Collection;

        if ($this->takesScores()) {
            foreach ($check['scores'] as $index => $score) {
                $games->push((new MatchGame)->forceFill([
                    'number' => $index + 1,
                    'side_a_points' => $score['a'],
                    'side_b_points' => $score['b'],
                ]));
            }
        }

        $match->setRelation('participants', $participants);
        $match->setRelation('games', $games);

        return $match;
    }

    /**
     * Status choices for this actor: members log results and fixtures; admins can also mark a
     * match live, award a walkover or cancel it. Tournament matches whose players are not known
     * yet can only be (re)scheduled or cancelled.
     *
     * @return array<string, string>
     */
    public static function statusOptions(User $actor, ?BadmintonMatch $match = null): array
    {
        $labels = [
            MatchStatus::Completed->value => 'Completed',
            MatchStatus::Scheduled->value => 'Scheduled',
            MatchStatus::InProgress->value => 'In progress',
            MatchStatus::Walkover->value => 'Walkover',
            MatchStatus::Cancelled->value => 'Cancelled',
        ];

        if ($match !== null && $match->tournament_id !== null && ($match->entry_a_id === null || $match->entry_b_id === null)) {
            return array_intersect_key($labels, array_flip([MatchStatus::Scheduled->value, MatchStatus::Cancelled->value]));
        }

        if (! $actor->isAdmin()) {
            return array_intersect_key($labels, array_flip([MatchStatus::Completed->value, MatchStatus::Scheduled->value]));
        }

        return $labels;
    }

    /**
     * Players who can be picked: active members, plus whoever already plays in the match.
     *
     * @return Collection<int, User>
     */
    public static function eligiblePlayers(?BadmintonMatch $match = null): Collection
    {
        $current = $match?->exists
            ? $match->participants()->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->all()
            : [];

        return User::query()
            ->where(fn ($query) => $query->where('is_active', true)->when($current !== [], fn ($query) => $query->orWhereKey($current)))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    // ------------------------------------------------------------------
    // Validating and saving
    // ------------------------------------------------------------------

    /**
     * Validate the form for $actor and save it through SaveMatch (which validates the result
     * again, recalculates ratings and moves tournament winners on).
     *
     * @throws ValidationException keyed by form field ("form.games", "form.sideA.0", …)
     */
    public function save(User $actor, ?BadmintonMatch $match = null): BadmintonMatch
    {
        $data = $this->data($actor, $match);

        try {
            return SaveMatch::handle($data, $match, $actor);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($this->formErrors($exception->errors()));
        }
    }

    /**
     * The validated form as SaveMatch data.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function data(User $actor, ?BadmintonMatch $match = null): array
    {
        $this->validate(...$this->rulesFor($actor, $match));

        $status = $this->statusEnum() ?? MatchStatus::Scheduled;
        $friendly = $match === null || $match->tournament_id === null;

        $data = [
            'status' => $status,
            'best_of' => $this->bestOfNumber(),
            'points_per_game' => $this->pointsNumber(),
            'is_rated' => $this->isRated,
            'court' => $this->court === '' ? null : (int) $this->court,
            'venue' => trim($this->venue) === '' ? null : trim($this->venue),
            'notes' => trim($this->notes) === '' ? null : trim($this->notes),
            'scheduled_at' => $this->scheduledAt === '' ? null : $this->scheduledAt,
            'played_at' => $status->isFinished() && $this->playedAt !== '' ? $this->playedAt : null,
            'games' => $this->takesScores() ? array_values($this->games) : [],
            'walkover_winner' => $status === MatchStatus::Walkover ? $this->walkoverWinner : null,
        ];

        if ($friendly) {
            $data['discipline'] = $this->disciplineEnum();
            $data['side_a'] = $this->playerIds(Side::A);
            $data['side_b'] = $this->playerIds(Side::B);
        }

        return $data;
    }

    /**
     * Rules, messages and attribute names for $actor (and the match being edited).
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: array<string, string>}
     */
    public function rulesFor(User $actor, ?BadmintonMatch $match = null): array
    {
        $status = $this->statusEnum();
        $creating = $match === null;
        $friendly = $match === null || $match->tournament_id === null;
        $latest = self::now()->addMinutes(10)->format('Y-m-d H:i:s');

        $rules = [
            'status' => ['required', Rule::in(array_keys(self::statusOptions($actor, $match)))],
            'bestOf' => ['required', Rule::in(SaveMatch::BEST_OF)],
            'pointsPerGame' => ['required', Rule::in(SaveMatch::POINTS_PER_GAME)],
            'isRated' => ['boolean'],
            'court' => ['nullable', 'integer', 'between:1,99'],
            'venue' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'playedAt' => $status === MatchStatus::Completed
                ? ['required', 'date', 'before_or_equal:'.$latest]
                : ['nullable', 'date', 'before_or_equal:'.$latest],
            'scheduledAt' => $creating && $status === MatchStatus::Scheduled
                ? ['required', 'date', 'after:now']
                : ['nullable', 'date'],
            'games' => ['array', 'max:'.$this->bestOfNumber()],
            'games.*.a' => ['nullable', 'integer', 'min:0', 'max:99'],
            'games.*.b' => ['nullable', 'integer', 'min:0', 'max:99'],
            'walkoverWinner' => $status === MatchStatus::Walkover
                ? ['required', Rule::in([Side::A->value, Side::B->value])]
                : ['nullable'],
        ];

        if ($friendly) {
            $perSide = $this->disciplineEnum()->playersPerSide();
            $eligible = self::eligiblePlayers($match)->modelKeys();
            $chosen = $this->chosenIds();

            $once = function (string $attribute, mixed $value, Closure $fail) use ($chosen): void {
                $id = filter_var($value, FILTER_VALIDATE_INT);

                if (is_int($id) && count(array_keys($chosen, $id, true)) > 1) {
                    $fail('This player is already picked. Each player can only appear once.');
                }
            };

            $rules['discipline'] = ['required', Rule::enum(Discipline::class)];
            $rules['sideA'] = ['array', 'size:'.$perSide];
            $rules['sideB'] = ['array', 'size:'.$perSide];

            for ($slot = 0; $slot < $perSide; $slot++) {
                $rules['sideA.'.$slot] = ['required', 'integer', Rule::in($eligible), $once];
                $rules['sideB.'.$slot] = ['required', 'integer', Rule::in($eligible), $once];
            }

            if (! $actor->isAdmin()) {
                $rules['sideA.0'][] = function (string $attribute, mixed $value, Closure $fail) use ($actor): void {
                    if (filter_var($value, FILTER_VALIDATE_INT) !== $actor->id) {
                        $fail('You can only log matches you played in, so you stay on side A.');
                    }
                };
            }
        }

        $messages = [
            'sideA.*.required' => 'Choose a player.',
            'sideB.*.required' => 'Choose a player.',
            'sideA.*.in' => 'Choose an active SMASH member.',
            'sideB.*.in' => 'Choose an active SMASH member.',
            'sideA.*.integer' => 'Choose a player from the list.',
            'sideB.*.integer' => 'Choose a player from the list.',
            'sideA.size' => 'Pick the right number of players for side A.',
            'sideB.size' => 'Pick the right number of players for side B.',
            'status.in' => 'Choose one of the statuses shown.',
            'playedAt.required' => 'When did you play? Enter the date and time.',
            'playedAt.before_or_equal' => 'That is in the future. Log a result once the match has been played.',
            'scheduledAt.required' => 'When will you play? Enter the date and time.',
            'scheduledAt.after' => 'A fixture needs a date in the future. Played already? Log it as completed.',
            'games.max' => 'A best of :max has at most :max games.',
            'games.*.*.integer' => 'Scores are whole numbers.',
            'games.*.*.min' => 'Scores cannot be negative.',
            'games.*.*.max' => 'That score is too high for a game of badminton.',
            'walkoverWinner.required' => 'Choose who is awarded the walkover.',
            'court.between' => 'Courts are numbered 1 to 99.',
        ];

        $attributes = [
            'discipline' => 'discipline',
            'playedAt' => 'date played',
            'scheduledAt' => 'start time',
            'bestOf' => 'number of games',
            'pointsPerGame' => 'points per game',
            'isRated' => 'rated',
            'walkoverWinner' => 'walkover winner',
        ];

        return [$rules, $messages, $attributes];
    }

    /**
     * SaveMatch errors keyed like this form ("games" => "form.games").
     *
     * @param  array<string, array<int, string>>  $errors
     * @return array<string, list<string>>
     */
    private function formErrors(array $errors): array
    {
        $mapped = [];

        foreach ($errors as $key => $messages) {
            $field = $this->getPropertyName().'.'.(self::ERROR_FIELDS[$key] ?? 'status');
            $mapped[$field] = [...($mapped[$field] ?? []), ...$messages];
        }

        return $mapped;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Now, to the minute (datetime-local has no seconds).
     */
    private static function now(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfMinute();
    }

    private static function parseDate(string $value): ?CarbonImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A typed score as an int (null when blank or not a whole number of 0 or more).
     */
    private static function points(mixed $value): ?int
    {
        if (self::blank($value)) {
            return null;
        }

        $points = filter_var(is_string($value) ? trim($value) : $value, FILTER_VALIDATE_INT);

        return is_int($points) && $points >= 0 ? $points : null;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * @param  array<string, int|string|null>|false  $row
     */
    private static function blankRow(array|false $row): bool
    {
        return $row === false || (self::blank($row['a'] ?? null) && self::blank($row['b'] ?? null));
    }
}
