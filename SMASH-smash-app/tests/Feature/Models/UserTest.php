<?php

use App\Enums\Discipline;
use App\Enums\Handedness;
use App\Enums\Programme;
use App\Enums\SkillLevel;
use App\Enums\UserRole;
use App\Models\BadmintonMatch;
use App\Models\ClubNight;
use App\Models\ClubNightRsvp;
use App\Models\Prediction;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

test('a newly registered user gets the player defaults', function () {
    $user = User::create(['name' => 'Sam de Vries', 'email' => 'sam@smash.test', 'password' => 'password']);
    $fresh = $user->fresh();

    expect($user->role)->toBe(UserRole::Member)
        ->and($fresh->role)->toBe(UserRole::Member)
        ->and($fresh->is_active)->toBeTrue()
        ->and($fresh->singles_rating)->toBe(1500.0)
        ->and($fresh->doubles_peak)->toBe(1500.0)
        ->and($fresh->doubles_played)->toBe(0)
        ->and($fresh->singles_wins)->toBe(0)
        ->and($fresh->programme)->toBeNull()
        ->and($fresh->level)->toBeNull()
        ->and($fresh->bio)->toBeNull();
});

test('player attributes are cast to enums and scalars', function () {
    $user = User::factory()
        ->admin()
        ->level(SkillLevel::Advanced)
        ->programme(Programme::Games)
        ->withRating(Discipline::Singles, 1612.25, 14, 9)
        ->create(['handedness' => Handedness::Left])
        ->fresh();

    expect($user->role)->toBe(UserRole::Admin)
        ->and($user->level)->toBe(SkillLevel::Advanced)
        ->and($user->programme)->toBe(Programme::Games)
        ->and($user->handedness)->toBe(Handedness::Left)
        ->and($user->is_active)->toBeTrue()
        ->and($user->singles_rating)->toBe(1612.25)
        ->and($user->singles_peak)->toBe(1612.25)
        ->and($user->singles_played)->toBe(14)
        ->and($user->singles_wins)->toBe(9)
        ->and($user->email_verified_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($user->rating(Discipline::Singles))->toBe(1612)
        ->and($user->losses(Discipline::Singles))->toBe(5);
});

test('the active and admins scopes', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $inactive = User::factory()->inactive()->create();

    expect(User::active()->pluck('id')->all())->toEqualCanonicalizing([$admin->id, $member->id])
        ->and(User::admins()->pluck('id')->all())->toBe([$admin->id])
        ->and(User::query()->active()->admins()->count())->toBe(1)
        ->and($inactive->fresh()->is_active)->toBeFalse();
});

test('ranked lists active players with matches, best rating first, then by name', function () {
    $bea = User::factory()->withRating(Discipline::Singles, 1600, 5)->create(['name' => 'Bea Bakker']);
    $anna = User::factory()->withRating(Discipline::Singles, 1600, 8)->create(['name' => 'Anna Aerts']);
    $top = User::factory()->withRating(Discipline::Singles, 1720.4, 20)->create();
    User::factory()->withRating(Discipline::Singles, 1800, 30)->inactive()->create();
    User::factory()->create();
    User::factory()->withRating(Discipline::Doubles, 1900, 10)->create();

    expect(User::ranked(Discipline::Singles)->pluck('id')->all())->toBe([$top->id, $anna->id, $bea->id])
        ->and(User::query()->ranked(Discipline::Singles, 6)->pluck('id')->all())->toBe([$top->id, $anna->id])
        ->and(User::ranked(Discipline::Doubles)->count())->toBe(1);
});

test('a user has matches, entries, RSVPs and predictions', function () {
    $user = User::factory()->create();
    $match = BadmintonMatch::factory()->withPlayers($user, User::factory()->create())->create();
    $entry = TournamentEntry::factory()->for(Tournament::factory()->doubles())->create(['player_id' => $user->id]);
    $partnered = TournamentEntry::factory()->for(Tournament::factory()->doubles())->withPartner($user)->create();
    $night = ClubNight::factory()->create();
    ClubNightRsvp::factory()->for($night)->for($user)->waitlist()->create();
    $prediction = Prediction::factory()->for($user)->create();

    expect($user->matchPlayers)->toHaveCount(1)
        ->and($user->matches)->toHaveCount(1)
        ->and($user->matches->first()?->is($match))->toBeTrue()
        ->and($user->matches->first()?->getRelation('pivot')->getAttribute('side'))->toBe('a')
        ->and($user->tournamentEntries->first()?->is($entry))->toBeTrue()
        ->and($user->partnerEntries->first()?->is($partnered))->toBeTrue()
        ->and($user->rsvps)->toHaveCount(1)
        ->and($user->clubNights->first()?->is($night))->toBeTrue()
        ->and($user->clubNights->first()?->getRelation('pivot')->getAttribute('status'))->toBe('waitlist')
        ->and($user->predictions->first()?->is($prediction))->toBeTrue();
});

test('deleting a user removes their participations, entries and RSVPs but keeps what they created', function () {
    $user = User::factory()->admin()->create();
    $match = BadmintonMatch::factory()->withPlayers($user, User::factory()->create())->create(['created_by' => $user->id]);
    $tournament = Tournament::factory()->create(['created_by' => $user->id]);
    TournamentEntry::factory()->for($tournament)->create(['player_id' => $user->id]);
    ClubNightRsvp::factory()->for($user)->create();

    $user->delete();

    expect($match->participants()->count())->toBe(1)
        ->and($match->fresh()?->created_by)->toBeNull()
        ->and($tournament->entries()->count())->toBe(0)
        ->and($tournament->fresh()?->created_by)->toBeNull()
        ->and(ClubNightRsvp::count())->toBe(0);
});
