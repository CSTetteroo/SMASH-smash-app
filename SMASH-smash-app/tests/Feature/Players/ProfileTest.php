<?php

use App\Actions\Matches\SaveMatch;
use App\Actions\Tournaments\RegisterForTournament;
use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Models\BadmintonMatch;
use App\Models\Tournament;
use App\Models\User;
use Livewire\Livewire;

/**
 * A completed friendly through the real domain action (ratings recalculated).
 *
 * @param  list<User>  $sideA
 * @param  list<User>  $sideB
 * @param  list<array{0:int,1:int}>  $games
 */
function playersPlayMatch(array $sideA, array $sideB, array $games = [[21, 15], [21, 17]], string $playedAt = '2026-10-20 20:30'): BadmintonMatch
{
    return SaveMatch::handle([
        'discipline' => count($sideA) > 1 ? Discipline::Doubles : Discipline::Singles,
        'side_a' => array_map(fn (User $user): int => $user->id, $sideA),
        'side_b' => array_map(fn (User $user): int => $user->id, $sideB),
        'status' => MatchStatus::Completed,
        'played_at' => $playedAt,
        'games' => array_map(fn (array $game): array => ['a' => $game[0], 'b' => $game[1]], $games),
    ]);
}

test('guests can view a player profile with stats, matches and achievements', function () {
    $sam = User::factory()->create(['name' => 'Sam de Vries', 'email' => 'sam.secret@smash.test', 'bio' => 'Left-handed smasher from the Games programme.']);
    $lotte = User::factory()->create(['name' => 'Lotte Jansen']);

    playersPlayMatch([$sam], [$lotte], playedAt: '2026-10-20 20:30');
    playersPlayMatch([$sam], [$lotte], [[21, 19], [18, 21], [21, 16]], '2026-10-22 20:30');

    $this->get(route('players.show', $sam))
        ->assertOk()
        ->assertSee('Sam de Vries')
        ->assertSee('Left-handed smasher from the Games programme.')
        ->assertSee('Statistics')
        ->assertSee('Recent results')
        ->assertSee('Lotte Jansen')
        ->assertSee('First Blood')
        ->assertSee('Achievements')
        ->assertDontSee('sam.secret@smash.test');
});

test('the profile Livewire state never contains the email address', function () {
    $sam = User::factory()->create(['email' => 'sam.secret@smash.test']);

    $component = Livewire::test('pages::players.show', ['user' => $sam]);

    expect($component->html())->not->toContain('sam.secret@smash.test')
        ->and(json_encode($component->snapshot))->not->toContain('sam.secret@smash.test');
});

test('players without matches get friendly empty states', function () {
    $newbie = User::factory()->create(['name' => 'Bence Toth']);

    $this->get(route('players.show', $newbie))
        ->assertOk()
        ->assertSee('No rated singles matches yet')
        ->assertSee('No results yet')
        ->assertSee('No tournaments yet');
});

test('the discipline switch shows doubles stats and partners', function () {
    [$a, $b, $c, $d] = User::factory()->count(4)->create()->all();
    $a->update(['name' => 'Alex Ander']);
    $b->update(['name' => 'Bo Partner']);

    playersPlayMatch([$a, $b], [$c, $d]);
    playersPlayMatch([$a, $b], [$c, $d], playedAt: '2026-10-21 20:30');

    Livewire::test('pages::players.show', ['user' => $a->refresh()])
        ->assertSet('discipline', '')
        ->assertSee('Doubles rating')
        ->assertSee('Partners')
        ->assertSee('Bo Partner')
        ->set('discipline', 'singles')
        ->assertSee('No rated singles matches yet')
        ->set('discipline', 'doubles')
        ->assertSee('Partners');
});

test('inactive players keep a public profile with a notice', function () {
    $rita = User::factory()->inactive()->create(['name' => 'Retired Rita']);

    $this->get(route('players.show', $rita))
        ->assertOk()
        ->assertSee('Inactive member');
});

test('unknown players return a 404', function () {
    $this->get('/players/999999')->assertNotFound();
});

test('admin actions are only shown to admins', function () {
    $sam = User::factory()->create();

    $this->get(route('players.show', $sam))->assertOk()->assertDontSee(route('players.edit', $sam));

    $this->actingAs(User::factory()->create())
        ->get(route('players.show', $sam))
        ->assertOk()
        ->assertDontSee(route('players.edit', $sam))
        ->assertDontSee('data-test="toggle-active"', false);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('players.show', $sam))
        ->assertOk()
        ->assertSee(route('players.edit', $sam))
        ->assertSee('data-test="toggle-active"', false)
        ->assertSee('Delete player');
});

test('members see a link to edit their own player profile', function () {
    $member = User::factory()->create();

    $this->actingAs($member)
        ->get(route('players.show', $member))
        ->assertOk()
        ->assertSee('Edit your player profile');

    $this->get(route('players.show', User::factory()->create()))
        ->assertOk()
        ->assertDontSee('Edit your player profile');
});

