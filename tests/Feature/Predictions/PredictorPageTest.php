<?php

use App\Enums\Discipline;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\User;
use App\Services\Prediction\MatchPrediction;
use Livewire\Livewire;
use Tests\Feature\Domain\Support\Play;

beforeEach(fn () => Play::reset());

test('guests, members and admins can open the predictor', function (?string $role) {
    $a = User::factory()->withRating(Discipline::Singles, 1650)->create(['name' => 'Wei Chen']);
    $b = User::factory()->withRating(Discipline::Singles, 1550)->create(['name' => 'Noor van Dijk']);

    if ($role !== null) {
        $this->actingAs(User::factory()->{$role}()->create());
    }

    $this->get(route('predictor', ['d' => 'singles', 'a1' => $a->id, 'b1' => $b->id]))
        ->assertOk()
        ->assertSee('Who wins?')
        ->assertSee('Wei Chen')
        ->assertSee('Noor van Dijk')
        ->assertSee('What moves the needle')
        ->assertDontSee($a->email)
        ->assertDontSee($b->email);
})->with(['guest' => null, 'member' => 'member', 'admin' => 'admin']);

test('the predictor reads the matchup from the query string and its chances add up to 100', function () {
    $a = User::factory()->withRating(Discipline::Singles, 1700)->create(['name' => 'Wei Chen']);
    $b = User::factory()->withRating(Discipline::Singles, 1500)->create(['name' => 'Sam de Vries']);

    $component = Livewire::withQueryParams(['d' => 'singles', 'a1' => $a->id, 'b1' => $b->id, 'bo' => 1, 'ppg' => 15])
        ->test('pages::predictions.predictor')
        ->assertSet('a1', $a->id)
        ->assertSet('b1', $b->id)
        ->assertSet('bo', 1)
        ->assertSet('ppg', 15)
        ->assertSee('SMASH makes Wei the favourite')
        ->assertSee('One game to 15');

    $prediction = $component->instance()->prediction;

    expect($prediction)->toBeInstanceOf(MatchPrediction::class)
        ->and($prediction->percentFor(Side::A) + $prediction->percentFor(Side::B))->toBe(100)
        ->and($prediction->probabilityA + $prediction->probabilityB)->toEqualWithDelta(1.0, 1e-9)
        ->and(array_sum($prediction->scoreDistribution))->toEqualWithDelta(1.0, 1e-6)
        ->and($prediction->favourite)->toBe(Side::A)
        ->and($prediction->bestOf)->toBe(1)
        ->and($prediction->pointsPerGame)->toBe(15);
});

test('doubles needs two players a side before it predicts', function () {
    [$a1, $a2, $b1, $b2] = User::factory()->count(4)->withRating(Discipline::Doubles, 1550)->create()->all();

    $component = Livewire::withQueryParams(['d' => 'doubles', 'a1' => $a1->id, 'b1' => $b1->id])
        ->test('pages::predictions.predictor')
        ->assertSee('Doubles needs two a side');

    expect($component->instance()->prediction)->toBeNull();

    $component->set('a2', $a2->id)->assertSee('One more partner');
    expect($component->instance()->prediction)->toBeNull();

    $component->set('b2', $b2->id)->assertDontSee('One more partner');

    $prediction = $component->instance()->prediction;
    expect($prediction)->toBeInstanceOf(MatchPrediction::class)
        ->and($prediction->percentFor(Side::A) + $prediction->percentFor(Side::B))->toBe(100)
        ->and($prediction->stakes['a_wins'])->toHaveKeys([$a1->id, $a2->id, $b1->id, $b2->id]);
});

test('a player can only be picked once: picking them again moves them', function () {
    [$a, $b, $c] = User::factory()->count(3)->create()->all();

    Livewire::withQueryParams(['d' => 'singles', 'a1' => $a->id, 'b1' => $b->id])
        ->test('pages::predictions.predictor')
        ->set('b1', $a->id)
        ->assertSet('b1', $a->id)
        ->assertSet('a1', null)
        ->set('a1', $c->id)
        ->assertSet('a1', $c->id);
});

test('tampered query strings are cleaned up', function () {
    $a = User::factory()->create();
    $inactive = User::factory()->inactive()->create();

    Livewire::withQueryParams(['d' => 'squash', 'a1' => $a->id, 'a2' => $a->id, 'b1' => $inactive->id, 'b2' => 999999, 'bo' => 7, 'ppg' => 99])
        ->test('pages::predictions.predictor')
        ->assertSet('d', 'singles')
        ->assertSet('a1', $a->id)
        ->assertSet('a2', null)
        ->assertSet('b1', null)
        ->assertSet('b2', null)
        ->assertSet('bo', 3)
        ->assertSet('ppg', 21)
        ->assertSee('Who are they playing?');
});

test('an empty predictor opens on the top of the rankings', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    Play::win($first, $second);
    Play::win($first, $second);

    Livewire::test('pages::predictions.predictor')
        ->assertRedirect(route('predictor', ['d' => 'singles', 'a1' => $first->id, 'b1' => $second->id, 'bo' => 3, 'ppg' => 21]));
});

test('surprise me picks a full line-up of different players', function () {
    User::factory()->count(6)->create();

    $component = Livewire::withQueryParams(['d' => 'doubles'])
        ->test('pages::predictions.predictor')
        ->call('surprise');

    $ids = [$component->get('a1'), $component->get('a2'), $component->get('b1'), $component->get('b2')];

    expect(array_filter($ids))->toHaveCount(4)
        ->and(array_unique($ids))->toHaveCount(4)
        ->and($component->instance()->prediction)->toBeInstanceOf(MatchPrediction::class);
});

test('head-to-head history and the closest fixtures are shown', function () {
    $a = User::factory()->create(['name' => 'Ann Archer']);
    $b = User::factory()->create(['name' => 'Ben Baker']);
    Play::win($a, $b);
    Play::win($b, $a);
    Play::win($a, $b);

    [$c, $d] = User::factory()->count(2)->create()->all();
    BadmintonMatch::factory()->withPlayers($c, $d)->create(['scheduled_at' => now()->addDays(2)]);

    Livewire::withQueryParams(['d' => 'singles', 'a1' => $a->id, 'b1' => $b->id])
        ->test('pages::predictions.predictor')
        ->assertSee('3 rated meetings')
        ->assertSee('2–1')
        ->assertSee('Too close to call')
        ->assertSee($c->name)
        ->assertSee('Break it down');
});
