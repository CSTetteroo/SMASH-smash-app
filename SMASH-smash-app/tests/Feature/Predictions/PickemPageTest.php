<?php

use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\Prediction;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournament\DrawGenerator;
use Livewire\Livewire;

/**
 * A scheduled friendly between two new players, kicking off tomorrow.
 */
function pickemFixture(array $attributes = [], ?User $playerA = null, ?User $playerB = null): BadmintonMatch
{
    return BadmintonMatch::factory()
        ->withPlayers($playerA ?? User::factory()->create(), $playerB ?? User::factory()->create())
        ->create(['scheduled_at' => now()->addDay(), ...$attributes]);
}

test('guests, members and admins can open pick’em', function (?string $role) {
    $fixture = pickemFixture();

    if ($role !== null) {
        $this->actingAs(User::factory()->{$role}()->create());
    }

    $this->get(route('predictions.index'))
        ->assertOk()
        ->assertSee('Pick’em', false)
        ->assertSee('Leaderboard')
        ->assertSee('id="match-'.$fixture->id.'"', false);
})->with(['guest' => null, 'member' => 'member', 'admin' => 'admin']);

test('guests see the table and a log-in prompt, but no pick actions', function () {
    $fixture = pickemFixture();

    Livewire::test('pages::predictions.index')
        ->assertSee('Log in to play')
        ->assertSee('Think you can beat the table?')
        ->assertSee($fixture->playersOn(Side::A)->first()->name)
        ->assertDontSeeHtml('wire:click="pick(')
        ->assertDontSee('Your record');
});

test('guests cannot predict, even by calling the action directly', function () {
    $fixture = pickemFixture();

    Livewire::test('pages::predictions.index')
        ->call('pick', $fixture->id, 'a', 0)
        ->assertRedirect(route('login'));

    expect(Prediction::query()->count())->toBe(0);
});

test('a member places a prediction and can change it before kick-off', function () {
    $member = User::factory()->create();
    $fixture = pickemFixture();

    $component = Livewire::actingAs($member)
        ->test('pages::predictions.index')
        ->assertSeeHtml('wire:click="pick('.$fixture->id.', &#039;a&#039;, 1)"')
        ->call('pick', $fixture->id, 'a', 1)
        ->assertHasNoErrors();

    $prediction = Prediction::query()->sole();
    expect($prediction->user_id)->toBe($member->id)
        ->and($prediction->match_id)->toBe($fixture->id)
        ->and($prediction->predicted_side)->toBe(Side::A)
        ->and($prediction->predicted_loser_games)->toBe(1);

    $component->assertSee('Your pick')
        ->assertSeeHtml('data-pick-selected')
        ->call('pick', $fixture->id, 'b', 0)
        ->assertHasNoErrors();

    $prediction->refresh();
    expect(Prediction::query()->count())->toBe(1)
        ->and($prediction->predicted_side)->toBe(Side::B)
        ->and($prediction->predicted_loser_games)->toBe(0);
});

test('single-game matches only take a winner', function () {
    $member = User::factory()->create();
    $fixture = pickemFixture(['best_of' => 1]);

    Livewire::actingAs($member)
        ->test('pages::predictions.index')
        ->call('pick', $fixture->id, 'b', 0)
        ->assertHasNoErrors();

    expect(Prediction::query()->sole()->predicted_loser_games)->toBeNull();
});

test('members cannot predict a match they play in', function () {
    $member = User::factory()->create();
    $fixture = pickemFixture([], $member);

    Livewire::actingAs($member)
        ->test('pages::predictions.index')
        ->assertSee('You’re playing in this one.', false)
        ->assertDontSeeHtml('wire:click="pick('.$fixture->id)
        ->call('pick', $fixture->id, 'a', 0)
        ->assertHasErrors('prediction');

    expect(Prediction::query()->count())->toBe(0);
});

