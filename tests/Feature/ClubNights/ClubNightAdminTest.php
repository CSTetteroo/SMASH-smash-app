<?php

use App\Enums\ClubNightKind;
use App\Enums\RsvpStatus;
use App\Enums\SkillLevel;
use App\Models\ClubNight;
use App\Models\ClubNightRsvp;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| Committee tools: create / edit / duplicate / delete club nights and check people in at the
| door — and making sure members can't reach any of it, not even by calling actions directly.
*/

test('only admins can open the create and edit pages', function () {
    $night = ClubNight::factory()->upcoming()->create();
    $member = User::factory()->create();
    $admin = User::factory()->admin()->create();

    $this->get(route('club-nights.create'))->assertRedirect(route('login'));
    $this->get(route('club-nights.edit', $night))->assertRedirect(route('login'));

    $this->actingAs($member)->get(route('club-nights.create'))->assertForbidden();
    $this->actingAs($member)->get(route('club-nights.edit', $night))->assertForbidden();

    $this->actingAs($admin)->get(route('club-nights.create'))->assertOk()->assertSee('New club night');
    $this->actingAs($admin)->get(route('club-nights.edit', $night))->assertOk()->assertSee('Edit club night')->assertSee($night->title);
});

test('admins see the committee tools, members do not', function () {
    $night = ClubNight::factory()->upcoming()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('club-nights.show', $night))
        ->assertSee('Duplicate to next week')
        ->assertSee(route('club-nights.edit', $night), false);

    $this->actingAs(User::factory()->create())
        ->get(route('club-nights.show', $night))
        ->assertDontSee('Duplicate to next week')
        ->assertDontSee(route('club-nights.edit', $night), false);

    $this->actingAs(User::factory()->admin()->create())->get(route('club-nights.index'))->assertSee('New club night');
});

test('an admin creates a club night', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::club-nights.create')
        ->assertSet('venue', config('smash.club.venue'))
        ->set('title', 'Tuesday training: net play')
        ->set('kind', 'training')
        ->set('level', 'intermediate')
        ->set('description', 'Tight net shots, net kills and lifting under pressure.')
        ->set('date', '2026-12-01')
        ->set('start_time', '19:30')
        ->set('end_time', '22:00')
        ->set('courts', '4')
        ->set('capacity', '20')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show')
        ->assertRedirect(route('club-nights.show', ClubNight::query()->sole()));

    $night = ClubNight::query()->sole();

    expect($night->title)->toBe('Tuesday training: net play')
        ->and($night->kind)->toBe(ClubNightKind::Training)
        ->and($night->level)->toBe(SkillLevel::Intermediate)
        ->and($night->starts_at->format('Y-m-d H:i'))->toBe('2026-12-01 19:30')
        ->and($night->ends_at->format('Y-m-d H:i'))->toBe('2026-12-01 22:00')
        ->and($night->capacity)->toBe(20)
        ->and($night->courts)->toBe(4)
        ->and($night->created_by)->toBe($admin->id);
});

test('the create form suggests a title until the admin types their own', function () {
    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::club-nights.create')
        ->set('date', '2026-12-03')
        ->assertSet('title', 'Thursday social')
        ->set('kind', 'ladder')
        ->assertSet('title', 'Ladder night')
        ->set('title', 'Christmas ladder')
        ->set('kind', 'social')
        ->assertSet('title', 'Christmas ladder');

    expect($component->get('level'))->toBe('');
});

test('the create form validates its input', function (array $input, string $field) {
    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::club-nights.create')
        ->set('date', '2026-12-01')
        ->set($input)
        ->call('save')
        ->assertHasErrors($field);

    expect(ClubNight::query()->count())->toBe(0);
})->with([
    'end before start' => [['start_time' => '22:00', 'end_time' => '19:30'], 'end_time'],
    'end equals start' => [['start_time' => '20:00', 'end_time' => '20:00'], 'end_time'],
    'no places' => [['capacity' => '0'], 'capacity'],
    'places not a number' => [['capacity' => 'lots'], 'capacity'],
    'no courts' => [['courts' => '0'], 'courts'],
    'missing title' => [['title' => ''], 'title'],
    'unknown kind' => [['kind' => 'karaoke'], 'kind'],
    'unknown level' => [['level' => 'legendary'], 'level'],
    'bad date' => [['date' => '1 December'], 'date'],
    'missing venue' => [['venue' => ''], 'venue'],
]);

test('duplicating prefills the form one week later', function () {
    $source = ClubNight::factory()->create([
        'title' => 'Tuesday training: smash & follow-up',
        'kind' => ClubNightKind::Training,
        'level' => SkillLevel::Advanced,
        'description' => 'Steep smashes and getting back in position.',
        'starts_at' => CarbonImmutable::parse('2026-11-10 19:30'),
        'ends_at' => CarbonImmutable::parse('2026-11-10 22:00'),
        'courts' => 3,
        'capacity' => 18,
    ]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->withQueryParams(['duplicate' => (string) $source->id])
        ->test('pages::club-nights.create')
        ->assertSet('title', 'Tuesday training: smash & follow-up')
        ->assertSet('kind', 'training')
        ->assertSet('level', 'advanced')
        ->assertSet('date', '2026-11-17')
        ->assertSet('start_time', '19:30')
        ->assertSet('end_time', '22:00')
        ->assertSet('courts', 3)
        ->assertSet('capacity', 18)
        ->assertSee('A copy of')
        ->call('save')
        ->assertHasNoErrors();

    expect(ClubNight::query()->count())->toBe(2)
        ->and(ClubNight::query()->latest('id')->first()->starts_at->format('Y-m-d H:i'))->toBe('2026-11-17 19:30');

    // A missing or malformed source just gives the empty form.
    Livewire::actingAs(User::factory()->admin()->create())
        ->withQueryParams(['duplicate' => 'abc'])
        ->test('pages::club-nights.create')
        ->assertSet('kind', 'social')
        ->assertDontSee('A copy of');
});

test('an admin edits a club night and extra places promote the waitlist', function () {
    $night = ClubNight::factory()->upcoming()->capacity(2)->withRsvps(2, 2)->create();
    $firstWaiting = $night->rsvps()->waitlisted()->first();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::club-nights.edit', ['clubNight' => $night])
        ->assertSet('capacity', 2)
        ->assertSee('2 members are going')
        ->set('title', 'Thursday social: mixed doubles')
        ->set('capacity', '3')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('club-nights.show', $night));

    $night->refresh();

    expect($night->title)->toBe('Thursday social: mixed doubles')
        ->and($night->capacity)->toBe(3)
        ->and($night->goingCount())->toBe(3)
        ->and($firstWaiting->fresh()->status)->toBe(RsvpStatus::Going);
});

