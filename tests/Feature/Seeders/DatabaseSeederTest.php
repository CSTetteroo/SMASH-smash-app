<?php

use App\Enums\MatchStatus;
use App\Enums\TournamentStatus;
use App\Enums\UserRole;
use App\Models\BadmintonMatch;
use App\Models\ClubNight;
use App\Models\Prediction;
use App\Models\RatingAdjustment;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Rating\RatingEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;

/*
 * One `php artisan migrate:fresh --seed` must build the complete demo world that the QA notes
 * (SEED-NOTES.md) describe. The world is anchored on "now", so the clock is frozen on a fixed day.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 18:00:00'));
    $this->seed();
});

test('the seeder builds the members and both demo accounts', function () {
    expect(User::query()->count())->toBe(66)
        ->and(User::query()->where('is_active', true)->count())->toBe(63)
        ->and(User::query()->where('is_active', false)->count())->toBe(3)
        ->and(User::query()->where('email', 'not like', '%@smash.test')->count())->toBe(0);

    $admin = User::query()->where('email', 'admin@smash.test')->sole();
    $member = User::query()->where('email', 'member@smash.test')->sole();

    expect($admin->id)->toBe(1)
        ->and($admin->role)->toBe(UserRole::Admin)
        ->and($member->id)->toBe(2)
        ->and($member->role)->toBe(UserRole::Member);

    foreach ([$admin, $member] as $account) {
        expect(Hash::check('password', $account->password))->toBeTrue()
            ->and($account->email_verified_at)->not->toBeNull()
            ->and($account->is_active)->toBeTrue()
            ->and($account->singles_played)->toBeGreaterThan(0)
            ->and($account->doubles_played)->toBeGreaterThan(0);
    }
});

test('the seeder builds the season of matches with exactly one live match', function () {
    $byStatus = BadmintonMatch::query()->toBase()
        ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
        ->map(fn ($total): int => (int) $total)->all();

    expect($byStatus)->toEqual([
        MatchStatus::Cancelled->value => 1,
        MatchStatus::Completed->value => 259,
        MatchStatus::InProgress->value => 1,
        MatchStatus::Scheduled->value => 12,
        MatchStatus::Walkover->value => 9,
    ]);

    $live = BadmintonMatch::query()->live()->with('participants')->sole();

    expect($live->isFriendly())->toBeTrue()
        ->and($live->participants)->toHaveCount(2)
        ->and($live->games()->count())->toBeGreaterThan(0);

    expect(BadmintonMatch::query()->whereNull('tournament_id')->count())->toBe(221)
        ->and(BadmintonMatch::query()->whereNotNull('tournament_id')->count())->toBe(61)
        ->and(Prediction::query()->count())->toBeGreaterThan(0);
});

test('the seeder builds one tournament per stage and the club nights', function () {
    $tournaments = Tournament::query()->orderBy('id')->get();

    expect($tournaments->pluck('slug')->all())->toBe([
        'welcome-week-open', 'autumn-doubles-cup', 'halloween-smash', 'winter-doubles-showdown', 'new-year-ladder-classic',
    ])->and($tournaments->pluck('status')->all())->toBe([
        TournamentStatus::Completed, TournamentStatus::Completed, TournamentStatus::InProgress,
        TournamentStatus::Open, TournamentStatus::Draft,
    ]);

    expect(ClubNight::query()->count())->toBe(37)
        ->and(ClubNight::query()->upcoming()->count())->toBe(14)
        ->and(ClubNight::query()->upcoming()->withRsvpCounts()->get()->filter->isFull()->isNotEmpty())->toBeTrue();
});

test('the seeder applies the rating adjustments and leaves ratings fully recalculated', function () {
    expect(RatingAdjustment::query()->count())->toBe(4);

    $columns = ['id', 'singles_rating', 'doubles_rating', 'singles_peak', 'doubles_peak', 'singles_played', 'doubles_played', 'singles_wins', 'doubles_wins'];
    $stored = User::query()->orderBy('id')->get($columns)->toArray();

    RatingEngine::recalculate();

    // The final recalculation already ran: a fresh one changes nothing.
    expect(User::query()->orderBy('id')->get($columns)->toArray())->toEqual($stored);

    $arne = User::query()->where('name', 'Arne Maes')->sole();

    expect(round($arne->singles_rating))->toBeGreaterThan(1600.0);
});
