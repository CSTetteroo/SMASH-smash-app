<?php

use App\Enums\Discipline;
use App\Models\RatingAdjustment;
use App\Models\User;
use App\Services\Rating\RatingEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Domain\Support\Play;

const ADJUSTMENTS_PAGE = 'pages::ratings.adjustments';

beforeEach(function () {
    Play::reset();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 15:00:00'));
});

/**
 * The page mounted as an admin, with the form filled in for a new adjustment.
 */
function adjustmentForm(User $admin, User $player, array $fields = []): Testable
{
    $component = Livewire::actingAs($admin)->test(ADJUSTMENTS_PAGE)->call('create');

    foreach ([
        'userId' => (string) $player->id,
        'formDiscipline' => 'singles',
        'amount' => '150',
        'reason' => 'Placement: four seasons of league singles before joining SMASH.',
        'timing' => 'now',
        ...$fields,
    ] as $property => $value) {
        $component->set($property, $value);
    }

    return $component;
}

// ---- Access ------------------------------------------------------------

test('guests are sent to the login page', function () {
    $this->get(route('rating-adjustments.index'))->assertRedirect(route('login'));
});

test('members are forbidden, on the route and when mounting the component', function () {
    $member = User::factory()->create();

    $this->actingAs($member)->get(route('rating-adjustments.index'))->assertForbidden();
    Livewire::actingAs($member)->test(ADJUSTMENTS_PAGE)->assertForbidden();
});

test('admins see the log with players, amounts and reasons, but never email addresses', function () {
    $admin = User::factory()->admin()->create(['name' => 'Noor van Dijk']);
    $player = User::factory()->create(['name' => 'Arne Maes', 'email' => 'arne.secret@smash.test']);
    RatingAdjustment::factory()->for($player)->by($admin)->singles()->amount(120)
        ->effectiveAt('2026-11-01 11:12:00')
        ->create(['reason' => 'Placement: four seasons at BC Leuven.']);
    RatingEngine::recalculate();

    $this->actingAs($admin)->get(route('rating-adjustments.index'))
        ->assertOk()
        ->assertSee('Rating adjustments')
        ->assertSee('Arne Maes')
        ->assertSee('Placement: four seasons at BC Leuven.')
        ->assertSee('+120')
        ->assertSee('Sun 1 Nov 2026')
        ->assertSee('Noor van Dijk')
        ->assertDontSee('arne.secret@smash.test');
});

test('an empty log explains what adjustments are for', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('rating-adjustments.index'))
        ->assertOk()
        ->assertSee('No adjustments yet');
});

// ---- Create --------------------------------------------------------------

test('an admin adds an adjustment: it is audited and the rating is recalculated', function () {
    $admin = User::factory()->admin()->create();
    $player = User::factory()->create();

    adjustmentForm($admin, $player)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false)
        ->assertDispatched('toast-show');

    $adjustment = RatingAdjustment::query()->sole();

    expect($adjustment->user_id)->toBe($player->id)
        ->and($adjustment->discipline)->toBe(Discipline::Singles)
        ->and($adjustment->amount)->toBe(150.0)
        ->and($adjustment->created_by)->toBe($admin->id)
        ->and($adjustment->effective_at->format('Y-m-d H:i'))->toBe('2026-11-04 15:00')
        ->and($player->refresh()->singles_rating)->toBe(1650.0)
        ->and($player->singles_played)->toBe(0);
});

test('"before first match" places the adjustment one minute before the first rated match in that discipline', function () {
    $admin = User::factory()->admin()->create();
    [$newcomer, $opponent] = User::factory()->count(2)->create();
    $first = Play::win($opponent, $newcomer);   // 2026-09-01 20:00
    Play::win($newcomer, $opponent);           // 21:00

    adjustmentForm($admin, $newcomer, ['amount' => '200', 'timing' => 'first'])
        ->assertSee('one minute before')
        ->call('save')
        ->assertHasNoErrors();

    expect(RatingAdjustment::query()->sole()->effective_at->format('Y-m-d H:i:s'))->toBe('2026-09-01 19:59:00')
        ->and($first->participantFor($newcomer)?->refresh()->rating_before)->toBe(1700.0)
        ->and($newcomer->refresh()->singles_played)->toBe(2);
});

