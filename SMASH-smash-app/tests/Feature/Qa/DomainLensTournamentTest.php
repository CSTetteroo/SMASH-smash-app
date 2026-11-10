<?php

/*
| QA probes (domain lens): tournament flows under adversarial use.
| Each test asserts the CORRECT behaviour; a failing test demonstrates a bug.
*/

use App\Actions\Matches\SaveMatch;
use App\Actions\Players\DeletePlayer;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournament\DrawGenerator;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function qaDomainMatch(Tournament $tournament, int $round, int $position): BadmintonMatch
{
    return $tournament->matches()->where('round', $round)->where('bracket_position', $position)->sole();
}

function qaDomainWin(BadmintonMatch $match, Side $winner): BadmintonMatch
{
    return SaveMatch::handle([
        'status' => MatchStatus::Completed,
        'games' => $winner === Side::A ? [['a' => 21, 'b' => 12], ['a' => 21, 'b' => 12]] : [['a' => 12, 'b' => 21], ['a' => 12, 'b' => 21]],
    ], $match);
}

test('an admin can save the edit form of a bye without breaking the draw', function () {
    $admin = User::factory()->admin()->create();
    $tournament = Tournament::factory()->open()->knockout()->singles()->withEntries(6)->create();
    DrawGenerator::generate($tournament);

    // 6 entries in a draw of 8: seeds 1 and 2 get byes (positions 1 and 3 in round 1).
    $bye = qaDomainMatch($tournament, 1, 1);
    expect($bye->isBye())->toBeTrue();
    $seedOne = $bye->entry_a_id;
    expect(qaDomainMatch($tournament, 2, 1)->entry_a_id)->toBe($seedOne);

    // Opening the form and pressing Save without touching anything should not fail.
    Livewire::actingAs($admin)
        ->test('pages::matches.edit', ['match' => $bye])
        ->call('save')
        ->assertHasNoErrors();
});

test('changing a bye to scheduled from the edit form keeps the seed in round 2 (or is refused)', function () {
    $admin = User::factory()->admin()->create();
    $tournament = Tournament::factory()->open()->knockout()->singles()->withEntries(6)->create();
    DrawGenerator::generate($tournament);

    $bye = qaDomainMatch($tournament, 1, 1);
    $seedOne = $bye->entry_a_id;

    // The only statuses the form offers for a bye are Scheduled and Cancelled.
    Livewire::actingAs($admin)
        ->test('pages::matches.edit', ['match' => $bye])
        ->set('form.status', 'scheduled')
        ->call('save');

    $bye->refresh();
    $next = qaDomainMatch($tournament, 2, 1);

    // Either the change is refused, or the seed must still be in round 2.
    expect($bye->status === MatchStatus::Walkover || $next->entry_a_id === $seedOne)->toBeTrue(
        "bye #{$bye->id} is now {$bye->status->value} and round-2 match #{$next->id} lost seed 1 (entry_a_id = ".var_export($next->entry_a_id, true).')'
    );
});

test('a bye cannot be awarded to its empty side', function () {
    $tournament = Tournament::factory()->open()->knockout()->singles()->withEntries(6)->create();
    DrawGenerator::generate($tournament);

    $bye = qaDomainMatch($tournament, 1, 1);
    $seedOne = $bye->entry_a_id;

    $refused = false;

    try {
        SaveMatch::handle(['status' => MatchStatus::Walkover, 'walkover_winner' => 'b'], $bye);
    } catch (ValidationException) {
        $refused = true;
    }

    $next = qaDomainMatch($tournament, 2, 1);

    expect($refused || $next->entry_a_id === $seedOne)->toBeTrue(
        'the bye was awarded to the missing entry: round 2 now has entry_a_id = '.var_export($next->entry_a_id, true)
    );
});

test('when a player in a running knockout deletes their account, the opponent can still be put through', function () {
    $admin = User::factory()->admin()->create();
    $tournament = Tournament::factory()->open()->knockout()->singles()->withEntries(4)->create();
    DrawGenerator::generate($tournament);

    $semi = qaDomainMatch($tournament, 1, 1);
    $leaver = $semi->entryA->player;

    // Self-service "Delete account" goes through DeletePlayer.
    DeletePlayer::handle($leaver);

    $semi->refresh();
    expect($semi->entry_a_id)->toBeNull();

    // The committee now tries to award the remaining player a walkover.
    $awarded = true;

    try {
        SaveMatch::handle(['status' => MatchStatus::Walkover, 'walkover_winner' => 'b'], $semi);
    } catch (ValidationException $e) {
        $awarded = false;
        $message = collect($e->errors())->flatten()->first();
    }

    $options = App\Livewire\Forms\MatchForm::statusOptions($admin, $semi->refresh());

    expect($awarded)->toBeTrue('walkover refused: '.($message ?? '').' — form offers only: '.implode(', ', array_keys($options)));
});

test('deleting a player keeps the champion of a finished knockout', function () {
    $tournament = Tournament::factory()->open()->knockout()->singles()->withEntries(2)->create();
    DrawGenerator::generate($tournament);

    $final = qaDomainMatch($tournament, 1, 1);
    qaDomainWin($final, Side::A);
    $tournament->refresh();
    $champion = $tournament->champion();
    expect($champion)->not->toBeNull();

    // The runner-up deletes their account; the champion should still be the champion.
    DeletePlayer::handle($final->refresh()->entryB->player);

    expect($tournament->refresh()->champion()?->id)->toBe($champion->id);
});
