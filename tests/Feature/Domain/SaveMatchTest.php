<?php

use App\Actions\Matches\DeleteMatch;
use App\Actions\Matches\SaveMatch;
use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\Prediction;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournament\DrawGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Domain\Support\Play;

beforeEach(fn () => Play::reset());

/**
 * The validation errors SaveMatch reports for $data (empty when it saves).
 *
 * @param  array<string, mixed>  $data
 * @return array<string, array<int, string>>
 */
function saveMatchErrors(array $data, ?BadmintonMatch $match = null, ?User $actor = null): array
{
    try {
        SaveMatch::handle($data, $match, $actor);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

/**
 * @return array<string, mixed>
 */
function saveMatchSingles(User $a, User $b, array $extra = []): array
{
    return ['discipline' => Discipline::Singles, 'side_a' => [$a->id], 'side_b' => [$b->id], ...$extra];
}

test('a completed friendly gets its winner from the games and updates ratings', function () {
    CarbonImmutable::setTestNow('2026-11-03 21:15:00');
    [$admin, $a, $b] = [User::factory()->admin()->create(), ...User::factory()->count(2)->create()];

    $match = SaveMatch::handle(saveMatchSingles($a, $b, [
        'status' => 'completed',
        'games' => [['a' => 15, 'b' => 21], ['a' => 21, 'b' => 19], ['a' => 18, 'b' => 21]],
        'court' => '2',
        'venue' => '  BUas Sports Hall ',
        'notes' => '',
    ]), actor: $admin);

    expect($match->status)->toBe(MatchStatus::Completed)
        ->and($match->winning_side)->toBe(Side::B)
        ->and($match->played_at?->toDateTimeString())->toBe('2026-11-03 21:15:00')
        ->and($match->scoreline())->toBe('1–2')
        ->and($match->games)->toHaveCount(3)
        ->and($match->games->pluck('number')->all())->toBe([1, 2, 3])
        ->and($match->court)->toBe(2)
        ->and($match->venue)->toBe('BUas Sports Hall')
        ->and($match->notes)->toBeNull()
        ->and($match->created_by)->toBe($admin->id)
        ->and($match->relationLoaded('participants'))->toBeTrue()
        ->and($match->playersOn(Side::A)->sole()->is($a))->toBeTrue()
        ->and($b->refresh()->singles_wins)->toBe(1)
        ->and($a->refresh()->singles_rating)->toBeLessThan(1500.0);

    CarbonImmutable::setTestNow();
});

test('game rows are accepted in the shapes forms send', function () {
    [$a, $b] = User::factory()->count(2)->create();

    $match = SaveMatch::handle(saveMatchSingles($a, $b, [
        'status' => 'completed',
        'games' => [[21, '17'], ['side_a_points' => '21', 'side_b_points' => 9], ['a' => '', 'b' => null]],
    ]));

    expect($match->games->map->score()->all())->toBe(['21–17', '21–9']);
    expect(saveMatchErrors(saveMatchSingles($a, $b, ['status' => 'completed', 'games' => [['a' => 21, 'b' => '']]])))
        ->toHaveKey('games')
        ->and(saveMatchErrors(saveMatchSingles($a, $b, ['status' => 'completed', 'games' => [['a' => 'x', 'b' => 3]]]))['games'][0])
        ->toContain('whole numbers');
});

test('invalid input is reported under the field it belongs to', function (Closure $data, string $key) {
    [$a, $b, $c] = User::factory()->count(3)->create();

    expect(saveMatchErrors($data($a, $b, $c)))->toHaveKey($key);
})->with([
    'missing discipline' => [fn ($a, $b) => ['side_a' => [$a->id], 'side_b' => [$b->id]], 'discipline'],
    'unknown discipline' => [fn ($a, $b) => saveMatchSingles($a, $b, ['discipline' => 'triples']), 'discipline'],
    'unknown status' => [fn ($a, $b) => saveMatchSingles($a, $b, ['status' => 'abandoned']), 'status'],
    'no opponent' => [fn ($a, $b) => saveMatchSingles($a, $b, ['side_b' => []]), 'side_b'],
    'two players in singles' => [fn ($a, $b, $c) => saveMatchSingles($a, $b, ['side_a' => [$a->id, $c->id]]), 'side_a'],
    'one player per side in doubles' => [fn ($a, $b) => saveMatchSingles($a, $b, ['discipline' => 'doubles']), 'side_a'],
    'same player on both sides' => [fn ($a, $b) => saveMatchSingles($a, $a), 'side_b'],
    'unknown player' => [fn ($a, $b) => saveMatchSingles($a, $b, ['side_b' => [999]]), 'side_a'],
    'best of four' => [fn ($a, $b) => saveMatchSingles($a, $b, ['best_of' => 4]), 'best_of'],
    'games to 25' => [fn ($a, $b) => saveMatchSingles($a, $b, ['points_per_game' => 25]), 'points_per_game'],
    'bad date' => [fn ($a, $b) => saveMatchSingles($a, $b, ['scheduled_at' => 'next tuesdayish']), 'scheduled_at'],
    'bad court' => [fn ($a, $b) => saveMatchSingles($a, $b, ['court' => 0]), 'court'],
    'completed without games' => [fn ($a, $b) => saveMatchSingles($a, $b, ['status' => 'completed']), 'games'],
    'completed but undecided' => [fn ($a, $b) => saveMatchSingles($a, $b, ['status' => 'completed', 'games' => Play::games([[21, 10]])]), 'games'],
    'impossible score' => [fn ($a, $b) => saveMatchSingles($a, $b, ['status' => 'completed', 'games' => Play::games([[25, 20], [21, 10]])]), 'games'],
    'live match already decided' => [fn ($a, $b) => saveMatchSingles($a, $b, ['status' => 'in_progress', 'games' => Play::games([[21, 10], [21, 10]])]), 'games'],
    'walkover without a winner' => [fn ($a, $b) => saveMatchSingles($a, $b, ['status' => 'walkover']), 'walkover_winner'],
]);

test('nothing is saved when validation fails', function () {
    [$a, $b] = User::factory()->count(2)->create();

    saveMatchErrors(saveMatchSingles($a, $b, ['status' => 'completed', 'games' => Play::games([[21, 20]])]));

    expect(BadmintonMatch::query()->count())->toBe(0);
});

test('members can only record matches they play in', function () {
    [$member, $a, $b] = User::factory()->count(3)->create();
    $admin = User::factory()->admin()->create();

    expect(saveMatchErrors(saveMatchSingles($a, $b), actor: $member))->toHaveKey('side_a')
        ->and(saveMatchErrors(saveMatchSingles($member, $b), actor: $member))->toBe([])
        ->and(saveMatchErrors(saveMatchSingles($a, $b), actor: $admin))->toBe([]);
});

test('a walkover has a winner but no games and never changes ratings', function () {
    [$a, $b] = User::factory()->count(2)->create();

    $match = SaveMatch::handle(saveMatchSingles($a, $b, [
        'status' => MatchStatus::Walkover,
        'walkover_winner' => 'B',
        'games' => Play::games([[21, 3]]),
    ]));

    expect($match->winning_side)->toBe(Side::B)
        ->and($match->games)->toBeEmpty()
        ->and($match->played_at)->not->toBeNull()
        ->and($match->scoreline())->toBe('W/O')
        ->and($b->refresh()->singles_played)->toBe(0);
});

test('a live match may end in an unfinished game and has no winner yet', function () {
    [$a, $b] = User::factory()->count(2)->create();

    $match = SaveMatch::handle(saveMatchSingles($a, $b, [
        'status' => 'in_progress',
        'games' => Play::games([[21, 18], [9, 11]]),
        'played_at' => '2026-10-01 20:00',
    ]));

    expect($match->winning_side)->toBeNull()
        ->and($match->played_at)->toBeNull()
        ->and($match->games)->toHaveCount(2)
        ->and($match->scoreline())->toBe('1–0');

    // Finishing it later only needs the status: the stored games are validated as a completed match.
    expect(saveMatchErrors(['status' => 'completed'], $match))->toHaveKey('games');

    $finished = SaveMatch::handle(['status' => 'completed', 'games' => Play::games([[21, 18], [21, 19]])], $match);

    expect($finished->winning_side)->toBe(Side::A)
        ->and($a->refresh()->singles_wins)->toBe(1);
});

test('updates keep what is not sent and resetting a result restores ratings', function () {
    [$a, $b, $c] = User::factory()->count(3)->create();
    $match = Play::win($a, $b, ['court' => 3, 'notes' => 'Great rallies']);

    $updated = SaveMatch::handle(['notes' => 'Even better rallies'], $match);

    expect($updated->court)->toBe(3)
        ->and($updated->games)->toHaveCount(2)
        ->and($updated->winning_side)->toBe(Side::A)
        ->and($updated->notes)->toBe('Even better rallies')
        ->and($a->refresh()->singles_played)->toBe(1);

    $swapped = SaveMatch::handle(['side_b' => [$c->id]], $match);

    expect($swapped->playersOn(Side::B)->sole()->is($c))->toBeTrue()
        ->and($swapped->participants)->toHaveCount(2)
        ->and($b->refresh()->singles_played)->toBe(0)
        ->and($c->refresh()->singles_played)->toBe(1);

    $reset = SaveMatch::handle(['status' => 'scheduled'], $match);

    expect($reset->games)->toBeEmpty()
        ->and($reset->winning_side)->toBeNull()
        ->and($reset->played_at)->toBeNull()
        ->and($a->refresh()->singles_rating)->toBe(1500.0)
        ->and($a->singles_played)->toBe(0);
});

test('cancelling a finished match removes it from the ratings', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $match = Play::win($a, $b);

    SaveMatch::handle(['status' => 'cancelled'], $match);

    expect($a->refresh()->singles_played)->toBe(0)
        ->and($match->refresh()->games()->count())->toBe(0);
});

test('tournament matches take their players from the entries', function () {
    $tournament = Tournament::factory()->open()->knockout()->withEntries(2)->create();
    DrawGenerator::generate($tournament);
    $final = $tournament->matches()->sole();
    $stranger = User::factory()->create();

    $match = SaveMatch::handle([
        'discipline' => 'doubles',
        'side_a' => [$stranger->id],
        'status' => 'completed',
        'games' => Play::games([[21, 15], [21, 15]]),
    ], $final);

    expect($match->discipline)->toBe(Discipline::Singles)
        ->and($match->hasParticipant($stranger))->toBeFalse()
        ->and($match->participants)->toHaveCount(2)
        ->and($match->playersOn(Side::A)->sole()->id)->toBe($match->entryA?->player_id)
        ->and($tournament->refresh()->status->value)->toBe('completed');
});

test('players can score their own tournament match, other members cannot', function () {
    $tournament = Tournament::factory()->open()->knockout()->withEntries(2)->create();
    DrawGenerator::generate($tournament);
    $final = $tournament->matches()->sole();
    $player = $final->playersOn(Side::B)->sole();
    $outsider = User::factory()->create();
    $live = ['status' => 'in_progress', 'games' => Play::games([[11, 9]])];

    expect(saveMatchErrors($live, $final, $outsider))->toHaveKey('side_a')
        ->and(saveMatchErrors($live, $final, $player))->toBe([])
        ->and($final->refresh()->status)->toBe(MatchStatus::InProgress);
});

test('a tournament result needs both entries', function () {
    $tournament = Tournament::factory()->open()->knockout()->withEntries(4)->create();
    DrawGenerator::generate($tournament);
    $final = $tournament->matches()->where('round', 2)->sole();

    expect(saveMatchErrors(['status' => 'completed', 'games' => Play::games([[21, 1], [21, 1]])], $final))->toHaveKey('status')
        ->and(saveMatchErrors(['court' => 2, 'scheduled_at' => '2026-12-12 15:00'], $final))->toBe([]);
});

test('a bye can be edited and stays a bye', function () {
    $tournament = Tournament::factory()->open()->knockout()->withEntries(3)->create();
    DrawGenerator::generate($tournament);
    $bye = $tournament->matches()->get()->first(fn (BadmintonMatch $match): bool => $match->isBye());

    $saved = SaveMatch::handle(['notes' => 'Top seed through'], $bye);

    expect($saved->isBye())->toBeTrue()
        ->and($saved->winning_side)->toBe(Side::A)
        ->and($saved->played_at)->toBeNull()
        ->and($saved->notes)->toBe('Top seed through')
        ->and($tournament->matches()->where('round', 2)->value('entry_a_id'))->toBe($bye?->entry_a_id);
});

test('deleting a friendly removes its results and recalculates', function () {
    [$a, $b, $c] = User::factory()->count(3)->create();
    $match = Play::win($a, $b);
    Prediction::factory()->for($match, 'match')->for($c)->create();

    DeleteMatch::handle($match);

    expect(BadmintonMatch::query()->count())->toBe(0)
        ->and(Prediction::query()->count())->toBe(0)
        ->and($a->refresh()->singles_played)->toBe(0)
        ->and($a->singles_rating)->toBe(1500.0);
});

test('tournament matches cannot be deleted one by one', function () {
    $tournament = Tournament::factory()->open()->withEntries(2)->create();
    DrawGenerator::generate($tournament);

    expect(fn () => DeleteMatch::handle($tournament->matches()->sole()))
        ->toThrow(ValidationException::class);

    try {
        DeleteMatch::handle($tournament->matches()->sole());
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('match');
    }
});
