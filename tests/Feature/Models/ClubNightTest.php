<?php

use App\Enums\ClubNightKind;
use App\Enums\RsvpStatus;
use App\Enums\SkillLevel;
use App\Models\ClubNight;
use App\Models\ClubNightRsvp;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

test('club nights cast kind, level and times and follow the kind in their title', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00'));

    $social = ClubNight::factory()->create(['starts_at' => CarbonImmutable::parse('2026-11-10 19:30')])->fresh();
    $clinic = ClubNight::factory()->clinic()->create(['starts_at' => CarbonImmutable::parse('2026-11-08 14:00')])->fresh();

    expect($social->kind)->toBe(ClubNightKind::Social)
        ->and($social->title)->toBe('Tuesday social')
        ->and($social->starts_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($social->ends_at->toDateTimeString())->toBe('2026-11-10 22:00:00')
        ->and($social->capacity)->toBe(24)
        ->and($social->courts)->toBe(4)
        ->and($social->level)->toBeNull()
        ->and($clinic->kind)->toBe(ClubNightKind::Clinic)
        ->and($clinic->level)->toBe(SkillLevel::Beginner)
        ->and($clinic->title)->toBe("Beginners' clinic");
});

test('going and waitlist counts drive spots left and fullness', function () {
    $night = ClubNight::factory()->capacity(3)->withRsvps(going: 2, waitlist: 0)->create();

    expect($night->goingCount())->toBe(2)
        ->and($night->waitlistCount())->toBe(0)
        ->and($night->spotsLeft())->toBe(1)
        ->and($night->isFull())->toBeFalse();

    ClubNightRsvp::factory()->for($night)->create();
    ClubNightRsvp::factory()->for($night)->waitlist()->count(2)->create();

    expect($night->goingCount())->toBe(3)
        ->and($night->waitlistCount())->toBe(2)
        ->and($night->spotsLeft())->toBe(0)
        ->and($night->isFull())->toBeTrue()
        ->and($night->load('rsvps')->goingCount())->toBe(3);
});

test('counts come from withRsvpCounts without extra queries', function () {
    ClubNight::factory()->withRsvps(going: 5, waitlist: 1)->create();
    ClubNight::factory()->create();

    $nights = ClubNight::query()->withRsvpCounts()->orderBy('id')->get();

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($nights[0]->goingCount())->toBe(5)
        ->and($nights[0]->waitlistCount())->toBe(1)
        ->and($nights[0]->spotsLeft())->toBe(19)
        ->and($nights[1]->goingCount())->toBe(0)
        ->and(DB::getQueryLog())->toBeEmpty();
});

test('a night is past once it has ended and ongoing while it runs', function () {
    $past = ClubNight::factory()->past()->create();
    $ongoing = ClubNight::factory()->ongoing()->create();
    $upcoming = ClubNight::factory()->upcoming()->create();

    expect($past->isPast())->toBeTrue()
        ->and($past->isOngoing())->toBeFalse()
        ->and($ongoing->isPast())->toBeFalse()
        ->and($ongoing->isOngoing())->toBeTrue()
        ->and($upcoming->isPast())->toBeFalse()
        ->and($upcoming->isOngoing())->toBeFalse();
});

test('upcoming and past scopes', function () {
    $nextWeek = ClubNight::factory()->create(['starts_at' => now()->addWeek()]);
    $tomorrow = ClubNight::factory()->create(['starts_at' => now()->addDay()]);
    $tonight = ClubNight::factory()->ongoing()->create();
    $lastWeek = ClubNight::factory()->create(['starts_at' => now()->subWeek()]);
    $yesterday = ClubNight::factory()->create(['starts_at' => now()->subDay()]);

    expect(ClubNight::upcoming()->pluck('id')->all())->toBe([$tonight->id, $tomorrow->id, $nextWeek->id])
        ->and(ClubNight::past()->pluck('id')->all())->toBe([$yesterday->id, $lastWeek->id]);
});

test('rsvpFor returns the member\'s RSVP and null for guests', function () {
    $night = ClubNight::factory()->create();
    $member = User::factory()->create();
    $rsvp = ClubNightRsvp::factory()->for($night)->for($member)->create();

    expect($night->rsvpFor(null))->toBeNull()
        ->and($night->rsvpFor($member)?->is($rsvp))->toBeTrue()
        ->and($night->rsvpFor(User::factory()->create()))->toBeNull()
        ->and($night->load('rsvps')->rsvpFor($member)?->is($rsvp))->toBeTrue();
});

test('the waitlist is first come, first served', function () {
    $night = ClubNight::factory()->capacity(1)->withRsvps(going: 1)->create();
    [$first, $second, $going] = User::factory()->count(3)->create()->all();

    // Inserted first but joined later, so the created_at order (not the id) decides.
    ClubNightRsvp::factory()->for($night)->for($first)->waitlist()->create(['created_at' => now()->subMinutes(5)]);
    ClubNightRsvp::factory()->for($night)->for($second)->waitlist()->create(['created_at' => now()->subMinutes(20)]);

    expect($night->waitlistPosition($second))->toBe(1)
        ->and($night->waitlistPosition($first))->toBe(2)
        ->and($night->waitlistPosition($going))->toBeNull()
        ->and($night->rsvps()->waitlisted()->pluck('user_id')->all())->toBe([$second->id, $first->id]);
});

test('attendees carry their RSVP status and check-in on the pivot', function () {
    $night = ClubNight::factory()->create();
    $present = User::factory()->create();
    ClubNightRsvp::factory()->for($night)->for($present)->checkedIn()->create();
    ClubNightRsvp::factory()->for($night)->waitlist()->create();

    $attendee = $night->attendees()->whereKey($present->id)->firstOrFail();
    $rsvp = $night->rsvpFor($present);

    expect($night->attendees)->toHaveCount(2)
        ->and($attendee->getRelation('pivot')->getAttribute('status'))->toBe(RsvpStatus::Going->value)
        ->and($attendee->getRelation('pivot')->getAttribute('checked_in_at'))->not->toBeNull()
        ->and($night->checkedInCount())->toBe(1)
        ->and($rsvp?->status)->toBe(RsvpStatus::Going)
        ->and($rsvp?->isGoing())->toBeTrue()
        ->and($rsvp?->isCheckedIn())->toBeTrue()
        ->and($rsvp?->checked_in_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($rsvp?->clubNight->is($night))->toBeTrue()
        ->and(ClubNightRsvp::going()->count())->toBe(1);
});

test('a member can RSVP to a night only once', function () {
    $rsvp = ClubNightRsvp::factory()->create();

    ClubNightRsvp::factory()->create(['club_night_id' => $rsvp->club_night_id, 'user_id' => $rsvp->user_id]);
})->throws(UniqueConstraintViolationException::class);
