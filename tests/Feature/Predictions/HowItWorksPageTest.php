<?php

use App\Enums\Discipline;
use App\Models\RatingAdjustment;
use App\Models\User;
use App\Services\Prediction\PredictionBacktest;
use App\Services\Rating\RatingEngine;
use Livewire\Livewire;
use Tests\Feature\Domain\Support\Play;

beforeEach(fn () => Play::reset());

test('guests, members and admins can read how it works', function (?string $role) {
    if ($role !== null) {
        $this->actingAs(User::factory()->{$role}()->create());
    }

    $this->get(route('how-it-works'))
        ->assertOk()
        ->assertSee('Elo ratings in SMASH')
        ->assertSee('How SMASH calls a match')
        ->assertSee('How accurate is it?')
        ->assertSee('How badminton is scored')
        ->assertSee('Rating adjustments')
        ->assertSee('Pick’em rules', false);
})->with(['guest' => null, 'member' => 'member', 'admin' => 'admin']);

test('an empty club still renders, with empty states', function () {
    Livewire::test('pages::predictions.how-it-works')
        ->assertOk()
        ->assertSee('No results to test yet')
        ->assertDontSee('Worked example');
});

test('the explainer quotes the configured rating numbers', function () {
    config([
        'smash.rating.k_provisional' => 32,
        'smash.rating.k_established' => 16,
        'smash.rating.tournament_multiplier' => 1.5,
        'smash.rating.initial' => 1200,
    ]);

    Livewire::test('pages::predictions.how-it-works')
        ->assertSee('Everyone, in both disciplines')
        ->assertSee('1200')
        ->assertSee('32')
        ->assertSee('16')
        ->assertSee('×1.5')
        ->assertSee('K 48 and 24 in tournaments');
});

test('it shows the backtest numbers, a worked example and the biggest upsets', function () {
    [$strong, $mid, $weak] = User::factory()->count(3)->create()->all();
    $strong->update(['name' => 'Wei Chen']);
    $weak->update(['name' => 'Tom Peeters']);

    foreach (range(1, 4) as $round) {
        Play::win($strong, $mid);
        Play::win($mid, $weak);
        Play::win($strong, $weak);
    }
    $upset = Play::match($weak, $strong, [[21, 19], [19, 21], [21, 18]]);

    $backtest = PredictionBacktest::run();
    expect($backtest['matches'])->toBeGreaterThan(0);

    $component = Livewire::test('pages::predictions.how-it-works')
        ->assertSee((int) round($backtest['accuracy'] * 100).'%')
        ->assertSee(number_format($backtest['brier'], 3))
        ->assertSee('Calibration')
        ->assertSee('Worked example')
        ->assertSee('Tom Peeters beat Wei Chen 2–1')
        ->assertSee('Biggest upsets')
        ->assertSeeHtml(route('matches.show', $upset));

    // The worked example recomputes exactly the change the rating engine stored.
    $example = $component->instance()->example;
    $winner = $example['winner'];

    expect($example['match']->id)->toBe($upset->id)
        ->and($example['deltaW'])->toEqualWithDelta((float) $winner->rating_after - (float) $winner->rating_before, 1e-6)
        ->and($example['deltaL'])->toEqualWithDelta((float) $example['loser']->rating_after - (float) $example['loser']->rating_before, 1e-6);
});

test('the doubles team rating is the mean of both partners', function () {
    [$a, $b, $c, $d] = User::factory()->count(4)->create()->all();
    Play::win([$a, $b], [$c, $d]);
    Play::win([$a, $c], [$b, $d]);

    $doubles = Livewire::test('pages::predictions.how-it-works')
        ->assertSee('Doubles: the pair is the average')
        ->instance()->doubles;

    $mean = fn (array $players): float => array_sum(array_map(fn ($mp) => (float) $mp->rating_before, $players)) / 2;

    expect($doubles['teamA'])->toEqualWithDelta($mean($doubles['a']), 1e-9)
        ->and($doubles['teamB'])->toEqualWithDelta($mean($doubles['b']), 1e-9);
});

test('admins get a link to manage rating adjustments and everyone sees the count', function () {
    $admin = User::factory()->admin()->create();
    $player = User::factory()->create();
    RatingAdjustment::query()->create([
        'user_id' => $player->id,
        'discipline' => Discipline::Singles,
        'amount' => 120,
        'reason' => 'Placement: played league badminton in Poland',
        'effective_at' => now()->subWeek(),
        'created_by' => $admin->id,
    ]);
    RatingEngine::recalculate();

    Livewire::test('pages::predictions.how-it-works')
        ->assertSee('One rating has been placed or corrected by the committee.')
        ->assertDontSee('Manage rating adjustments')
        ->assertDontSee('Placement: played league badminton in Poland');

    Livewire::actingAs($admin)
        ->test('pages::predictions.how-it-works')
        ->assertSee('Manage rating adjustments');
});
