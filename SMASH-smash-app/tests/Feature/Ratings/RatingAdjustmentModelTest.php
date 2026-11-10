<?php

use App\Enums\Discipline;
use App\Models\BadmintonMatch;
use App\Models\RatingAdjustment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Feature\Domain\Support\Play;

beforeEach(fn () => Play::reset());

test('an adjustment casts its columns and knows its player and creator', function () {
    $admin = User::factory()->admin()->create();
    $player = User::factory()->create();

    $adjustment = RatingAdjustment::factory()->for($player)->by($admin)->doubles()->amount(-25)
        ->effectiveAt('2026-10-06 20:15:00')->create(['reason' => 'Penalty: no-show at the Autumn Doubles Cup.']);
    $adjustment = RatingAdjustment::query()->with(['user', 'creator'])->findOrFail($adjustment->id);

    expect($adjustment->discipline)->toBe(Discipline::Doubles)
        ->and($adjustment->amount)->toBe(-25.0)
        ->and($adjustment->effective_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($adjustment->effective_at->format('Y-m-d H:i'))->toBe('2026-10-06 20:15')
        ->and($adjustment->user->is($player))->toBeTrue()
        ->and($adjustment->creator?->is($admin))->toBeTrue()
        ->and($adjustment->formattedAmount())->toBe('−25')
        ->and($adjustment->isPositive())->toBeFalse()
        ->and($player->ratingAdjustments()->sole()->is($adjustment))->toBeTrue();
});

test('scopes filter by discipline and player and sort for the log and the replay', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $old = RatingAdjustment::factory()->for($a)->singles()->effectiveAt('2026-09-02 12:00')->create();
    $new = RatingAdjustment::factory()->for($a)->doubles()->effectiveAt('2026-10-02 12:00')->create();
    $other = RatingAdjustment::factory()->for($b)->singles()->effectiveAt('2026-09-20 12:00')->create();

    expect(RatingAdjustment::query()->forDiscipline(Discipline::Singles)->pluck('id')->sort()->values()->all())->toBe([$old->id, $other->id])
        ->and(RatingAdjustment::query()->forPlayer($a)->count())->toBe(2)
        ->and(RatingAdjustment::query()->forPlayer($b->id)->sole()->id)->toBe($other->id)
        ->and(RatingAdjustment::query()->latestFirst()->pluck('id')->all())->toBe([$new->id, $other->id, $old->id])
        ->and(RatingAdjustment::query()->inReplayOrder()->pluck('id')->all())->toBe([$old->id, $other->id, $new->id]);
});

test('deleting a player removes their adjustments; deleting the admin keeps the record', function () {
    $admin = User::factory()->admin()->create();
    $player = User::factory()->create();
    $kept = RatingAdjustment::factory()->by($admin)->create();
    RatingAdjustment::factory()->for($player)->by($admin)->create();

    $player->delete();
    $admin->delete();

    expect(RatingAdjustment::query()->count())->toBe(1)
        ->and($kept->refresh()->created_by)->toBeNull();
});

test('a placement defaults to one minute before the first rated match in that discipline', function () {
    [$player, $opponent, $c, $d] = User::factory()->count(4)->create();

    // Unrated and other-discipline matches do not count.
    Play::win($player, $opponent, ['is_rated' => false]);         // 20:00
    Play::match([$player, $c], [$opponent, $d], [[21, 15], [21, 15]]); // 21:00 doubles
    Play::win($opponent, $player);                                 // 22:00 singles
    Play::win($player, $opponent);                                 // 23:00 singles

    expect(RatingAdjustment::firstRatedMatchAt($player, Discipline::Singles)?->format('Y-m-d H:i:s'))->toBe('2026-09-01 22:00:00')
        ->and(RatingAdjustment::beforeFirstMatch($player, Discipline::Singles)->format('Y-m-d H:i:s'))->toBe('2026-09-01 21:59:00')
        ->and(RatingAdjustment::beforeFirstMatch($player->id, Discipline::Doubles)->format('Y-m-d H:i:s'))->toBe('2026-09-01 20:59:00');
});

test('a player without rated matches is placed from the season start', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 15:00:00'));
    $player = User::factory()->create();
    BadmintonMatch::factory()->singles()->scheduled()->withPlayers($player, User::factory()->create())->create();

    expect(RatingAdjustment::firstRatedMatchAt($player, Discipline::Singles))->toBeNull()
        ->and(RatingAdjustment::beforeFirstMatch($player, Discipline::Singles)->format('Y-m-d H:i:s'))->toBe('2026-09-01 00:00:00');
});