test('"before first match" for a player without matches counts from the season start', function () {
    $admin = User::factory()->admin()->create();
    $player = User::factory()->create();

    adjustmentForm($admin, $player, ['timing' => 'first', 'formDiscipline' => 'doubles'])
        ->assertSee('season start')
        ->call('save')
        ->assertHasNoErrors();

    expect(RatingAdjustment::query()->sole()->effective_at->format('Y-m-d H:i:s'))->toBe('2026-09-01 00:00:00')
        ->and($player->refresh()->doubles_rating)->toBe(1650.0);
});

test('a chosen moment is stored as picked', function () {
    $admin = User::factory()->admin()->create();
    $player = User::factory()->create();

    adjustmentForm($admin, $player, ['amount' => '-25', 'timing' => 'custom', 'effectiveAt' => '2026-10-06T20:15'])
        ->call('save')
        ->assertHasNoErrors();

    expect(RatingAdjustment::query()->sole())
        ->amount->toBe(-25.0)
        ->effective_at->format('Y-m-d H:i:s')->toBe('2026-10-06 20:15:00');
});

test('the preview shows the rating before and after without writing anything', function () {
    $admin = User::factory()->admin()->create();
    [$player, $opponent] = User::factory()->count(2)->create();
    Play::win($player, $opponent);
    $current = $player->refresh()->singles_rating;

    $component = adjustmentForm($admin, $player, ['amount' => '100', 'timing' => 'custom', 'effectiveAt' => '2026-09-01T19:00']);
    $preview = $component->instance()->preview;

    expect($preview['current'])->toBe($current)
        ->and($preview['later'])->toBe(1)
        ->and($preview['others'])->toBe(1)
        ->and($preview['after'])->toBe(RatingEngine::simulate([[
            'user_id' => $player->id, 'discipline' => 'singles', 'amount' => 100, 'effective_at' => '2026-09-01 19:00:00',
        ]])['singles'][$player->id]['rating'])
        ->and(RatingAdjustment::query()->count())->toBe(0)
        ->and($player->refresh()->singles_rating)->toBe($current);

    $component->assertSee('What this does')->assertSee('1 later singles match');
});

// ---- Validation ----------------------------------------------------------

test('the form rejects invalid input', function (array $fields, string $error) {
    $admin = User::factory()->admin()->create();
    $player = User::factory()->create();

    adjustmentForm($admin, $player, $fields)
        ->call('save')
        ->assertHasErrors($error);

    expect(RatingAdjustment::query()->count())->toBe(0)
        ->and($player->refresh()->singles_rating)->toBe(1500.0);
})->with([
    'amount of zero' => [['amount' => '0'], 'amount'],
    'minus zero' => [['amount' => '-0'], 'amount'],
    'no amount' => [['amount' => ''], 'amount'],
    'decimal amount' => [['amount' => '12.5'], 'amount'],
    'too large' => [['amount' => '501'], 'amount'],
    'too small' => [['amount' => '-501'], 'amount'],
    'not a number' => [['amount' => 'lots'], 'amount'],
    'no reason' => [['reason' => ''], 'reason'],
    'whitespace reason' => [['reason' => "   \n  "], 'reason'],
    'reason too short' => [['reason' => 'oops'], 'reason'],
    'reason too long' => [['reason' => str_repeat('a', 161)], 'reason'],
    'no player' => [['userId' => ''], 'userId'],
    'unknown player' => [['userId' => '999999'], 'userId'],
    'unknown discipline' => [['formDiscipline' => 'mixed'], 'formDiscipline'],
    'unknown timing' => [['timing' => 'yesterday'], 'timing'],
    'future moment' => [['timing' => 'custom', 'effectiveAt' => '2026-11-05T10:00'], 'effectiveAt'],
    'missing moment' => [['timing' => 'custom', 'effectiveAt' => ''], 'effectiveAt'],
    'malformed moment' => [['timing' => 'custom', 'effectiveAt' => 'next tuesday'], 'effectiveAt'],
    'ancient moment' => [['timing' => 'custom', 'effectiveAt' => '1999-01-01T10:00'], 'effectiveAt'],
]);

// ---- Edit ------------------------------------------------------------------

