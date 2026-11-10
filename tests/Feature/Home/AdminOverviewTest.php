<?php

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Models\BadmintonMatch;
use App\Models\ClubNight;
use App\Models\Tournament;
use App\Models\User;
use Livewire\Livewire;

require_once __DIR__.'/HomeHelpers.php';

test('guests are sent to log in and members are forbidden', function () {
    $this->get(route('admin.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->member()->create());
    $this->get(route('admin.index'))->assertForbidden();

    Livewire::test('pages::admin.index')->assertForbidden();
});

test('admins see the overview with counts and quick-create links', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->inactive()->create();
    $a = User::factory()->create(['name' => 'Wei Chen', 'email' => 'wei.private@smash.test']);
    $b = User::factory()->create(['name' => 'Lotte Jansen']);
    homePlay([$a], [$b], extra: ['notes' => null]);

    $this->actingAs($admin);

    $this->get(route('admin.index'))
        ->assertOk()
        ->assertSee('Admin overview')
        ->assertSee('Active players')
        ->assertSee('1 inactive')
        ->assertSee(route('matches.create'), false)
        ->assertSee(route('tournaments.create'), false)
        ->assertSee(route('club-nights.create'), false)
        ->assertSee(route('players.create'), false)
        ->assertSee(route('rating-adjustments.index'), false)
        ->assertSee('Latest logged matches')
        ->assertSee('Wei Chen vs Lotte Jansen')
        ->assertSee('Newest members')
        ->assertSee('How good is the model?')
        ->assertSee('Recalculate all ratings')
        ->assertDontSee('wei.private@smash.test');
});

test('needs attention lists overdue results, draws to make and full club nights', function () {
    $a = User::factory()->create(['name' => 'Arjun Mehta']);
    $b = User::factory()->create(['name' => 'Jonas Weber']);
    homeFixture($a, $b, '-2 days');

    $closed = Tournament::factory()->open()->create([
        'name' => 'Closed Cup',
        'registration_closes_at' => now()->subDay(),
        'starts_at' => now()->addWeek(),
    ]);
    Tournament::factory()->open()->create(['name' => 'Still Open Cup', 'registration_closes_at' => now()->addWeek(), 'starts_at' => now()->addWeeks(2)]);
    ClubNight::factory()->upcoming()->capacity(2)->withRsvps(2, 1)->create(['title' => 'Packed ladder night', 'starts_at' => now()->addDays(2)->setTime(20, 0)]);
    ClubNight::factory()->upcoming()->capacity(2)->withRsvps(2)->create(['title' => 'Far away social', 'starts_at' => now()->addDays(20)->setTime(20, 0)]);

    $this->actingAs(User::factory()->admin()->create());

    $overdue = BadmintonMatch::query()->where('status', MatchStatus::Scheduled->value)->firstOrFail();

    $this->get(route('admin.index'))
        ->assertOk()
        ->assertSee('Arjun Mehta vs Jonas Weber')
        ->assertSee(route('matches.score', $overdue), false)
        ->assertSee(route('matches.edit', $overdue), false)
        ->assertSee('Closed Cup')
        ->assertSee(route('tournaments.show', $closed), false)
        ->assertSee('Packed ladder night')
        ->assertSee('1 waiting')
        ->assertDontSee('Far away social');
});

test('a calm club shows that everything is clear', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('admin.index'))
        ->assertOk()
        ->assertSee('All clear')
        ->assertSee('Every match that should have been played has a result.')
        ->assertSee('No tournament is waiting for its draw.')
        ->assertSee('Every club night this week still has room.');
});

test('admins can recalculate every rating', function () {
    $admin = User::factory()->admin()->create();
    $a = User::factory()->create();
    $b = User::factory()->create();
    homePlay([$a], [$b]);
    $rating = $a->refresh()->ratingExact(Discipline::Singles);

    // Somebody fiddled with the stored rating by hand.
    $a->forceFill(['singles_rating' => 999, 'singles_played' => 0])->save();

    Livewire::actingAs($admin)->test('pages::admin.index')
        ->call('recalculate')
        ->assertHasNoErrors();

    expect($a->refresh()->ratingExact(Discipline::Singles))->toBe($rating)
        ->and($a->played(Discipline::Singles))->toBe(1);
});

test('members cannot trigger a recalculation with a direct call', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->member()->create();
    $a = User::factory()->create();
    $b = User::factory()->create();
    homePlay([$a], [$b]);
    $a->refresh()->forceFill(['singles_rating' => 999])->save();

    $component = Livewire::actingAs($admin)->test('pages::admin.index');

    $this->actingAs($member);
    $component->call('recalculate')->assertForbidden();

    expect($a->refresh()->ratingExact(Discipline::Singles))->toBe(999.0);
});
