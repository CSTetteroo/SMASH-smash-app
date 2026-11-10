<?php

use App\Enums\Discipline;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\RatingAdjustment;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RatingAdjustmentSeeder;
use Tests\Feature\Domain\Support\Play;

beforeEach(function () {
    Play::reset('2026-10-31 10:00:00');
    $this->travelTo(CarbonImmutable::parse('2026-11-04 18:00:00'));
});

/**
 * Just enough of the demo world for the seeder: its named players, two admins and a tournament walkover.
 */
function seederWorld(): array
{
    $chair = User::factory()->admin()->create(['name' => 'Noor van Dijk']);
    User::factory()->admin()->create(['name' => 'Daan Visser']);
    $arne = User::factory()->create(['name' => 'Arne Maes']);
    $emre = User::factory()->create(['name' => 'Emre Yılmaz']);
    $tom = User::factory()->create(['name' => 'Tom Peeters', 'created_at' => '2026-10-31 13:17:00']);
    $winner = User::factory()->create();
    $noShow = User::factory()->create(['name' => 'Femke Willems']);

    $first = Play::win(User::factory()->create(), $arne);  // 2026-10-31 11:00
    Play::win($emre, User::factory()->create());

    $tournament = Tournament::factory()->singles()->inProgress()->create(['name' => 'Halloween Smash']);
    $entryA = TournamentEntry::factory()->for($tournament)->for($winner, 'player')->create();
    $entryB = TournamentEntry::factory()->for($tournament)->for($noShow, 'player')->create();
    BadmintonMatch::factory()->inTournament($tournament)->betweenEntries($entryA, $entryB)->walkover(Side::A)
        ->create(['scheduled_at' => '2026-11-01 10:00:00']);

    return compact('chair', 'arne', 'emre', 'tom', 'noShow', 'first');
}

test('the seeder adds placements, a correction and a penalty, then recalculates', function () {
    $world = seederWorld();
    $arneBefore = $world['arne']->refresh()->singles_rating;

    $this->seed(RatingAdjustmentSeeder::class);

    $log = RatingAdjustment::query()->with('user')->inReplayOrder()->get()->keyBy(fn (RatingAdjustment $a): string => $a->user->name);

    expect($log)->toHaveCount(4)
        ->and($log['Arne Maes']->effective_at->format('Y-m-d H:i:s'))->toBe('2026-10-31 10:59:00')
        ->and($log['Arne Maes']->amount)->toBe(120.0)
        ->and($log['Arne Maes']->created_by)->toBe($world['chair']->id)
        ->and($log['Emre Yılmaz']->amount)->toBe(16.0)
        ->and($log['Emre Yılmaz']->wasEdited())->toBeTrue()
        ->and($log['Femke Willems']->amount)->toBe(-15.0)
        ->and($log['Femke Willems']->effective_at->format('Y-m-d H:i'))->toBe('2026-11-01 10:00')
        ->and($log['Femke Willems']->reason)->toContain('Halloween Smash')
        ->and($log['Tom Peeters']->discipline)->toBe(Discipline::Doubles)
        ->and($log->every(fn (RatingAdjustment $a): bool => mb_strlen($a->reason) <= RatingAdjustment::REASON_LENGTH))->toBeTrue()
        ->and($log->every(fn (RatingAdjustment $a): bool => $a->effective_at->lessThanOrEqualTo(now()) && $a->created_at->lessThanOrEqualTo(now())))->toBeTrue();

    // Ratings were recalculated: the placement counted in Arne's first match.
    expect($world['first']->participantFor($world['arne'])?->refresh()->rating_before)->toBe(1620.0)
        ->and($world['arne']->refresh()->singles_rating)->toBeGreaterThan($arneBefore)
        ->and($world['tom']->refresh()->doubles_rating)->toBe(1560.0)
        ->and($world['noShow']->refresh()->singles_rating)->toBe(1485.0);
});

test('running the seeder again changes nothing', function () {
    seederWorld();
    $this->seed(RatingAdjustmentSeeder::class);
    $ids = RatingAdjustment::query()->orderBy('id')->pluck('id')->all();

    $this->seed(RatingAdjustmentSeeder::class);

    expect(RatingAdjustment::query()->orderBy('id')->pluck('id')->all())->toBe($ids);
});

test('players the seeder cannot find are skipped', function () {
    User::factory()->admin()->create();

    $this->seed(RatingAdjustmentSeeder::class);

    expect(RatingAdjustment::query()->count())->toBe(0);
});