test('editing loads the adjustment, saves the change and keeps the original author', function () {
    $author = User::factory()->admin()->create();
    $editor = User::factory()->admin()->create();
    $player = User::factory()->create();
    $adjustment = RatingAdjustment::factory()->for($player)->by($author)->singles()->amount(100)
        ->effectiveAt('2026-10-01 20:15:42')->create(['reason' => 'Placement: league player.']);
    RatingEngine::recalculate();

    Livewire::actingAs($editor)->test(ADJUSTMENTS_PAGE)
        ->call('edit', $adjustment->id)
        ->assertSet('editingId', $adjustment->id)
        ->assertSet('userId', (string) $player->id)
        ->assertSet('amount', '100')
        ->assertSet('timing', 'custom')
        ->assertSet('effectiveAt', '2026-10-01T20:15')
        ->assertSet('showForm', true)
        ->assertSee('As it stands nothing changes')
        ->set('amount', '-30')
        ->set('reason', 'Penalty after all: the placement was a mix-up.')
        ->call('save')
        ->assertHasNoErrors();

    $adjustment->refresh();

    expect(RatingAdjustment::query()->count())->toBe(1)
        ->and($adjustment->amount)->toBe(-30.0)
        ->and($adjustment->reason)->toBe('Penalty after all: the placement was a mix-up.')
        ->and($adjustment->created_by)->toBe($author->id)
        // The date field was left alone, so the stored seconds survive.
        ->and($adjustment->effective_at->format('Y-m-d H:i:s'))->toBe('2026-10-01 20:15:42')
        ->and($player->refresh()->singles_rating)->toBe(1470.0);
});

test('editing can move an adjustment to another player and date', function () {
    $admin = User::factory()->admin()->create();
    [$first, $second] = User::factory()->count(2)->create();
    $adjustment = RatingAdjustment::factory()->for($first)->singles()->amount(80)->effectiveAt('2026-10-01 20:00')->create();
    RatingEngine::recalculate();

    Livewire::actingAs($admin)->test(ADJUSTMENTS_PAGE)
        ->call('edit', $adjustment->id)
        ->set('userId', (string) $second->id)
        ->set('effectiveAt', '2026-10-02T21:30')
        ->call('save')
        ->assertHasNoErrors();

    expect($adjustment->refresh()->user_id)->toBe($second->id)
        ->and($adjustment->effective_at->format('Y-m-d H:i:s'))->toBe('2026-10-02 21:30:00')
        ->and($first->refresh()->singles_rating)->toBe(1500.0)
        ->and($second->refresh()->singles_rating)->toBe(1580.0);
});

test('the edited adjustment id is locked against tampering', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(ADJUSTMENTS_PAGE)->set('editingId', 1);
})->throws(CannotUpdateLockedPropertyException::class);

test('the adjustment to delete is locked against tampering', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(ADJUSTMENTS_PAGE)->set('deletingId', 1);
})->throws(CannotUpdateLockedPropertyException::class);

// ---- Delete ----------------------------------------------------------------

test('deleting asks first, then removes the adjustment and reverts the rating', function () {
    $admin = User::factory()->admin()->create();
    [$player, $opponent] = User::factory()->count(2)->create();
    Play::win($player, $opponent);
    $plain = $player->refresh()->singles_rating;
    $adjustment = RatingAdjustment::factory()->for($player)->singles()->amount(-40)->effectiveAt('2026-09-01 19:00')->create();
    RatingEngine::recalculate();

    $component = Livewire::actingAs($admin)->test(ADJUSTMENTS_PAGE)
        ->call('confirmDelete', $adjustment->id)
        ->assertSet('deletingId', $adjustment->id)
        ->assertSet('showDelete', true)
        ->assertSee('Delete this adjustment?')
        ->assertSee('to '.(int) round($plain));

    expect(RatingAdjustment::query()->count())->toBe(1);

    $component->call('delete')
        ->assertSet('showDelete', false)
        ->assertSet('deletingId', null)
        ->assertDispatched('toast-show');

    expect(RatingAdjustment::query()->count())->toBe(0)
        ->and($player->refresh()->singles_rating)->toBe($plain);
});

test('deleting an adjustment that is already gone is harmless', function () {
    $admin = User::factory()->admin()->create();
    $adjustment = RatingAdjustment::factory()->create();

    $component = Livewire::actingAs($admin)->test(ADJUSTMENTS_PAGE)->call('confirmDelete', $adjustment->id);
    $adjustment->delete();

    $component->call('delete')->assertHasNoErrors()->assertSet('showDelete', false);
});