test('admins can deactivate and reactivate a player', function () {
    $admin = User::factory()->admin()->create();
    $sam = User::factory()->create(['name' => 'Sam de Vries']);

    $component = Livewire::actingAs($admin)->test('pages::players.show', ['user' => $sam]);

    $component->call('toggleActive')->assertHasNoErrors()->assertSee('Inactive member');
    expect($sam->refresh()->is_active)->toBeFalse();

    $component->call('toggleActive');
    expect($sam->refresh()->is_active)->toBeTrue();
});

test('admins cannot deactivate themselves', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::players.show', ['user' => $admin])
        ->call('toggleActive')
        ->assertForbidden();

    expect($admin->refresh()->is_active)->toBeTrue();
});

test('members and guests cannot call the admin actions directly', function () {
    $sam = User::factory()->create();
    $member = User::factory()->create();

    Livewire::actingAs($member)->test('pages::players.show', ['user' => $sam])->call('toggleActive')->assertForbidden();
    Livewire::actingAs($member)->test('pages::players.show', ['user' => $sam])->call('delete')->assertForbidden();
    Livewire::actingAs($member)->test('pages::players.show', ['user' => $member])->call('delete')->assertForbidden();

    auth()->logout();

    Livewire::test('pages::players.show', ['user' => $sam])->call('toggleActive')->assertForbidden();
    Livewire::test('pages::players.show', ['user' => $sam])->call('delete')->assertForbidden();

    expect($sam->refresh()->is_active)->toBeTrue()
        ->and(User::query()->whereKey([$sam->id, $member->id])->count())->toBe(2);
});

test('the player id on the profile cannot be tampered with', function () {
    $admin = User::factory()->admin()->create();
    $sam = User::factory()->create();
    $other = User::factory()->create();

    expect(fn () => Livewire::actingAs($admin)
        ->test('pages::players.show', ['user' => $sam])
        ->set('user', $other->id))
        ->toThrow(Exception::class);
});

test('admins can delete a player, which removes their friendlies and recalculates ratings', function () {
    $admin = User::factory()->admin()->create();
    $sam = User::factory()->create();
    $lotte = User::factory()->create();

    playersPlayMatch([$sam], [$lotte]);
    expect($lotte->refresh()->singles_played)->toBe(1);

    Livewire::actingAs($admin)
        ->test('pages::players.show', ['user' => $sam])
        ->call('delete')
        ->assertRedirect(route('players.index'));

    expect(User::query()->find($sam->id))->toBeNull()
        ->and(BadmintonMatch::query()->count())->toBe(0)
        ->and($lotte->refresh()->singles_played)->toBe(0)
        ->and($lotte->singles_rating)->toBe(1500.0);
});

test('admins cannot delete themselves', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::players.show', ['user' => $admin])
        ->call('delete')
        ->assertForbidden();

    expect($admin->fresh())->not->toBeNull();
});

test('compare opens the head-to-head page', function () {
    $sam = User::factory()->create();
    $lotte = User::factory()->create();

    Livewire::test('pages::players.show', ['user' => $sam])
        ->set('compareWith', (string) $lotte->id)
        ->call('compare')
        ->assertHasNoErrors()
        ->assertRedirect(route('head-to-head', ['a' => $sam->id, 'b' => $lotte->id]));
});

test('compare validates the picked player', function () {
    $sam = User::factory()->create();

    Livewire::test('pages::players.show', ['user' => $sam])
        ->call('compare')
        ->assertHasErrors(['compareWith' => 'required'])
        ->set('compareWith', (string) $sam->id)
        ->call('compare')
        ->assertHasErrors(['compareWith'])
        ->set('compareWith', '999999')
        ->call('compare')
        ->assertHasErrors(['compareWith']);
});

test('logged-in members get themselves preselected to compare with', function () {
    $sam = User::factory()->create();
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test('pages::players.show', ['user' => $sam])
        ->assertSet('compareWith', (string) $member->id);
});

test('the profile shows upcoming matches and tournament history', function () {
    $sam = User::factory()->create();
    $lotte = User::factory()->create(['name' => 'Lotte Jansen']);

    SaveMatch::handle([
        'discipline' => Discipline::Singles,
        'side_a' => [$sam->id],
        'side_b' => [$lotte->id],
        'status' => MatchStatus::Scheduled,
        'scheduled_at' => now()->addDays(3)->setTime(20, 30),
    ]);

    $tournament = Tournament::factory()->open()->singles()->create(['name' => 'Winter Open']);
    RegisterForTournament::handle($tournament, $sam);

    $this->get(route('players.show', $sam))
        ->assertOk()
        ->assertSee('Coming up')
        ->assertSee('Lotte Jansen')
        ->assertSee('Winter Open')
        ->assertSee('Registered');
});