test('the edit form validates too', function () {
    $night = ClubNight::factory()->upcoming()->create();
    $before = $night->starts_at;

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::club-nights.edit', ['clubNight' => $night])
        ->set('end_time', '06:00')
        ->set('capacity', '0')
        ->call('save')
        ->assertHasErrors(['end_time', 'capacity']);

    expect($night->fresh()->starts_at->equalTo($before))->toBeTrue();
});

test('an admin deletes a club night from its page or the edit page', function () {
    $admin = User::factory()->admin()->create();
    $first = ClubNight::factory()->upcoming()->withRsvps(3)->create();
    $second = ClubNight::factory()->upcoming()->create();

    Livewire::actingAs($admin)
        ->test('pages::club-nights.show', ['clubNight' => $first])
        ->call('delete')
        ->assertRedirect(route('club-nights.index'));

    Livewire::actingAs($admin)
        ->test('pages::club-nights.edit', ['clubNight' => $second])
        ->call('delete')
        ->assertRedirect(route('club-nights.index'));

    expect(ClubNight::query()->count())->toBe(0)
        ->and(ClubNightRsvp::query()->count())->toBe(0);
});

test('an admin checks attendees in and out on the night', function () {
    $night = ClubNight::factory()->ongoing()->create();
    $member = User::factory()->create();
    $rsvp = ClubNightRsvp::factory()->for($night)->for($member)->going()->create();
    $waiting = ClubNightRsvp::factory()->for($night)->waitlist()->create();

    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::club-nights.show', ['clubNight' => $night])
        ->assertSee('Check in')
        ->call('toggleCheckIn', $rsvp->id);

    expect($rsvp->fresh()->checked_in_at)->not->toBeNull();
    $component->assertSee('1 / 1');

    // A waitlister who turns up and plays becomes going.
    $component->call('toggleCheckIn', $waiting->id);
    expect($waiting->fresh()->status)->toBe(RsvpStatus::Going)
        ->and($waiting->fresh()->checked_in_at)->not->toBeNull();

    $component->call('toggleCheckIn', $rsvp->id);
    expect($rsvp->fresh()->checked_in_at)->toBeNull();
});

test('check-in only opens on the day of the night', function () {
    $night = ClubNight::factory()->create(['starts_at' => now()->addDays(3)->setTime(19, 30)]);
    $rsvp = ClubNightRsvp::factory()->for($night)->going()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::club-nights.show', ['clubNight' => $night])
        ->assertSee('Check-in opens on')
        ->call('toggleCheckIn', $rsvp->id)
        ->assertDispatched('toast-show');

    expect($rsvp->fresh()->checked_in_at)->toBeNull();
});

test('an RSVP from another night cannot be checked in through this page', function () {
    $night = ClubNight::factory()->ongoing()->create();
    $other = ClubNightRsvp::factory()->for(ClubNight::factory()->ongoing())->going()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::club-nights.show', ['clubNight' => $night])
        ->call('toggleCheckIn', $other->id)
        ->assertDispatched('toast-show');

    expect($other->fresh()->checked_in_at)->toBeNull();
});

test('members cannot call the admin actions directly', function () {
    $night = ClubNight::factory()->ongoing()->create();
    $member = User::factory()->create();
    $rsvp = ClubNightRsvp::factory()->for($night)->for($member)->going()->create();

    Livewire::actingAs($member)
        ->test('pages::club-nights.show', ['clubNight' => $night])
        ->assertDontSee('Check in')
        ->call('toggleCheckIn', $rsvp->id)
        ->assertForbidden();

    Livewire::actingAs($member)
        ->test('pages::club-nights.show', ['clubNight' => $night])
        ->call('delete')
        ->assertForbidden();

    Livewire::test('pages::club-nights.show', ['clubNight' => $night])
        ->call('delete')
        ->assertForbidden();

    expect($rsvp->fresh()->checked_in_at)->toBeNull()
        ->and($night->fresh())->not->toBeNull();
});

test('members cannot mount the create or edit components', function () {
    $night = ClubNight::factory()->upcoming()->create();
    $member = User::factory()->create();

    Livewire::actingAs($member)->test('pages::club-nights.create')->assertForbidden();
    Livewire::actingAs($member)->test('pages::club-nights.edit', ['clubNight' => $night])->assertForbidden();
});

test('the club night id on the page cannot be swapped', function () {
    $night = ClubNight::factory()->upcoming()->create();
    $other = ClubNight::factory()->upcoming()->create();

    expect(fn () => Livewire::actingAs(User::factory()->admin()->create())
        ->test('pages::club-nights.edit', ['clubNight' => $night])
        ->set('clubNight', $other))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
