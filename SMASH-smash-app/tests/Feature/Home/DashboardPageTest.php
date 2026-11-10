<?php

use App\Actions\Tournaments\RegisterForTournament;
use App\Enums\Discipline;
use App\Enums\RsvpStatus;
use App\Models\ClubNight;
use App\Models\Tournament;
use App\Models\User;
use Livewire\Livewire;

require_once __DIR__.'/HomeHelpers.php';

test('guests are sent to log in and unverified members to verify first', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->unverified()->create());
    $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});

test('the dashboard greets the member and shows their ratings', function () {
    $this->travelTo(now()->setTime(9, 15));

    $lotte = User::factory()->create(['name' => 'Lotte Jansen']);
    $wei = User::factory()->create(['name' => 'Wei Chen']);
    homePlay([$lotte], [$wei]);
    homePlay([$lotte], [$wei], [[21, 19], [19, 21], [21, 17]]);
    $lotte->refresh();

    $this->actingAs($lotte);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Good morning, Lotte')
        ->assertSee('Your ratings')
        ->assertSee((string) $lotte->rating(Discipline::Singles))
        ->assertSee('#1')
        ->assertSee('Recent results')
        ->assertSee('Wei Chen')
        ->assertDontSee('Welcome to the club');
});

test('a brand-new member gets a welcome instead of empty stats', function () {
    $this->travelTo(now()->setTime(20, 0));
    $this->actingAs(User::factory()->create(['name' => 'Bence Toth']));

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Good evening, Bence')
        ->assertSee('Welcome to the club, Bence.')
        ->assertSee('RSVP for a club night')
        ->assertSee('Nothing scheduled')
        ->assertSee('No results yet')
        ->assertDontSee('Committee');
});

test('upcoming matches show the model forecast and what is at stake', function () {
    $lotte = User::factory()->create(['name' => 'Lotte Jansen']);
    $wei = User::factory()->create(['name' => 'Wei Chen']);
    homeFixture($lotte, $wei);

    $this->actingAs($lotte);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Coming up')
        ->assertSee('Wei Chen')
        ->assertSee('At stake for you:');
});

test('the dashboard lists my tournaments with my status', function () {
    $lotte = User::factory()->create(['name' => 'Lotte Jansen']);
    $tournament = Tournament::factory()->open()->singles()->create(['name' => 'Halloween Smash']);
    RegisterForTournament::handle($tournament, $lotte);
    Tournament::factory()->open()->singles()->create(['name' => 'Winter Open']);

    $this->actingAs($lotte);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Your tournaments')
        ->assertSee('Halloween Smash')
        ->assertSee('You\'re in. The draw is made when registration closes.')
        ->assertSee('View your entry')
        ->assertSee('Still open for registration:')
        ->assertSee('Winter Open');
});

test('members can join and leave the next club night from the dashboard', function () {
    $night = ClubNight::factory()->upcoming()->capacity(1)->create();
    $lotte = User::factory()->create();
    $wei = User::factory()->create();

    Livewire::actingAs($lotte)->test('pages::dashboard')
        ->assertSee('I\'m coming')
        ->call('join', $night->id)
        ->assertHasNoErrors();

    expect($night->rsvpFor($lotte)?->status)->toBe(RsvpStatus::Going);

    Livewire::actingAs($wei)->test('pages::dashboard')
        ->assertSee('Join the waitlist')
        ->call('join', $night->id);

    expect($night->rsvpFor($wei)?->status)->toBe(RsvpStatus::Waitlist);

    Livewire::actingAs($lotte)->test('pages::dashboard')
        ->assertSee('Can\'t make it')
        ->call('leave', $night->id);

    expect($night->rsvpFor($lotte))->toBeNull()
        ->and($night->rsvpFor($wei)?->status)->toBe(RsvpStatus::Going);
});

test('rsvps for a club night that is over are refused', function () {
    $night = ClubNight::factory()->past()->create();
    $lotte = User::factory()->create();

    Livewire::actingAs($lotte)->test('pages::dashboard')->call('join', $night->id);

    expect($night->rsvpFor($lotte))->toBeNull();
});

test('joining an unknown club night is a 404', function () {
    Livewire::actingAs(User::factory()->create())->test('pages::dashboard')
        ->call('join', 999999)
        ->assertNotFound();
});

test('only admins see the committee card with attention counts', function () {
    ClubNight::factory()->upcoming()->capacity(2)->withRsvps(2, 1)->create(['starts_at' => now()->addDays(2)->setTime(19, 30)]);

    $this->actingAs(User::factory()->member()->create());
    $this->get(route('dashboard'))->assertOk()->assertDontSee('Admin overview');

    $this->actingAs(User::factory()->admin()->create());
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Admin overview')
        ->assertSee(route('admin.index'), false)
        ->assertSee('1 full club night this week');
});
