<?php

use App\Actions\Matches\SaveMatch;
use App\Enums\Discipline;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournament\DrawGenerator;
use Livewire\Livewire;

/*
| Editing and deleting matches (admins): results are corrected and ratings recalculated,
| tournament matches keep their players and their draw, and members can't get in.
*/

function editMatchFriendly(User $a, User $b, array $games = [[21, 15], [21, 18]]): BadmintonMatch
{
    return SaveMatch::handle([
        'discipline' => 'singles',
        'side_a' => [$a->id],
        'side_b' => [$b->id],
        'status' => 'completed',
        'played_at' => now()->subDay(),
        'games' => $games,
    ]);
}

test('the edit form is prefilled with the stored match', function () {
    $admin = User::factory()->admin()->create();
    [$a, $b] = User::factory()->count(2)->create();
    $match = editMatchFriendly($a, $b, [[21, 15], [18, 21], [21, 19]]);
    $match->update(['court' => 3, 'notes' => 'Great final game.']);

    Livewire::actingAs($admin)
        ->test('pages::matches.edit', ['match' => $match])
        ->assertSet('form.discipline', 'singles')
        ->assertSet('form.sideA', [(string) $a->id])
        ->assertSet('form.sideB', [(string) $b->id])
        ->assertSet('form.status', 'completed')
        ->assertSet('form.court', '3')
        ->assertSet('form.notes', 'Great final game.')
        ->assertSet('form.games', [['a' => '21', 'b' => '15'], ['a' => '18', 'b' => '21'], ['a' => '21', 'b' => '19']]);
});

test('an admin flips a result and the ratings are recalculated', function () {
    $admin = User::factory()->admin()->create();
    [$a, $b] = User::factory()->count(2)->create();
    $match = editMatchFriendly($a, $b);

    expect($a->refresh()->ratingExact(Discipline::Singles))->toBeGreaterThan(1500.0);

    Livewire::actingAs($admin)
        ->test('pages::matches.edit', ['match' => $match])
        ->set('form.games.0.a', '15')->set('form.games.0.b', '21')
        ->set('form.games.1.a', '18')->set('form.games.1.b', '21')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('matches.show', $match));

    expect($match->refresh()->winning_side)->toBe(Side::B)
        ->and($a->refresh()->ratingExact(Discipline::Singles))->toBeLessThan(1500.0)
        ->and($b->refresh()->ratingExact(Discipline::Singles))->toBeGreaterThan(1500.0);
});

test('members cannot edit or delete, not even by calling the component directly', function () {
    $member = User::factory()->create();
    [$a, $b] = User::factory()->count(2)->create();
    $match = editMatchFriendly($member, $b);

    $this->actingAs($member)->get(route('matches.edit', $match))->assertForbidden();

    Livewire::actingAs($member)->test('pages::matches.edit', ['match' => $match])->assertForbidden();

    Livewire::actingAs($member)
        ->test('pages::matches.show', ['match' => $match])
        ->call('delete')
        ->assertForbidden();

    expect(BadmintonMatch::query()->whereKey($match->id)->exists())->toBeTrue();
});

test('an admin deletes a friendly from the match page and the ratings reset', function () {
    $admin = User::factory()->admin()->create();
    [$a, $b] = User::factory()->count(2)->create();
    $match = editMatchFriendly($a, $b);

    Livewire::actingAs($admin)
        ->test('pages::matches.show', ['match' => $match])
        ->call('delete')
        ->assertRedirect(route('matches.index'));

    expect(BadmintonMatch::query()->count())->toBe(0)
        ->and($a->refresh()->ratingExact(Discipline::Singles))->toBe(1500.0)
        ->and($a->played(Discipline::Singles))->toBe(0);
});

test('an admin deletes a friendly from the edit page', function () {
    $admin = User::factory()->admin()->create();
    $match = editMatchFriendly(...User::factory()->count(2)->create()->all());

    Livewire::actingAs($admin)
        ->test('pages::matches.edit', ['match' => $match])
        ->assertSee('data-test="delete-button"', false)
        ->call('delete')
        ->assertRedirect(route('matches.index'));

    expect(BadmintonMatch::query()->count())->toBe(0);
});

/**
 * A four-player knockout with both semi-finals and the final played.
 *
 * @return array{0: Tournament, 1: BadmintonMatch, 2: BadmintonMatch}
 */
function editMatchPlayedKnockout(): array
{
    $tournament = Tournament::factory()->open()->knockout()->singles()->withEntries(4)->create(['name' => 'Halloween Smash']);
    DrawGenerator::generate($tournament);

    $semis = $tournament->matches()->where('round', 1)->orderBy('bracket_position')->get();
    foreach ($semis as $semi) {
        SaveMatch::handle(['status' => 'completed', 'games' => [[21, 10], [21, 12]]], $semi);
    }

    $final = $tournament->matches()->where('round', 2)->firstOrFail();
    SaveMatch::handle(['status' => 'completed', 'games' => [[21, 19], [21, 19]]], $final);

    return [$tournament, $semis->first()->refresh(), $final->refresh()];
}

test('tournament matches cannot be deleted, even by admins calling the action', function () {
    $admin = User::factory()->admin()->create();
    [, $semi] = editMatchPlayedKnockout();

    $this->actingAs($admin)->get(route('matches.edit', $semi))
        ->assertOk()
        ->assertSee('Managed by the tournament')
        ->assertDontSee('data-test="delete-button"', false);

    Livewire::actingAs($admin)->test('pages::matches.edit', ['match' => $semi])->call('delete')->assertForbidden();
    Livewire::actingAs($admin)->test('pages::matches.show', ['match' => $semi])->call('delete')->assertForbidden();

    expect(BadmintonMatch::query()->whereKey($semi->id)->exists())->toBeTrue();
});

test('tournament players are locked and a winner change after the next round is refused', function () {
    $admin = User::factory()->admin()->create();
    [, $semi] = editMatchPlayedKnockout();
    $intruder = User::factory()->create();
    $before = $semi->participants()->pluck('user_id')->sort()->values()->all();

    // Changing the winner of a semi-final whose final has been played is refused by SaveMatch.
    Livewire::actingAs($admin)
        ->test('pages::matches.edit', ['match' => $semi])
        ->assertSee('Tournament matches take their players from the draw.')
        ->set('form.sideA.0', (string) $intruder->id)  // ignored for tournament matches
        ->set('form.games.0.a', '10')->set('form.games.0.b', '21')
        ->set('form.games.1.a', '12')->set('form.games.1.b', '21')
        ->call('save')
        ->assertHasErrors('form.games')
        ->assertSee('The next round has already started');

    // A correction that keeps the same winner is fine, and the players never change.
    Livewire::actingAs($admin)
        ->test('pages::matches.edit', ['match' => $semi])
        ->set('form.sideA.0', (string) $intruder->id)
        ->set('form.games.1.a', '23')->set('form.games.1.b', '21')
        ->call('save')
        ->assertHasNoErrors();

    expect($semi->refresh()->games->pluck('side_a_points')->all())->toBe([21, 23])
        ->and($semi->participants()->pluck('user_id')->sort()->values()->all())->toBe($before);
});
