<?php

use App\Enums\ClubNightKind;
use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\RsvpStatus;
use App\Enums\Side;
use App\Enums\SkillLevel;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Enums\UserRole;
use App\Models\BadmintonMatch;
use App\Models\ClubNight;
use App\Models\ClubNightRsvp;
use App\Models\MatchGame;
use App\Models\MatchPlayer;
use App\Models\Prediction;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

test('every model factory creates a valid row', function (string $model) {
    /** @var class-string<Model> $model */
    $instance = $model::factory()->create();

    expect($instance->exists)->toBeTrue()
        ->and($model::query()->whereKey($instance->getKey())->exists())->toBeTrue();
})->with([
    User::class,
    BadmintonMatch::class,
    MatchPlayer::class,
    MatchGame::class,
    Tournament::class,
    TournamentEntry::class,
    ClubNight::class,
    ClubNightRsvp::class,
    Prediction::class,
]);

test('user factory states', function () {
    expect(User::factory()->admin()->create()->role)->toBe(UserRole::Admin)
        ->and(User::factory()->create()->role)->toBe(UserRole::Member)
        ->and(User::factory()->inactive()->create()->is_active)->toBeFalse()
        ->and(User::factory()->level(SkillLevel::Competitive)->create()->level)->toBe(SkillLevel::Competitive)
        ->and(User::factory()->withBio()->create()->bio)->not->toBeEmpty()
        ->and(User::factory()->unverified()->create()->email_verified_at)->toBeNull();
});

test('match factory states', function () {
    expect(BadmintonMatch::factory()->singles()->create()->discipline)->toBe(Discipline::Singles)
        ->and(BadmintonMatch::factory()->doubles()->create()->discipline)->toBe(Discipline::Doubles)
        ->and(BadmintonMatch::factory()->scheduled()->create()->scheduled_at?->isFuture())->toBeTrue()
        ->and(BadmintonMatch::factory()->inProgress()->create()->status)->toBe(MatchStatus::InProgress)
        ->and(BadmintonMatch::factory()->walkover(Side::B)->create()->winning_side)->toBe(Side::B)
        ->and(BadmintonMatch::factory()->cancelled()->create()->status)->toBe(MatchStatus::Cancelled)
        ->and(BadmintonMatch::factory()->unrated()->create()->is_rated)->toBeFalse();

    $completed = BadmintonMatch::factory()->state(['best_of' => 5, 'points_per_game' => 15])->completed(Side::B)->create();

    expect($completed->games)->toHaveCount(3)
        ->and($completed->games->every(fn (MatchGame $game) => $game->isComplete(15) && $game->winner() === Side::B))->toBeTrue()
        ->and($completed->played_at?->isPast())->toBeTrue()
        ->and($completed->scoreline())->toBe('0–3');
});

test('tournament factory states', function () {
    expect(Tournament::factory()->open()->create()->status)->toBe(TournamentStatus::Open)
        ->and(Tournament::factory()->draft()->create()->status)->toBe(TournamentStatus::Draft)
        ->and(Tournament::factory()->inProgress()->create()->status)->toBe(TournamentStatus::InProgress)
        ->and(Tournament::factory()->completed()->create()->starts_at->isPast())->toBeTrue()
        ->and(Tournament::factory()->doubles()->create()->discipline)->toBe(Discipline::Doubles)
        ->and(Tournament::factory()->singles()->create()->discipline)->toBe(Discipline::Singles)
        ->and(Tournament::factory()->roundRobin()->create()->format)->toBe(TournamentFormat::RoundRobin)
        ->and(Tournament::factory()->knockout()->create()->format)->toBe(TournamentFormat::SingleElimination);

    $open = Tournament::factory()->open()->create();

    expect($open->registration_closes_at?->lessThan($open->starts_at))->toBeTrue()
        ->and($open->starts_at->isFuture())->toBeTrue();

    $doubles = Tournament::factory()->doubles()->withEntries(3)->create();

    expect($doubles->entries)->toHaveCount(3)
        ->and($doubles->entries->every(fn (TournamentEntry $entry) => $entry->partner_id !== null))->toBeTrue();
});

test('club night and RSVP factory states', function () {
    expect(ClubNight::factory()->upcoming()->create()->starts_at->isFuture())->toBeTrue()
        ->and(ClubNight::factory()->past()->create()->isPast())->toBeTrue()
        ->and(ClubNight::factory()->kind(ClubNightKind::Ladder)->create()->title)->toBe('Ladder night')
        ->and(ClubNightRsvp::factory()->waitlist()->create()->status)->toBe(RsvpStatus::Waitlist)
        ->and(ClubNightRsvp::factory()->checkedIn()->create()->isCheckedIn())->toBeTrue();
});

test('the migrations create every SMASH table and player column', function () {
    foreach (['tournaments', 'tournament_entries', 'matches', 'match_players', 'match_games', 'club_nights', 'club_night_rsvps', 'predictions'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    expect(Schema::hasColumns('users', [
        'role', 'programme', 'level', 'handedness', 'bio', 'is_active',
        'singles_rating', 'doubles_rating', 'singles_peak', 'doubles_peak',
        'singles_played', 'doubles_played', 'singles_wins', 'doubles_wins',
    ]))->toBeTrue();
});
