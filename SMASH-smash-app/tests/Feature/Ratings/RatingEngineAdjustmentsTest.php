<?php

use App\Enums\Discipline;
use App\Models\MatchPlayer;
use App\Models\RatingAdjustment;
use App\Models\User;
use App\Services\Rating\EloCalculator;
use App\Services\Rating\RatingEngine;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Domain\Support\Play;

// Play::win()/match() record results at 20:00, 21:00, 22:00 … on 1 September 2026.
beforeEach(fn () => Play::reset());

/**
 * Save an adjustment and recalculate, like the admin page does.
 */
function ratingsAdjust(User $user, float $amount, string $effectiveAt, Discipline $discipline = Discipline::Singles): RatingAdjustment
{
    $adjustment = RatingAdjustment::factory()
        ->for($user)
        ->amount($amount)
        ->effectiveAt($effectiveAt)
        ->create(['discipline' => $discipline]);

    RatingEngine::recalculate();

    return $adjustment;
}

/**
 * Every stored rating value exactly as the database holds it (users and match participants).
 *
 * @return array{users: list<array<string, mixed>>, participants: list<array<string, mixed>>}
 */
function ratingsRawState(): array
{
    return [
        'users' => DB::table('users')->orderBy('id')
            ->get(['id', 'singles_rating', 'doubles_rating', 'singles_peak', 'doubles_peak', 'singles_played', 'doubles_played', 'singles_wins', 'doubles_wins'])
            ->map(fn (stdClass $row): array => (array) $row)->all(),
        'participants' => DB::table('match_players')->orderBy('id')
            ->get(['id', 'rating_before', 'rating_after'])
            ->map(fn (stdClass $row): array => (array) $row)->all(),
    ];
}

function ratingsBefore(int $matchId, User $user): ?float
{
    return MatchPlayer::query()->where('match_id', $matchId)->where('user_id', $user->id)->value('rating_before');
}

function ratingsAfter(int $matchId, User $user): ?float
{
    return MatchPlayer::query()->where('match_id', $matchId)->where('user_id', $user->id)->value('rating_after');
}

/**
 * A small mixed history: singles and doubles, three games, an upset.
 *
 * @return list<User>
 */
function ratingsHistory(): array
{
    [$a, $b, $c, $d] = User::factory()->count(4)->create()->all();

    Play::win($a, $b);
    Play::match([$a, $c], [$b, $d], [[19, 21], [21, 12], [21, 19]]);
    Play::win($d, $a);
    Play::match($c, $b, [[21, 23], [21, 15], [21, 11]]);
    Play::win([$b, $d], [$a, $c]);

    return [$a, $b, $c, $d];
}

test('an adjustment moves the rating and the peak, never matches played or won', function () {
    $newcomer = User::factory()->create();

    ratingsAdjust($newcomer, 200, '2026-09-01 12:00:00');
    $newcomer->refresh();

    expect($newcomer->singles_rating)->toBe(1700.0)
        ->and($newcomer->singles_peak)->toBe(1700.0)
        ->and($newcomer->singles_played)->toBe(0)
        ->and($newcomer->singles_wins)->toBe(0)
        ->and($newcomer->isProvisional(Discipline::Singles))->toBeTrue()
        ->and([$newcomer->doubles_rating, $newcomer->doubles_peak, $newcomer->doubles_played])->toBe([1500.0, 1500.0, 0]);
});

test('a placement before the first match counts in that match, with the newcomer still on the provisional K', function () {
    [$newcomer, $regular] = User::factory()->count(2)->create();

    ratingsAdjust($newcomer, 200, '2026-09-01 19:59:00');
    $match = Play::win($regular, $newcomer);

    // The regular beats a 1700 newcomer: a bigger win than against 1500. Both are on K 40 (provisional).
    $change = 40 * EloCalculator::marginMultiplier(2, 0, 12, 3) * (1 - EloCalculator::expected(1500.0, 1700.0));

    expect(ratingsBefore($match->id, $newcomer))->toBe(1700.0)
        ->and(ratingsBefore($match->id, $regular))->toBe(1500.0)
        ->and($match->ratingChangeFor($regular))->toEqualWithDelta($change, 1e-9)
        ->and($match->ratingChangeFor($newcomer))->toEqualWithDelta(-$change, 1e-9)
        ->and($newcomer->refresh()->singles_rating)->toEqualWithDelta(1700 - $change, 1e-9)
        ->and($newcomer->singles_peak)->toBe(1700.0)
        ->and([$newcomer->singles_played, $newcomer->singles_wins])->toBe([1, 0]);
});

