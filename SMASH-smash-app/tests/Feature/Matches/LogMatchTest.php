<?php

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Logging a match (pages::matches.create): members log friendlies they played in, admins any
| match. Everything is checked on the server, so tampered Livewire calls get nowhere.
*/

/**
 * Fill the games editor row by row (side A's points first).
 *
 * @param  list<array{0: int|string, 1: int|string}>  $games
 */
function logMatchScores(Testable $page, array $games): Testable
{
    foreach ($games as $index => [$a, $b]) {
        while (count($page->get('form.games')) <= $index) {
            $page->call('addGame');
        }

        $page->set("form.games.{$index}.a", (string) $a)->set("form.games.{$index}.b", (string) $b);
    }

    return $page;
}

test('a member logs a friendly they played and the ratings move', function () {
    $member = User::factory()->create(['name' => 'Sam de Vries']);
    $opponent = User::factory()->create(['name' => 'Lotte Jansen']);

    $page = Livewire::actingAs($member)
        ->test('pages::matches.create')
        ->assertSet('form.sideA', [(string) $member->id])
        ->assertSet('form.status', 'completed')
        ->assertSet('form.venue', config('smash.club.venue'))
        ->set('form.sideB.0', (string) $opponent->id);

    logMatchScores($page, [[21, 15], [19, 21], [21, 17]])
        ->assertSee('If this result stands:')
        ->assertSee('Sam de Vries wins 2–1')
        ->call('save')
        ->assertHasNoErrors();

    $match = BadmintonMatch::query()->latest('id')->firstOrFail();
    $page->assertRedirect(route('matches.show', $match));

    expect($match->status)->toBe(MatchStatus::Completed)
        ->and($match->winning_side)->toBe(Side::A)
        ->and($match->games)->toHaveCount(3)
        ->and($match->created_by)->toBe($member->id)
        ->and($member->refresh()->ratingExact(Discipline::Singles))->toBeGreaterThan(1500.0)
        ->and($opponent->refresh()->ratingExact(Discipline::Singles))->toBeLessThan(1500.0)
        ->and($member->played(Discipline::Singles))->toBe(1);
});

test('a member cannot log a match without themselves, not even with a tampered request', function () {
    $member = User::factory()->create();
    [$a, $b] = User::factory()->count(2)->create();

    $page = Livewire::actingAs($member)
        ->test('pages::matches.create')
        ->set('form.sideA.0', (string) $a->id)  // the UI never offers this
        ->set('form.sideB.0', (string) $b->id);

    logMatchScores($page, [[21, 10], [21, 12]])
        ->call('save')
        ->assertHasErrors('form.sideA.0');

    expect(BadmintonMatch::query()->count())->toBe(0);
});

test('a member doubles partner slot cannot be themselves twice or a duplicate', function () {
    $member = User::factory()->create();
    [$partner, $x, $y] = User::factory()->count(3)->create();

    $page = Livewire::actingAs($member)
        ->test('pages::matches.create')
        ->set('form.discipline', 'doubles')
        ->assertCount('form.sideA', 2)
        ->set('form.sideA.1', (string) $partner->id)
        ->set('form.sideB.0', (string) $partner->id)
        ->set('form.sideB.1', (string) $y->id);

    logMatchScores($page, [[21, 10], [21, 12]])->call('save')->assertHasErrors(['form.sideA.1', 'form.sideB.0']);
    expect(BadmintonMatch::query()->count())->toBe(0);

    $page->set('form.sideB.0', (string) $x->id)->call('save')->assertHasNoErrors();

    $match = BadmintonMatch::query()->with('participants')->firstOrFail();
    expect($match->discipline)->toBe(Discipline::Doubles)
        ->and($match->participants)->toHaveCount(4);
});

test('impossible scores are rejected', function (array $games, string $message) {
    $member = User::factory()->create();
    $opponent = User::factory()->create();

    $page = Livewire::actingAs($member)
        ->test('pages::matches.create')
        ->set('form.sideB.0', (string) $opponent->id);

    logMatchScores($page, $games)
        ->call('save')
        ->assertHasErrors('form.games')
        ->assertSee($message);

    expect(BadmintonMatch::query()->count())->toBe(0);
})->with([
    '21–20 is not finished' => [[[21, 20], [21, 10]], 'Check the score'],
    '25–20 cannot happen' => [[[25, 20], [21, 10]], 'Check the score'],
    'a game after the match was decided' => [[[21, 10], [21, 12], [21, 15]], 'Check the score'],
    'undecided match' => [[[21, 10], [10, 21]], 'Check the score'],
]);

