<?php

use App\Actions\ClubNights\CheckInAttendee;
use App\Actions\ClubNights\JoinClubNight;
use App\Actions\ClubNights\LeaveClubNight;
use App\Actions\ClubNights\PromoteFromWaitlist;
use App\Enums\RsvpStatus;
use App\Models\ClubNight;
use App\Models\ClubNightRsvp;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

afterEach(fn () => CarbonImmutable::setTestNow());

test('members are going while there is room and waitlisted after that', function () {
    $night = ClubNight::factory()->upcoming()->capacity(2)->create();
    [$ann, $ben, $cas] = User::factory()->count(3)->create();

    $first = JoinClubNight::handle($night, $ann);
    $second = JoinClubNight::handle($night, $ben);
    $third = JoinClubNight::handle($night, $cas);

    expect([$first->status, $second->status, $third->status])->toBe([RsvpStatus::Going, RsvpStatus::Going, RsvpStatus::Waitlist])
        ->and($night->goingCount())->toBe(2)
        ->and($night->waitlistCount())->toBe(1);
});

test('joining twice changes nothing', function () {
    $night = ClubNight::factory()->upcoming()->capacity(1)->create();
    $ann = User::factory()->create();

    $first = JoinClubNight::handle($night, $ann);
    $again = JoinClubNight::handle($night, $ann);

    expect($again->id)->toBe($first->id)
        ->and($again->status)->toBe(RsvpStatus::Going)
        ->and(ClubNightRsvp::query()->count())->toBe(1);
});

test('a freed place goes to whoever joined the waitlist first', function () {
    $night = ClubNight::factory()->upcoming()->capacity(1)->create();
    [$ann, $ben, $cas] = User::factory()->count(3)->create();

    CarbonImmutable::setTestNow('2026-11-03 10:00:00');
    JoinClubNight::handle($night, $ann);
    CarbonImmutable::setTestNow('2026-11-03 10:05:00');
    JoinClubNight::handle($night, $ben);
    CarbonImmutable::setTestNow('2026-11-03 10:10:00');
    JoinClubNight::handle($night, $cas);

    LeaveClubNight::handle($night, $ann);

    expect($night->rsvpFor($ann))->toBeNull()
        ->and($night->rsvpFor($ben)?->status)->toBe(RsvpStatus::Going)
        ->and($night->rsvpFor($cas)?->status)->toBe(RsvpStatus::Waitlist)
        ->and($night->waitlistPosition($cas))->toBe(1);

    // Leaving from the waitlist frees no place; leaving twice is harmless.
    LeaveClubNight::handle($night, $cas);
    LeaveClubNight::handle($night, $cas);

    expect(ClubNightRsvp::query()->count())->toBe(1);
});

test('raising the capacity promotes the waitlist in order', function () {
    $night = ClubNight::factory()->upcoming()->capacity(1)->create();
    $users = User::factory()->count(4)->create();

    foreach ($users as $index => $user) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 10:00')->addMinutes($index));
        JoinClubNight::handle($night, $user);
    }

    $night->update(['capacity' => 3]);

    expect(PromoteFromWaitlist::handle($night))->toBe(2)
        ->and($night->rsvpFor($users[1])?->status)->toBe(RsvpStatus::Going)
        ->and($night->rsvpFor($users[2])?->status)->toBe(RsvpStatus::Going)
        ->and($night->rsvpFor($users[3])?->status)->toBe(RsvpStatus::Waitlist)
        ->and(PromoteFromWaitlist::handle($night))->toBe(0);
});

test('nobody jumps the waitlist when a place has opened up', function () {
    $night = ClubNight::factory()->upcoming()->capacity(1)->create();
    [$ann, $ben, $cas] = User::factory()->count(3)->create();
    JoinClubNight::handle($night, $ann);
    JoinClubNight::handle($night, $ben);
    $night->update(['capacity' => 2]);

    $late = JoinClubNight::handle($night, $cas);

    expect($late->status)->toBe(RsvpStatus::Waitlist)
        ->and($night->rsvpFor($ben)?->status)->toBe(RsvpStatus::Going);
});

test('nights that are over take no more RSVPs', function () {
    $night = ClubNight::factory()->past()->create();
    $ann = User::factory()->create();

    expect(fn () => JoinClubNight::handle($night, $ann))->toThrow(ValidationException::class)
        ->and(fn () => LeaveClubNight::handle($night, $ann))->toThrow(ValidationException::class);
});

test('checking in marks attendees present and waitlisted walk-ins as going', function () {
    CarbonImmutable::setTestNow('2026-11-03 19:42:00');
    $rsvp = ClubNightRsvp::factory()->waitlist()->create();

    CheckInAttendee::handle($rsvp);

    expect($rsvp->refresh()->checked_in_at?->toDateTimeString())->toBe('2026-11-03 19:42:00')
        ->and($rsvp->status)->toBe(RsvpStatus::Going);

    CarbonImmutable::setTestNow('2026-11-03 20:00:00');
    CheckInAttendee::handle($rsvp);

    expect($rsvp->refresh()->checked_in_at?->toDateTimeString())->toBe('2026-11-03 19:42:00');

    CheckInAttendee::handle($rsvp, present: false);

    expect($rsvp->refresh()->checked_in_at)->toBeNull()
        ->and($rsvp->status)->toBe(RsvpStatus::Going);
});