test('an adjustment counts after earlier matches and before later ones', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $first = Play::win($a, $b);   // 20:00
    $second = Play::win($a, $b);  // 21:00
    $third = Play::win($b, $a);   // 22:00

    $plain = [
        'first' => ratingsAfter($first->id, $a),
        'b_before_second' => ratingsBefore($second->id, $b),
        'b_change_second' => $second->ratingChangeFor($b),
    ];

    ratingsAdjust($a, -50, '2026-09-01 20:30:00');

    expect(ratingsBefore($first->id, $a))->toBe(1500.0)
        ->and(ratingsAfter($first->id, $a))->toBe($plain['first'])
        ->and(ratingsBefore($second->id, $a))->toEqualWithDelta($plain['first'] - 50, 1e-9)
        // b was not adjusted, but losing to a weaker a afterwards costs b more.
        ->and(ratingsBefore($second->id, $b))->toBe($plain['b_before_second'])
        ->and($second->refresh()->ratingChangeFor($b))->toBeLessThan((float) $plain['b_change_second'])
        ->and(ratingsBefore($third->id, $a))->toEqualWithDelta((float) ratingsAfter($second->id, $a), 1e-9)
        ->and($a->refresh()->singles_rating)->toBe(ratingsAfter($third->id, $a))
        ->and([$a->singles_played, $a->singles_wins])->toBe([3, 2]);
});

test('on a tie the adjustment goes first; a second later it waits for the next match', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $match = Play::win($a, $b); // 20:00:00

    ratingsAdjust($a, 100, '2026-09-01 20:00:00');
    ratingsAdjust($b, 100, '2026-09-01 20:00:01');

    expect(ratingsBefore($match->id, $a))->toBe(1600.0)
        ->and(ratingsBefore($match->id, $b))->toBe(1500.0)
        ->and($b->refresh()->singles_rating)->toEqualWithDelta((float) ratingsAfter($match->id, $b) + 100, 1e-9);
});

test('matches without a date replay before every adjustment', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $match = Play::win($a, $b);
    DB::table('matches')->update(['played_at' => null]);

    ratingsAdjust($a, 100, '2000-01-01 00:00:00');

    expect(ratingsBefore($match->id, $a))->toBe(1500.0)
        ->and($a->refresh()->singles_rating)->toEqualWithDelta((float) ratingsAfter($match->id, $a) + 100, 1e-9);
});

test('a penalty lowers the rating and keeps the peak; a later boost raises the peak', function () {
    [$a, $b] = User::factory()->count(2)->create();
    Play::win($a, $b);
    $high = $a->refresh()->singles_rating;

    ratingsAdjust($a, -100, '2026-09-02 12:00:00');

    expect($a->refresh()->singles_rating)->toEqualWithDelta($high - 100, 1e-9)
        ->and($a->singles_peak)->toBe($high);

    ratingsAdjust($a, 250, '2026-09-03 12:00:00');

    expect($a->refresh()->singles_rating)->toEqualWithDelta($high + 150, 1e-9)
        ->and($a->singles_peak)->toEqualWithDelta($high + 150, 1e-9)
        ->and($a->peak(Discipline::Singles))->toBe((int) round($high + 150))
        ->and([$a->singles_played, $a->singles_wins])->toBe([1, 1]);
});

test('a doubles adjustment moves the pair mean in later matches and leaves singles alone', function () {
    [$a, $b, $c, $d] = User::factory()->count(4)->create();

    ratingsAdjust($a, 100, '2026-09-01 12:00:00', Discipline::Doubles);
    $match = Play::match([$a, $b], [$c, $d], [[21, 15], [21, 15]]);

    $change = 40 * EloCalculator::marginMultiplier(2, 0, 12, 3) * (1 - EloCalculator::expected(1550.0, 1500.0));

    expect($match->ratingChangeFor($b))->toEqualWithDelta($change, 1e-9)
        ->and($match->ratingChangeFor($a))->toEqualWithDelta($change, 1e-9)
        ->and(ratingsBefore($match->id, $a))->toBe(1600.0)
        ->and($a->refresh()->singles_rating)->toBe(1500.0)
        ->and($a->singles_peak)->toBe(1500.0);
});

test('with no adjustments nothing changes: an outsider\'s adjustment and its deletion leave everyone else exactly as they were', function () {
    ratingsHistory();
    $plain = ratingsRawState();
    $outsider = User::factory()->create();

    $adjustment = ratingsAdjust($outsider, 75, '2026-09-01 19:00:00');
    $with = ratingsRawState();

    expect(array_slice($with['users'], 0, 4))->toBe($plain['users'])
        ->and($with['participants'])->toBe($plain['participants'])
        ->and($outsider->refresh()->singles_rating)->toBe(1575.0);

    $adjustment->delete();
    RatingEngine::recalculate();

    expect(array_slice(ratingsRawState()['users'], 0, 4))->toBe($plain['users'])
        ->and(ratingsRawState()['participants'])->toBe($plain['participants'])
        ->and($outsider->refresh()->singles_rating)->toBe(1500.0);

    // Recalculating again with no adjustments left writes nothing at all.
    DB::enableQueryLog();
    RatingEngine::recalculate();
    $writes = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/^\s*(update|insert|delete)/i', $query['query']) === 1);
    DB::disableQueryLog();

    expect($writes)->toBeEmpty();
});