test('the score check explains problems while typing', function () {
    $member = User::factory()->create();

    $page = Livewire::actingAs($member)->test('pages::matches.create');

    // Level at one game each: the decider row is added automatically.
    logMatchScores($page, [[21, 17], [15, 21]]);
    expect($page->get('form.games'))->toHaveCount(3);
    $page->assertSee('Level at 1–1 in games: add the deciding game.');

    $page->set('form.games.0.b', '20')->assertSee('Check the score');
});

test('members can only choose completed or scheduled', function () {
    $member = User::factory()->create();
    $opponent = User::factory()->create();

    Livewire::actingAs($member)
        ->test('pages::matches.create')
        ->set('form.sideB.0', (string) $opponent->id)
        ->set('form.status', 'walkover')
        ->set('form.walkoverWinner', 'a')
        ->call('save')
        ->assertHasErrors('form.status');

    expect(BadmintonMatch::query()->count())->toBe(0);
});

test('a member schedules a challenge for next week', function () {
    $member = User::factory()->create();
    $opponent = User::factory()->create();
    $when = now()->addWeek()->setTime(20, 30);

    Livewire::actingAs($member)
        ->test('pages::matches.create')
        ->set('form.sideB.0', (string) $opponent->id)
        ->set('form.status', 'scheduled')
        ->call('save')
        ->assertHasErrors('form.scheduledAt')
        ->set('form.scheduledAt', $when->format('Y-m-d\TH:i'))
        ->set('form.court', '2')
        ->call('save')
        ->assertHasNoErrors();

    $match = BadmintonMatch::query()->firstOrFail();
    expect($match->status)->toBe(MatchStatus::Scheduled)
        ->and($match->scheduled_at->format('Y-m-d H:i'))->toBe($when->format('Y-m-d H:i'))
        ->and($match->court)->toBe(2)
        ->and($match->games)->toHaveCount(0)
        ->and($match->played_at)->toBeNull();
});

test('a practice match is saved without touching ratings', function () {
    $member = User::factory()->create();
    $opponent = User::factory()->create();

    $page = Livewire::actingAs($member)
        ->test('pages::matches.create')
        ->set('form.sideB.0', (string) $opponent->id)
        ->set('form.isRated', false)
        ->assertSee('Practice match — doesn&#039;t count for ELO', false);

    logMatchScores($page, [[21, 3], [21, 4]])->call('save')->assertHasNoErrors();

    expect(BadmintonMatch::query()->firstOrFail()->is_rated)->toBeFalse()
        ->and($member->refresh()->ratingExact(Discipline::Singles))->toBe(1500.0)
        ->and($member->played(Discipline::Singles))->toBe(0);
});

test('a result cannot be dated in the future', function () {
    $member = User::factory()->create();
    $opponent = User::factory()->create();

    $page = Livewire::actingAs($member)
        ->test('pages::matches.create')
        ->set('form.sideB.0', (string) $opponent->id)
        ->set('form.playedAt', now()->addDays(3)->format('Y-m-d\TH:i'));

    logMatchScores($page, [[21, 3], [21, 4]])->call('save')->assertHasErrors('form.playedAt');
});

test('inactive players cannot be picked', function () {
    $member = User::factory()->create();
    $inactive = User::factory()->inactive()->create();

    $page = Livewire::actingAs($member)
        ->test('pages::matches.create')
        ->assertDontSee($inactive->name)
        ->set('form.sideB.0', (string) $inactive->id);

    logMatchScores($page, [[21, 3], [21, 4]])->call('save')->assertHasErrors('form.sideB.0');
});

test('admins can log any match, including walkovers', function () {
    $admin = User::factory()->admin()->create();
    [$a, $b] = User::factory()->count(2)->create();

    Livewire::actingAs($admin)
        ->test('pages::matches.create')
        ->assertSet('form.sideA', [''])
        ->set('form.sideA.0', (string) $a->id)
        ->set('form.sideB.0', (string) $b->id)
        ->set('form.status', 'walkover')
        ->call('save')
        ->assertHasErrors('form.walkoverWinner')
        ->set('form.walkoverWinner', 'b')
        ->call('save')
        ->assertHasNoErrors();

    $match = BadmintonMatch::query()->firstOrFail();
    expect($match->status)->toBe(MatchStatus::Walkover)
        ->and($match->winning_side)->toBe(Side::B)
        ->and($match->hasParticipant($admin))->toBeFalse();
});

test('guests cannot reach the form', function () {
    $this->get(route('matches.create'))->assertRedirect(route('login'));
});

test('player names are on the form but email addresses are not', function () {
    $member = User::factory()->create();
    $other = User::factory()->create(['name' => 'Chidi Okafor']);

    Livewire::actingAs($member)
        ->test('pages::matches.create')
        ->assertSee('Chidi Okafor')
        ->assertDontSee($other->email);
});