// ---- Authorization on direct calls ------------------------------------------

test('a member cannot call the actions directly, even on a component mounted by an admin', function (string $method, array $parameters) {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $player = User::factory()->create();
    $adjustment = RatingAdjustment::factory()->for($player)->singles()->amount(50)->effectiveAt('2026-10-01 20:00')->create();
    RatingEngine::recalculate();

    $component = adjustmentForm($admin, $player)->set('amount', '300');
    $component->call('confirmDelete', $adjustment->id);

    $this->actingAs($member);

    $component->call($method, ...$parameters)->assertForbidden();

    expect(RatingAdjustment::query()->sole()->amount)->toBe(50.0)
        ->and($player->refresh()->singles_rating)->toBe(1550.0);
})->with([
    'save' => ['save', []],
    'delete' => ['delete', []],
    'create' => ['create', []],
    'edit' => ['edit', [1]],
    'confirmDelete' => ['confirmDelete', [1]],
]);

test('a logged-out visitor cannot call the actions either', function () {
    $admin = User::factory()->admin()->create();
    $player = User::factory()->create();

    $component = adjustmentForm($admin, $player);
    auth()->logout();

    $component->call('save')->assertForbidden();

    expect(RatingAdjustment::query()->count())->toBe(0);
});

// ---- Filters -------------------------------------------------------------------

test('the log filters by player and discipline', function () {
    $admin = User::factory()->admin()->create();
    [$a, $b] = User::factory()->count(2)->create();
    RatingAdjustment::factory()->for($a)->singles()->create(['reason' => 'Alpha singles reason']);
    RatingAdjustment::factory()->for($a)->doubles()->create(['reason' => 'Alpha doubles reason']);
    RatingAdjustment::factory()->for($b)->singles()->create(['reason' => 'Bravo singles reason']);

    Livewire::actingAs($admin)->test(ADJUSTMENTS_PAGE)
        ->assertSee(['Alpha singles reason', 'Alpha doubles reason', 'Bravo singles reason'])
        ->set('player', (string) $a->id)
        ->assertSee(['Alpha singles reason', 'Alpha doubles reason'])
        ->assertDontSee('Bravo singles reason')
        ->set('discipline', 'doubles')
        ->assertSee('Alpha doubles reason')
        ->assertDontSee('Alpha singles reason')
        ->call('clearFilters')
        ->assertSet('player', '')
        ->assertSet('discipline', '')
        ->assertSee('Bravo singles reason');
});

test('filters arrive from the query string and nonsense values are ignored', function () {
    $admin = User::factory()->admin()->create();
    [$a, $b] = User::factory()->count(2)->create();
    RatingAdjustment::factory()->for($a)->create(['reason' => 'Alpha reason']);
    RatingAdjustment::factory()->for($b)->create(['reason' => 'Bravo reason']);

    $this->actingAs($admin)->get(route('rating-adjustments.index', ['player' => $a->id]))
        ->assertOk()->assertSee('Alpha reason')->assertDontSee('Bravo reason');

    $this->actingAs($admin)->get(route('rating-adjustments.index', ['player' => 'drop table', 'discipline' => 'mixed']))
        ->assertOk()->assertSee(['Alpha reason', 'Bravo reason']);
});

test('a new adjustment from a filtered log starts with that player and discipline', function () {
    $admin = User::factory()->admin()->create();
    $player = User::factory()->create();
    RatingAdjustment::factory()->for($player)->doubles()->create();

    Livewire::actingAs($admin)->test(ADJUSTMENTS_PAGE)
        ->set('player', (string) $player->id)
        ->set('discipline', 'doubles')
        ->call('create')
        ->assertSet('userId', (string) $player->id)
        ->assertSet('formDiscipline', 'doubles')
        ->assertSet('editingId', null);
});

test('the log is paginated and loads without a query per row', function () {
    $admin = User::factory()->admin()->create();
    RatingAdjustment::factory()->count(20)->by($admin)->create();

    DB::enableQueryLog();
    $this->actingAs($admin)->get(route('rating-adjustments.index'))->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    RatingAdjustment::factory()->count(10)->by($admin)->create();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($admin)->get(route('rating-adjustments.index', ['page' => 2]))->assertOk();
    $more = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Page 2 has 15 rows instead of 5, with the same number of queries.
    expect($more)->toBe($queries);
});
