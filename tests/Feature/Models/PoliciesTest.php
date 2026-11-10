<?php

use App\Models\BadmintonMatch;
use App\Models\ClubNight;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use App\Policies\BadmintonMatchPolicy;
use App\Policies\ClubNightPolicy;
use App\Policies\TournamentPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;

/**
 * Whether $user (null = guest) may perform $ability.
 *
 * @param  array<int, mixed>|mixed  $arguments
 */
function modelsSpecCan(?User $user, string $ability, mixed $arguments = []): bool
{
    return Gate::forUser($user)->allows($ability, $arguments);
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->member = User::factory()->create();
});

test('policies are discovered for every model', function () {
    expect(Gate::getPolicyFor(BadmintonMatch::class))->toBeInstanceOf(BadmintonMatchPolicy::class)
        ->and(Gate::getPolicyFor(Tournament::class))->toBeInstanceOf(TournamentPolicy::class)
        ->and(Gate::getPolicyFor(ClubNight::class))->toBeInstanceOf(ClubNightPolicy::class)
        ->and(Gate::getPolicyFor(User::class))->toBeInstanceOf(UserPolicy::class);
});

test('only admins may manage', function () {
    expect(modelsSpecCan($this->admin, 'manage'))->toBeTrue()
        ->and(modelsSpecCan($this->member, 'manage'))->toBeFalse()
        ->and(modelsSpecCan(null, 'manage'))->toBeFalse();
});

test('matches: viewing is public, members log, admins edit', function () {
    $friendly = BadmintonMatch::factory()->completed()->create();
    $tournamentMatch = BadmintonMatch::factory()->inTournament(Tournament::factory()->create())->create();

    expect(modelsSpecCan(null, 'viewAny', BadmintonMatch::class))->toBeTrue()
        ->and(modelsSpecCan(null, 'view', $friendly))->toBeTrue()
        ->and(modelsSpecCan(null, 'create', BadmintonMatch::class))->toBeFalse()
        ->and(modelsSpecCan($this->member, 'create', BadmintonMatch::class))->toBeTrue()
        ->and(modelsSpecCan($this->member, 'update', $friendly))->toBeFalse()
        ->and(modelsSpecCan($this->admin, 'update', $friendly))->toBeTrue()
        ->and(modelsSpecCan($this->member, 'delete', $friendly))->toBeFalse()
        ->and(modelsSpecCan($this->admin, 'delete', $friendly))->toBeTrue()
        ->and(modelsSpecCan($this->admin, 'delete', $tournamentMatch))->toBeFalse()
        ->and(modelsSpecCan($this->admin, 'update', $tournamentMatch))->toBeTrue();
});

test('live scoring: admins always, participants until the match is decided', function () {
    $opponent = User::factory()->create();
    $scheduled = BadmintonMatch::factory()->withPlayers($this->member, $opponent)->create();
    $live = BadmintonMatch::factory()->inProgress()->withPlayers($this->member, $opponent)->create();
    $finished = BadmintonMatch::factory()->completed()->withPlayers($this->member, $opponent)->create();
    $cancelled = BadmintonMatch::factory()->cancelled()->withPlayers($this->member, $opponent)->create();
    $someoneElses = BadmintonMatch::factory()->inProgress()->withRandomPlayers()->create();

    expect(modelsSpecCan($this->member, 'score', $scheduled))->toBeTrue()
        ->and(modelsSpecCan($this->member, 'score', $live))->toBeTrue()
        ->and(modelsSpecCan($opponent, 'score', $live))->toBeTrue()
        ->and(modelsSpecCan($this->member, 'score', $finished))->toBeFalse()
        ->and(modelsSpecCan($this->member, 'score', $cancelled))->toBeFalse()
        ->and(modelsSpecCan($this->member, 'score', $someoneElses))->toBeFalse()
        ->and(modelsSpecCan(null, 'score', $live))->toBeFalse()
        ->and(modelsSpecCan($this->admin, 'score', $someoneElses))->toBeTrue()
        ->and(modelsSpecCan($this->admin, 'score', $finished))->toBeTrue();
});

test('pick\'em: members who do not play, before a scheduled match starts', function () {
    $upcoming = BadmintonMatch::factory()->scheduled()->withRandomPlayers()->create();
    $unscheduled = BadmintonMatch::factory()->withRandomPlayers()->create(['scheduled_at' => null]);
    $overdue = BadmintonMatch::factory()->withRandomPlayers()->create(['scheduled_at' => now()->subMinute()]);
    $mine = BadmintonMatch::factory()->scheduled()->withPlayers($this->member, User::factory()->create())->create();
    $live = BadmintonMatch::factory()->inProgress()->withRandomPlayers()->create();
    $finished = BadmintonMatch::factory()->completed()->create();

    expect(modelsSpecCan($this->member, 'predict', $upcoming))->toBeTrue()
        ->and(modelsSpecCan($this->admin, 'predict', $upcoming))->toBeTrue()
        ->and(modelsSpecCan($this->member, 'predict', $unscheduled))->toBeTrue()
        ->and(modelsSpecCan($this->member, 'predict', $overdue))->toBeFalse()
        ->and(modelsSpecCan($this->member, 'predict', $mine))->toBeFalse()
        ->and(modelsSpecCan($this->member, 'predict', $live))->toBeFalse()
        ->and(modelsSpecCan($this->member, 'predict', $finished))->toBeFalse()
        ->and(modelsSpecCan(null, 'predict', $upcoming))->toBeFalse();
});

