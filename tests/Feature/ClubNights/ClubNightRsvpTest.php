<?php

use App\Enums\ClubNightKind;
use App\Enums\RsvpStatus;
use App\Models\BadmintonMatch;
use App\Models\ClubNight;
use App\Models\ClubNightRsvp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| Club night pages for guests and members: viewing, RSVP (going / waitlist), leaving with
| waitlist promotion, and the rules around past nights and unverified accounts.
*/

test('guests can browse the schedule and a club night', function () {
    $next = ClubNight::factory()->upcoming()->withRsvps(3)->create(['title' => 'Thursday social']);
    $past = ClubNight::factory()->past()->create(['title' => 'Halloween social']);

    $this->get(route('club-nights.index'))
        ->assertOk()
        ->assertSee('Club nights')
        ->assertSee('Thursday social')
        ->assertSee('Halloween social')
        ->assertSee('Log in to RSVP')
        ->assertSee(route('club-nights.ics'), false)
        ->assertDontSee('New club night');

    $this->get(route('club-nights.show', $next))
        ->assertOk()
        ->assertSee('Thursday social')
        ->assertSee('Who\'s going')
        ->assertSee('Log in to RSVP');

    $this->get(route('club-nights.show', $past))
        ->assertOk()
        ->assertSee('Finished');
});

test('player email addresses never appear on the public pages', function () {
    $night = ClubNight::factory()->upcoming()->create();
    $member = User::factory()->create(['email' => 'lotte.jansen@smash.test']);
    ClubNightRsvp::factory()->for($night)->for($member)->create();

    $this->get(route('club-nights.index'))->assertOk()->assertDontSee('lotte.jansen@smash.test');
    $this->get(route('club-nights.show', $night))->assertOk()->assertSee($member->name)->assertDontSee('lotte.jansen@smash.test');
});

test('an unknown club night is a 404', function () {
    $this->get('/club-nights/999999')->assertNotFound();
});

test('the kind filter limits the upcoming nights and ignores unknown values', function () {
    ClubNight::factory()->upcoming()->create(['title' => 'Tuesday training: footwork', 'kind' => ClubNightKind::Training]);
    ClubNight::factory()->upcoming()->create(['title' => 'Thursday social', 'kind' => ClubNightKind::Social]);

    $component = Livewire::withQueryParams(['kind' => 'training'])
        ->test('pages::club-nights.index')
        ->assertSee('Tuesday training: footwork')
        ->assertSet('kind', 'training');

    expect($component->instance()->upcoming->pluck('title')->all())->toBe(['Tuesday training: footwork']);

    $component->set('kind', '');
    expect($component->instance()->upcoming)->toHaveCount(2);

    Livewire::withQueryParams(['kind' => 'karaoke'])
        ->test('pages::club-nights.index')
        ->assertSet('kind', '');
});

test('a guest who tries to RSVP is sent to the login page', function () {
    $night = ClubNight::factory()->upcoming()->create();

    Livewire::test('pages::club-nights.index')
        ->call('join', $night->id)
        ->assertRedirect(route('login'));

    Livewire::test('pages::club-nights.show', ['clubNight' => $night])
        ->call('join')
        ->assertRedirect(route('login'));

    expect(ClubNightRsvp::query()->count())->toBe(0);
});

test('a member joins from the schedule and is going', function () {
    $night = ClubNight::factory()->upcoming()->capacity(10)->create();
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test('pages::club-nights.index')
        ->call('join', $night->id)
        ->assertDispatched('toast-show')
        ->assertSee('You\'re going');

    expect($night->rsvpFor($member)?->status)->toBe(RsvpStatus::Going);
});

test('a member joins a full night from its page and lands on the waitlist', function () {
    $night = ClubNight::factory()->upcoming()->capacity(2)->withRsvps(2, 1)->create();
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test('pages::club-nights.show', ['clubNight' => $night])
        ->assertSee('Join the waitlist')
        ->call('join')
        ->assertSee('On the waitlist')
        ->assertSee('#2');

    expect($night->rsvpFor($member)?->status)->toBe(RsvpStatus::Waitlist)
        ->and($night->waitlistPosition($member))->toBe(2);
});

test('joining twice keeps a single RSVP', function () {
    $night = ClubNight::factory()->upcoming()->create();
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test('pages::club-nights.show', ['clubNight' => $night])
        ->call('join')
        ->call('join');

    expect(ClubNightRsvp::query()->where('user_id', $member->id)->count())->toBe(1);
});

test('leaving a full night gives the place to the first person on the waitlist', function () {
    $night = ClubNight::factory()->upcoming()->capacity(2)->create();
    [$going, $other, $first, $second] = User::factory()->count(4)->create();

    ClubNightRsvp::factory()->for($night)->for($going)->going()->create();
    ClubNightRsvp::factory()->for($night)->for($other)->going()->create();
    ClubNightRsvp::factory()->for($night)->for($first)->waitlist()->create(['created_at' => now()->subHours(2)]);
    ClubNightRsvp::factory()->for($night)->for($second)->waitlist()->create(['created_at' => now()->subHour()]);

    Livewire::actingAs($going)
        ->test('pages::club-nights.show', ['clubNight' => $night])
        ->assertSee('Leave, and the first person on the waitlist gets your place straight away.')
        ->call('confirmLeave')
        ->call('leave')
        ->assertDispatched('toast-show')
        ->assertSee('Join the waitlist');

    $night->refresh();

    expect($night->rsvpFor($going))->toBeNull()
        ->and($night->rsvpFor($first)?->status)->toBe(RsvpStatus::Going)
        ->and($night->rsvpFor($second)?->status)->toBe(RsvpStatus::Waitlist)
        ->and($night->waitlistPosition($second))->toBe(1);
});