test('deleting an adjustment that changed history gives back exactly the ratings without it', function () {
    [$a] = ratingsHistory();
    $plain = ratingsRawState();

    $adjustment = ratingsAdjust($a, 180, '2026-09-01 19:30:00');

    expect(ratingsRawState())->not->toBe($plain);

    $adjustment->delete();
    RatingEngine::recalculate();

    expect(ratingsRawState())->toBe($plain);
});

test('editing an adjustment gives the ratings it would have had all along', function () {
    [$a, $b] = ratingsHistory();

    $adjustment = ratingsAdjust($a, 50, '2026-09-01 21:30:00');
    $adjustment->update(['amount' => -30, 'effective_at' => '2026-09-01 19:30:00', 'user_id' => $b->id]);
    RatingEngine::recalculate();
    $edited = ratingsRawState();

    $adjustment->delete();
    ratingsAdjust($b, -30, '2026-09-01 19:30:00');

    expect(ratingsRawState())->toBe($edited);
});

test('simulate() previews adding, editing and deleting without writing anything', function () {
    [$a, $b] = ratingsHistory();
    $stored = ratingsAdjust($b, 40, '2026-09-01 22:30:00');
    $before = ratingsRawState();

    DB::enableQueryLog();
    $added = RatingEngine::simulate([['user_id' => $a->id, 'discipline' => 'singles', 'amount' => 120, 'effective_at' => '2026-09-01 20:30:00']]);
    $edited = RatingEngine::simulate([['id' => $stored->id, 'user_id' => $b, 'discipline' => Discipline::Singles, 'amount' => -60, 'effective_at' => '2026-09-01 19:30']], [$stored->id]);
    $deleted = RatingEngine::simulate(without: [$stored->id]);
    $writes = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/^\s*(update|insert|delete)/i', $query['query']) === 1);
    DB::disableQueryLog();

    expect($writes)->toBeEmpty()
        ->and(ratingsRawState())->toBe($before);

    // Each preview matches what saving really gives.
    $new = ratingsAdjust($a, 120, '2026-09-01 20:30:00');
    expect($a->refresh()->singles_rating)->toBe($added['singles'][$a->id]['rating'])
        ->and($b->refresh()->singles_rating)->toBe($added['singles'][$b->id]['rating']);
    $new->delete();

    $stored->update(['amount' => -60, 'effective_at' => '2026-09-01 19:30:00']);
    RatingEngine::recalculate();
    expect($b->refresh()->singles_rating)->toBe($edited['singles'][$b->id]['rating'])
        ->and($b->singles_peak)->toBe($edited['singles'][$b->id]['peak']);

    $stored->delete();
    RatingEngine::recalculate();
    expect($b->refresh()->singles_rating)->toBe($deleted['singles'][$b->id]['rating'])
        ->and($a->refresh()->singles_rating)->toBe($deleted['singles'][$a->id]['rating']);
});

test('simulate() accepts unsaved models and leaves players without history out', function () {
    $newcomer = User::factory()->create();
    $bystander = User::factory()->create();

    $state = RatingEngine::simulate([new RatingAdjustment([
        'user_id' => $newcomer->id,
        'discipline' => Discipline::Doubles,
        'amount' => 125,
        'effective_at' => now(),
    ])]);

    expect($state['doubles'][$newcomer->id])->toBe(['rating' => 1625.0, 'peak' => 1625.0, 'played' => 0, 'wins' => 0])
        ->and($state)->not->toHaveKey('singles')
        ->and($state['doubles'])->not->toHaveKey($bystander->id)
        ->and(RatingAdjustment::query()->count())->toBe(0);
});

test('rows the engine cannot apply are skipped', function () {
    $player = User::factory()->create();
    DB::table('rating_adjustments')->insert([
        'user_id' => $player->id, 'discipline' => 'mixed', 'amount' => 100, 'reason' => 'Not a discipline',
        'effective_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    RatingEngine::recalculate();

    expect($player->refresh()->singles_rating)->toBe(1500.0)
        ->and($player->doubles_rating)->toBe(1500.0);
});

test('adjustments are applied in effective order whatever order they were saved in', function () {
    $player = User::factory()->create();
    $opponent = User::factory()->create();

    ratingsAdjust($player, 100, '2026-09-01 21:30:00');
    $first = Play::win($opponent, $player);              // 20:00, before the +100
    ratingsAdjust($player, -40, '2026-09-01 19:00:00'); // before the first match
    $match = Play::win($player, $opponent);              // 21:00, before the +100

    expect(ratingsBefore($first->id, $player))->toBe(1460.0)
        ->and($player->refresh()->singles_rating)->toEqualWithDelta((float) ratingsAfter($match->id, $player) + 100, 1e-9)
        ->and($player->singles_peak)->toEqualWithDelta(max(1500.0, (float) ratingsAfter($match->id, $player) + 100), 1e-9);
});
