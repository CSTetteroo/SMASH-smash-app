<?php

use App\Actions\Matches\SaveMatch;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Enums\TournamentStatus;
use App\Models\BadmintonMatch;
use App\Models\Prediction;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournament\DrawGenerator;
use App\Services\Tournament\TournamentProgress;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Domain\Support\Play;

function progressMatch(Tournament $tournament, int $round, int $position): BadmintonMatch
{
    return $tournament->matches()->where('round', $round)->where('bracket_position', $position)->sole();
}

function progressResult(BadmintonMatch $match, Side $winner, array $extra = []): BadmintonMatch
{
    return SaveMatch::handle([
        'status' => MatchStatus::Completed,
        'games' => Play::games($winner === Side::A ? [[21, 12], [21, 12]] : [[12, 21], [12, 21]]),
        ...$extra,
    ], $match);
}

beforeEach(function () {
    $this->tournament = Tournament::factory()->open()->knockout()->withEntries(8)->create();
    DrawGenerator::generate($this->tournament);
});

test('the winner moves into the next round, odd positions on side A and even on side B', function () {
    $first = progressResult(progressMatch($this->tournament, 1, 1), Side::A);
    $second = progressResult(progressMatch($this->tournament, 1, 2), Side::B);
    $semi = progressMatch($this->tournament, 2, 1);

    expect($semi->entry_a_id)->toBe($first->entry_a_id)
        ->and($semi->entry_b_id)->toBe($second->entry_b_id)
        ->and($semi->participants()->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$first->entryA?->player_id, $second->entryB?->player_id])->sort()->values()->all());
});

test('a corrected result swaps the entry in the next match while it has not started', function () {
    $first = progressResult(progressMatch($this->tournament, 1, 1), Side::A);
    progressResult(progressMatch($this->tournament, 1, 2), Side::A);
    $semi = progressMatch($this->tournament, 2, 1);
    Prediction::factory()->for($semi, 'match')->for(User::factory())->create();

    progressResult($first, Side::B);

    $semi->refresh();

    expect($semi->entry_a_id)->toBe($first->entry_b_id)
        ->and($semi->predictions()->count())->toBe(0)
        ->and($semi->participants()->where('user_id', $first->entryB?->player_id)->value('side'))->toBe(Side::A)
        ->and($semi->participants()->where('user_id', $first->entryA?->player_id)->exists())->toBeFalse();
});

test('clearing a result takes the entry back out of the next round', function () {
    $first = progressResult(progressMatch($this->tournament, 1, 1), Side::A);
    progressResult(progressMatch($this->tournament, 1, 2), Side::A);

    SaveMatch::handle(['status' => 'scheduled'], $first);

    $semi = progressMatch($this->tournament, 2, 1);

    expect($semi->entry_a_id)->toBeNull()
        ->and($semi->entry_b_id)->not->toBeNull()
        ->and($semi->participants()->count())->toBe(0);
});

test('the winner cannot change once the next match has started', function (MatchStatus $nextStatus) {
    $first = progressResult(progressMatch($this->tournament, 1, 1), Side::A);
    progressResult(progressMatch($this->tournament, 1, 2), Side::A);
    $semi = progressMatch($this->tournament, 2, 1);

    $nextStatus === MatchStatus::InProgress
        ? SaveMatch::handle(['status' => 'in_progress', 'games' => Play::games([[5, 3]])], $semi)
        : progressResult($semi, Side::A);

    expect(TournamentProgress::canChangeWinner($first->refresh()))->toBeFalse()
        ->and(fn () => progressResult($first, Side::B))->toThrow(ValidationException::class)
        ->and(fn () => SaveMatch::handle(['status' => 'scheduled'], $first))->toThrow(ValidationException::class);

    try {
        progressResult($first, Side::B);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('games');
    }

    // Same winner, corrected score: fine.
    $fixed = SaveMatch::handle(['games' => Play::games([[21, 19], [21, 19]])], $first);

    expect($fixed->games->first()?->score())->toBe('21–19')
        ->and($fixed->winning_side)->toBe(Side::A);
})->with([MatchStatus::InProgress, MatchStatus::Completed]);

test('finals and round robins can always change their winner', function () {
    $final = progressMatch($this->tournament, 3, 1);
    $friendly = BadmintonMatch::factory()->create();

    expect(TournamentProgress::canChangeWinner($final))->toBeTrue()
        ->and(TournamentProgress::canChangeWinner($friendly))->toBeTrue()
        ->and(TournamentProgress::nextMatch($final))->toBeNull()
        ->and(TournamentProgress::nextMatch(progressMatch($this->tournament, 1, 4))?->is(progressMatch($this->tournament, 2, 2)))->toBeTrue();
});

test('the tournament completes with the final and reopens when the final is undone', function () {
    $this->tournament->matches()->orderBy('round')->orderBy('bracket_position')->get()
        ->each(function (BadmintonMatch $match): void {
            $match = $match->fresh();

            if ($match !== null && $match->entry_a_id !== null && $match->entry_b_id !== null) {
                progressResult($match, Side::A);
            }
        });

    $tournament = $this->tournament->refresh();
    $final = progressMatch($tournament, 3, 1);

    expect($tournament->status)->toBe(TournamentStatus::Completed)
        ->and($tournament->champion()?->id)->toBe($final->entry_a_id);

    SaveMatch::handle(['status' => 'scheduled'], $final);

    expect($tournament->refresh()->status)->toBe(TournamentStatus::InProgress)
        ->and($tournament->champion())->toBeNull();
});

test('a round robin completes when every match that is not cancelled is finished', function () {
    $tournament = Tournament::factory()->open()->roundRobin()->withEntries(3)->create();
    DrawGenerator::generate($tournament);
    [$one, $two, $three] = $tournament->matches()->orderBy('round')->get()->all();

    progressResult($one, Side::A);
    SaveMatch::handle(['status' => 'cancelled'], $two);

    expect($tournament->refresh()->status)->toBe(TournamentStatus::InProgress);

    progressResult($three, Side::B);

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Completed);

    SaveMatch::handle(['status' => 'scheduled'], $three);

    expect($tournament->refresh()->status)->toBe(TournamentStatus::InProgress);
});