test('leaving from the schedule works for the waitlist too', function () {
    $night = ClubNight::factory()->upcoming()->capacity(1)->withRsvps(1)->create();
    $member = User::factory()->create();
    ClubNightRsvp::factory()->for($night)->for($member)->waitlist()->create();

    Livewire::actingAs($member)
        ->test('pages::club-nights.index')
        ->call('leave', $night->id)
        ->assertDispatched('toast-show');

    expect($night->rsvpFor($member))->toBeNull()
        ->and($night->goingCount())->toBe(1);
});

test('past club nights cannot be RSVP\'d or left', function () {
    $night = ClubNight::factory()->past()->create();
    $member = User::factory()->create();
    $rsvp = ClubNightRsvp::factory()->for($night)->for($member)->going()->create();
    $newcomer = User::factory()->create();

    Livewire::actingAs($newcomer)
        ->test('pages::club-nights.show', ['clubNight' => $night])
        ->assertSee('RSVPs are closed')
        ->assertDontSee('I\'m coming')
        ->call('join')
        ->assertDispatched('toast-show');

    Livewire::actingAs($newcomer)->test('pages::club-nights.index')->call('join', $night->id);
    Livewire::actingAs($member)->test('pages::club-nights.show', ['clubNight' => $night])->call('leave');
    Livewire::actingAs($member)->test('pages::club-nights.index')->call('leave', $night->id);

    expect($night->rsvpFor($newcomer))->toBeNull()
        ->and($rsvp->fresh())->not->toBeNull();
});

test('members have to verify their email before they RSVP', function () {
    $night = ClubNight::factory()->upcoming()->create();
    $member = User::factory()->unverified()->create();

    Livewire::actingAs($member)
        ->test('pages::club-nights.show', ['clubNight' => $night])
        ->call('join')
        ->assertDispatched('toast-show');

    expect($night->rsvpFor($member))->toBeNull();
});

test('joining a night that no longer exists is handled', function () {
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test('pages::club-nights.index')
        ->call('join', 999999)
        ->assertDispatched('toast-show');

    expect(ClubNightRsvp::query()->count())->toBe(0);
});

test('the club night page lists friendlies played during the night', function () {
    $night = ClubNight::factory()->past()->create();
    $during = BadmintonMatch::factory()->completed()->create(['played_at' => $night->starts_at->addHour()]);
    $before = BadmintonMatch::factory()->completed()->create(['played_at' => $night->starts_at->subDay()]);

    $component = Livewire::test('pages::club-nights.show', ['clubNight' => $night]);

    expect($component->instance()->matches->pluck('id')->all())->toBe([$during->id])
        ->and($before->id)->not->toBe($during->id);

    $component->assertSee('Matches played');
});

test('the who\'s going list can be sorted by rating', function () {
    $night = ClubNight::factory()->upcoming()->create();
    $low = User::factory()->create(['name' => 'Anna Low', 'singles_rating' => 1400]);
    $high = User::factory()->create(['name' => 'Zoe High', 'singles_rating' => 1700]);
    ClubNightRsvp::factory()->for($night)->for($low)->create();
    ClubNightRsvp::factory()->for($night)->for($high)->create();

    $component = Livewire::test('pages::club-nights.show', ['clubNight' => $night]);
    expect($component->instance()->going->map->user_id->all())->toBe([$low->id, $high->id]);

    $component->set('sort', 'singles');
    expect($component->instance()->going->map->user_id->all())->toBe([$high->id, $low->id]);

    $component->set('sort', 'nonsense')->assertSet('sort', 'name');
});

test('the schedule and the night page do not run a query per club night or attendee', function () {
    $member = User::factory()->create();
    $queriesFor = function (callable $render): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $render();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $nights = ClubNight::factory()->count(3)->upcoming()->withRsvps(4, 1)->create();
    ClubNight::factory()->count(3)->past()->withRsvps(4)->create();

    $index = $queriesFor(fn () => $this->actingAs($member)->get(route('club-nights.index'))->assertOk());
    $show = $queriesFor(fn () => $this->actingAs($member)->get(route('club-nights.show', $nights->first()))->assertOk());

    ClubNight::factory()->count(6)->upcoming()->withRsvps(6, 2)->create();
    ClubNight::factory()->count(6)->past()->withRsvps(6)->create();
    ClubNightRsvp::factory()->count(10)->for($nights->first())->create();

    expect($queriesFor(fn () => $this->actingAs($member)->get(route('club-nights.index'))->assertOk()))->toBe($index)
        ->and($queriesFor(fn () => $this->actingAs($member)->get(route('club-nights.show', $nights->first()))->assertOk()))->toBe($show);
});