test('tournaments: drafts are admin-only, admins manage, members register while open', function () {
    $draft = Tournament::factory()->draft()->create();
    $open = Tournament::factory()->open()->create();
    $closed = Tournament::factory()->open()->registrationClosed()->create();
    $full = Tournament::factory()->open()->maxEntries(1)->withEntries(1)->create();

    expect(modelsSpecCan(null, 'viewAny', Tournament::class))->toBeTrue()
        ->and(modelsSpecCan(null, 'view', $open))->toBeTrue()
        ->and(modelsSpecCan(null, 'view', $draft))->toBeFalse()
        ->and(modelsSpecCan($this->member, 'view', $draft))->toBeFalse()
        ->and(modelsSpecCan($this->admin, 'view', $draft))->toBeTrue();

    foreach (['update', 'delete', 'manageDraw'] as $ability) {
        expect(modelsSpecCan($this->admin, $ability, $open))->toBeTrue()
            ->and(modelsSpecCan($this->member, $ability, $open))->toBeFalse()
            ->and(modelsSpecCan(null, $ability, $open))->toBeFalse();
    }

    expect(modelsSpecCan($this->admin, 'create', Tournament::class))->toBeTrue()
        ->and(modelsSpecCan($this->member, 'create', Tournament::class))->toBeFalse()
        ->and(modelsSpecCan($this->member, 'register', $open))->toBeTrue()
        ->and(modelsSpecCan(null, 'register', $open))->toBeFalse()
        ->and(modelsSpecCan($this->member, 'register', $closed))->toBeFalse()
        ->and(modelsSpecCan($this->member, 'register', $full))->toBeFalse()
        ->and(modelsSpecCan($this->member, 'register', $draft))->toBeFalse();
});

test('club nights: public, admin-managed, members RSVP until the night is over', function () {
    $upcoming = ClubNight::factory()->upcoming()->create();
    $ongoing = ClubNight::factory()->ongoing()->create();
    $past = ClubNight::factory()->past()->create();

    expect(modelsSpecCan(null, 'viewAny', ClubNight::class))->toBeTrue()
        ->and(modelsSpecCan(null, 'view', $past))->toBeTrue()
        ->and(modelsSpecCan($this->member, 'rsvp', $upcoming))->toBeTrue()
        ->and(modelsSpecCan($this->member, 'rsvp', $ongoing))->toBeTrue()
        ->and(modelsSpecCan($this->member, 'rsvp', $past))->toBeFalse()
        ->and(modelsSpecCan(null, 'rsvp', $upcoming))->toBeFalse()
        ->and(modelsSpecCan($this->admin, 'create', ClubNight::class))->toBeTrue()
        ->and(modelsSpecCan($this->member, 'create', ClubNight::class))->toBeFalse();

    foreach (['update', 'delete', 'checkIn'] as $ability) {
        expect(modelsSpecCan($this->admin, $ability, $upcoming))->toBeTrue()
            ->and(modelsSpecCan($this->member, $ability, $upcoming))->toBeFalse()
            ->and(modelsSpecCan(null, $ability, $upcoming))->toBeFalse();
    }
});

test('players: public profiles, admins manage, nobody deletes themselves', function () {
    $player = User::factory()->create();

    expect(modelsSpecCan(null, 'viewAny', User::class))->toBeTrue()
        ->and(modelsSpecCan(null, 'view', $player))->toBeTrue()
        ->and(modelsSpecCan($this->admin, 'create', User::class))->toBeTrue()
        ->and(modelsSpecCan($this->member, 'create', User::class))->toBeFalse()
        ->and(modelsSpecCan($this->admin, 'update', $player))->toBeTrue()
        ->and(modelsSpecCan($this->member, 'update', $player))->toBeFalse()
        ->and(modelsSpecCan($this->member, 'update', $this->member))->toBeFalse()
        ->and(modelsSpecCan($this->admin, 'delete', $player))->toBeTrue()
        ->and(modelsSpecCan($this->admin, 'delete', $this->admin))->toBeFalse()
        ->and(modelsSpecCan($this->member, 'delete', $player))->toBeFalse();
});

test('a tournament entry does not make a member a match participant before the draw', function () {
    $tournament = Tournament::factory()->open()->create();
    TournamentEntry::factory()->for($tournament)->create(['player_id' => $this->member->id]);
    $match = BadmintonMatch::factory()->inTournament($tournament)->create();

    expect(modelsSpecCan($this->member, 'score', $match))->toBeFalse()
        ->and(modelsSpecCan($this->member, 'predict', $match))->toBeTrue();
});