test('started and live matches are locked', function () {
    $member = User::factory()->create();
    $started = pickemFixture(['scheduled_at' => now()->subMinutes(10)]);
    $live = BadmintonMatch::factory()->inProgress()->withRandomPlayers()->create();

    Livewire::actingAs($member)
        ->test('pages::predictions.index')
        ->assertSee('Picks closed at kick-off.')
        ->assertSee('Picks closed: this one is live.')
        ->assertDontSeeHtml('wire:click="pick('.$started->id)
        ->call('pick', $started->id, 'a', 0)
        ->assertHasErrors('prediction')
        ->call('pick', $live->id, 'b', 1)
        ->assertHasErrors('prediction');

    expect(Prediction::query()->count())->toBe(0);
});

test('finished matches and matches without known sides cannot be predicted', function () {
    $member = User::factory()->create();
    $finished = BadmintonMatch::factory()->completed()->withRandomPlayers()->create();
    $tournament = Tournament::factory()->open()->withEntries(4)->create();
    DrawGenerator::generate($tournament);
    $final = $tournament->matches()->where('round', 2)->sole();
    $final->update(['scheduled_at' => now()->addWeek()]);

    Livewire::actingAs($member)
        ->test('pages::predictions.index')
        ->assertSee('Picks open as soon as both sides are known.')
        ->call('pick', $finished->id, 'a', 0)
        ->assertHasErrors('prediction')
        ->call('pick', $final->id, 'a', 0)
        ->assertHasErrors('prediction');

    expect(Prediction::query()->count())->toBe(0);
});

test('picks are validated', function () {
    $member = User::factory()->create();
    $fixture = pickemFixture();

    Livewire::actingAs($member)
        ->test('pages::predictions.index')
        ->call('pick', $fixture->id, 'c', 0)
        ->assertHasErrors('side')
        ->call('pick', 'nope', 'a', 0)
        ->assertHasErrors('match')
        ->call('pick', $fixture->id, 'a', 9)
        ->assertHasErrors('games')
        ->call('pick', $fixture->id, 'a', 2)
        ->assertHasErrors('loser_games')
        ->call('pick', 999999, 'a', 0)
        ->assertHasErrors('prediction');

    expect(Prediction::query()->count())->toBe(0);
});

test('members need a verified email address to play', function () {
    $member = User::factory()->unverified()->create();
    $fixture = pickemFixture();

    Livewire::actingAs($member)
        ->test('pages::predictions.index')
        ->call('pick', $fixture->id, 'a', 0)
        ->assertHasErrors('prediction');

    expect(Prediction::query()->count())->toBe(0);
});

test('the leaderboard scores 3 for the right winner and 5 for the exact result', function () {
    $sharp = User::factory()->create(['name' => 'Priya Raman']);
    $lucky = User::factory()->create(['name' => 'Daan Visser']);
    $wrong = User::factory()->create(['name' => 'Ruben Dekker']);

    // Straight-games wins for side A (the loser takes 0 games).
    $first = BadmintonMatch::factory()->completed(Side::A)->withRandomPlayers()->create();
    $second = BadmintonMatch::factory()->completed(Side::A)->withRandomPlayers()->create();

    foreach ([$first, $second] as $played) {
        Prediction::factory()->for($sharp)->for($played, 'match')->picking(Side::A, 0)->create();
        Prediction::factory()->for($lucky)->for($played, 'match')->picking(Side::A, 1)->create();
        Prediction::factory()->for($wrong)->for($played, 'match')->picking(Side::B, 0)->create();
    }

    $component = Livewire::actingAs($lucky)
        ->test('pages::predictions.index')
        ->assertSeeInOrder(['Priya Raman', 'Daan Visser', 'Ruben Dekker'])
        ->assertSee('Your record')
        ->assertSee('Recently settled');

    $rows = $component->instance()->table->keyBy(fn ($row) => $row->user->id);

    expect($rows[$sharp->id]->points)->toBe(10)
        ->and($rows[$sharp->id]->exact)->toBe(2)
        ->and($rows[$lucky->id]->points)->toBe(6)
        ->and($rows[$lucky->id]->correct)->toBe(2)
        ->and($rows[$wrong->id]->points)->toBe(0)
        ->and($component->instance()->me->position)->toBe(2);
});

test('the SMASH model can be switched on', function () {
    pickemFixture();

    Livewire::test('pages::predictions.index')
        ->assertDontSeeHtml('data-pick-model')
        ->set('model', true)
        ->assertSeeHtml('data-pick-model');
});
