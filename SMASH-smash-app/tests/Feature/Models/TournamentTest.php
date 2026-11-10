<?php

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\BadmintonMatch;
use App\Models\MatchGame;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A finished round robin match between two entries with the given game scores (side A first).
 *
 * @param  list<array{0: int, 1: int}>  $scores
 */
function modelsSpecRoundRobinResult(Tournament $tournament, int $matchday, TournamentEntry $a, TournamentEntry $b, array $scores): BadmintonMatch
{
    $match = BadmintonMatch::factory()->inTournament($tournament, $matchday, null)->betweenEntries($a, $b)->create();

    foreach ($scores as $index => [$pointsA, $pointsB]) {
        MatchGame::factory()->for($match, 'match')->number($index + 1)->score($pointsA, $pointsB)->create();
    }

    $won = collect($scores)->filter(fn (array $game) => $game[0] > $game[1])->count();

    $match->update([
        'status' => MatchStatus::Completed,
        'winning_side' => $won * 2 > count($scores) ? Side::A : Side::B,
        'played_at' => now(),
    ]);

    return $match;
}

test('slugs are generated from the name and kept unique', function () {
    $first = Tournament::factory()->create(['name' => 'Halloween Smash']);
    $second = Tournament::factory()->create(['name' => 'Halloween Smash']);
    $third = Tournament::factory()->create(['name' => 'Halloween smash!']);

    expect([$first->slug, $second->slug, $third->slug])
        ->toBe(['halloween-smash', 'halloween-smash-2', 'halloween-smash-3']);
});

test('a slug never shadows the create route', function () {
    expect(Tournament::factory()->create(['name' => 'Create'])->slug)->toBe('create-2')
        ->and(Tournament::factory()->create(['name' => 'Ladder', 'slug' => 'create'])->slug)->toBe('create-3');
});

test('an explicit free slug is kept and slugs do not change on rename', function () {
    $tournament = Tournament::factory()->create(['name' => 'Welcome Week Open', 'slug' => 'welcome-week-open-2026']);
    $tournament->update(['name' => 'Welcome Week Open (rescheduled)']);

    expect($tournament->fresh()?->slug)->toBe('welcome-week-open-2026')
        ->and(Tournament::uniqueSlug('Welcome Week Open 2026'))->toBe('welcome-week-open-2026-2')
        ->and(Tournament::uniqueSlug('Welcome Week Open 2026', $tournament->id))->toBe('welcome-week-open-2026')
        ->and(Tournament::uniqueSlug('!!!'))->toBe('tournament');
});

test('tournaments are routed by slug', function () {
    $tournament = Tournament::factory()->create(['name' => 'Winter Doubles Showdown']);

    expect($tournament->getRouteKeyName())->toBe('slug')
        ->and(route('tournaments.show', $tournament))->toEndWith('/tournaments/winter-doubles-showdown')
        ->and((new Tournament)->resolveRouteBinding('winter-doubles-showdown')?->is($tournament))->toBeTrue();
});

test('tournament columns are cast', function () {
    $tournament = Tournament::factory()->doubles()->roundRobin()->draft()->create()->fresh();

    expect($tournament->discipline)->toBe(Discipline::Doubles)
        ->and($tournament->format)->toBe(TournamentFormat::RoundRobin)
        ->and($tournament->status)->toBe(TournamentStatus::Draft)
        ->and($tournament->starts_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($tournament->registration_closes_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($tournament->is_rated)->toBeTrue()
        ->and($tournament->max_entries)->toBe(16);
});

test('a new tournament defaults to a rated draft, best of three to 21', function () {
    $tournament = Tournament::create([
        'name' => 'Committee Cup',
        'discipline' => Discipline::Singles,
        'format' => TournamentFormat::SingleElimination,
        'starts_at' => now()->addMonth(),
    ]);

    expect($tournament->status)->toBe(TournamentStatus::Draft)
        ->and($tournament->best_of)->toBe(3)
        ->and($tournament->points_per_game)->toBe(21)
        ->and($tournament->is_rated)->toBeTrue()
        ->and($tournament->slug)->toBe('committee-cup');
});

test('registration is open while open, before the deadline and not full', function () {
    $open = Tournament::factory()->open()->maxEntries(2)->create();

    expect($open->isOpenForRegistration())->toBeTrue()
        ->and(Tournament::factory()->draft()->create()->isOpenForRegistration())->toBeFalse()
        ->and(Tournament::factory()->inProgress()->create()->isOpenForRegistration())->toBeFalse()
        ->and(Tournament::factory()->completed()->create()->isOpenForRegistration())->toBeFalse()
        ->and(Tournament::factory()->open()->registrationClosed()->create()->isOpenForRegistration())->toBeFalse();

    TournamentEntry::factory()->count(2)->for($open)->create();

    expect($open->isFull())->toBeTrue()
        ->and($open->spotsLeft())->toBe(0)
        ->and($open->isOpenForRegistration())->toBeFalse();
});

test('without a deadline registration closes when the tournament starts', function () {
    $soon = Tournament::factory()->open()->create(['registration_closes_at' => null, 'starts_at' => now()->addHour()]);
    $started = Tournament::factory()->open()->create(['registration_closes_at' => null, 'starts_at' => now()->subHour()]);

    expect($soon->registrationDeadline()?->equalTo($soon->starts_at))->toBeTrue()
        ->and($soon->isOpenForRegistration())->toBeTrue()
        ->and($started->isOpenForRegistration())->toBeFalse();
});

test('spots left counts entries and is null without a maximum', function () {
    $tournament = Tournament::factory()->maxEntries(4)->withEntries(3)->create();
    $unlimited = Tournament::factory()->maxEntries(null)->withEntries(5)->create();

    expect($tournament->spotsLeft())->toBe(1)
        ->and($tournament->isFull())->toBeFalse()
        ->and($unlimited->spotsLeft())->toBeNull()
        ->and($unlimited->isFull())->toBeFalse()
        ->and($unlimited->entryCount())->toBe(5);

    $counted = Tournament::query()->withCount('entries')->findOrFail($tournament->id);

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($counted->spotsLeft())->toBe(1)
        ->and(DB::getQueryLog())->toBeEmpty();
});

test('hasDraw and totalRounds come from the matches once a draw exists', function () {
    $tournament = Tournament::factory()->knockout()->withEntries(6)->create();

    expect($tournament->hasDraw())->toBeFalse()
        ->and($tournament->totalRounds())->toBe(3);

    BadmintonMatch::factory()->inTournament($tournament, 4, 1)->create();

    $fresh = $tournament->fresh();

    expect($fresh?->hasDraw())->toBeTrue()
        ->and($fresh?->totalRounds())->toBe(4)
        ->and(Tournament::query()->withCount('matches')->findOrFail($tournament->id)->hasDraw())->toBeTrue()
        ->and(Tournament::factory()->roundRobin()->withEntries(6)->create()->totalRounds())->toBe(5);
});

test('the knockout champion and runner-up come from the final', function () {
    $tournament = Tournament::factory()->knockout()->inProgress()->create();
    [$first, $second, $third, $fourth] = TournamentEntry::factory()->count(4)->for($tournament)->create()->all();

    BadmintonMatch::factory()->inTournament($tournament, 1, 1)->betweenEntries($first, $fourth)->completed(Side::A)->create();
    BadmintonMatch::factory()->inTournament($tournament, 1, 2)->betweenEntries($second, $third)->completed(Side::B)->create();
    $final = BadmintonMatch::factory()->inTournament($tournament, 2, 1)->betweenEntries($first, $third)->create();

    expect($tournament->champion())->toBeNull()
        ->and($tournament->runnerUp())->toBeNull()
        ->and($tournament->progress())->toBe(2 / 3)
        ->and($tournament->finalMatch()?->is($final))->toBeTrue();

    $final->update(['status' => MatchStatus::Completed, 'winning_side' => Side::B, 'played_at' => now()]);
    $tournament = $tournament->fresh()?->load('matches');

    expect($tournament?->champion()?->is($third))->toBeTrue()
        ->and($tournament?->runnerUp()?->is($first))->toBeTrue()
        ->and($tournament?->progress())->toBe(1.0);
});

test('progress ignores byes and cancelled matches', function () {
    $tournament = Tournament::factory()->knockout()->inProgress()->create();
    [$top, $a, $b] = TournamentEntry::factory()->count(3)->for($tournament)->create()->all();

    BadmintonMatch::factory()->inTournament($tournament, 1, 1)->bye($top)->create();
    BadmintonMatch::factory()->inTournament($tournament, 1, 2)->betweenEntries($a, $b)->completed()->create();
    BadmintonMatch::factory()->inTournament($tournament, 2, 1)->betweenEntries($top, $a)->create();
    BadmintonMatch::factory()->inTournament($tournament, 2, 2)->cancelled()->create();

    expect($tournament->progress())->toBe(0.5)
        ->and(Tournament::factory()->create()->progress())->toBe(0.0);
});

test('the round robin champion tops the table on wins, then game difference', function () {
    $tournament = Tournament::factory()->doubles()->roundRobin()->inProgress()->create();
    [$anna, $bea, $cleo] = TournamentEntry::factory()->count(3)->for($tournament)->create()->all();

    modelsSpecRoundRobinResult($tournament, 1, $anna, $bea, [[21, 15], [21, 17]]);
    modelsSpecRoundRobinResult($tournament, 2, $bea, $cleo, [[21, 19], [18, 21], [21, 16]]);
    $last = BadmintonMatch::factory()->inTournament($tournament, 3, null)->betweenEntries($cleo, $anna)->create();

    expect($tournament->champion())->toBeNull();

    $last->delete();
    modelsSpecRoundRobinResult($tournament, 3, $cleo, $anna, [[21, 18], [19, 21], [21, 19]]);

    // All on one win; game difference: Anna +1, Cleo 0, Bea -1.
    expect($tournament->champion()?->is($anna))->toBeTrue()
        ->and($tournament->runnerUp()?->is($cleo))->toBeTrue()
        ->and($tournament->finalMatch())->toBeNull();
});

test('entryFor finds the entry as player or partner', function () {
    $tournament = Tournament::factory()->doubles()->create();
    [$player, $partner, $stranger] = User::factory()->count(3)->create()->all();
    $entry = TournamentEntry::factory()->for($tournament)->withPartner($partner)->create(['player_id' => $player->id]);
    TournamentEntry::factory()->for($tournament)->create();

    expect($tournament->entryFor($player)?->is($entry))->toBeTrue()
        ->and($tournament->entryFor($partner)?->is($entry))->toBeTrue()
        ->and($tournament->entryFor($stranger))->toBeNull()
        ->and($tournament->load('entries')->entryFor($partner)?->is($entry))->toBeTrue();
});

test('drafts are only visible to admins', function () {
    $draft = Tournament::factory()->draft()->create();
    $open = Tournament::factory()->open()->create();

    expect(Tournament::visibleTo(null)->pluck('id')->all())->toBe([$open->id])
        ->and(Tournament::visibleTo(User::factory()->create())->pluck('id')->all())->toBe([$open->id])
        ->and(Tournament::visibleTo(User::factory()->admin()->create())->pluck('id')->all())->toEqualCanonicalizing([$draft->id, $open->id]);
});

test('upcoming, ongoing and past split tournaments by status', function () {
    $later = Tournament::factory()->open()->create(['starts_at' => now()->addMonths(2)]);
    $sooner = Tournament::factory()->draft()->create(['starts_at' => now()->addWeek()]);
    $ongoing = Tournament::factory()->inProgress()->create();
    $recent = Tournament::factory()->completed()->create(['starts_at' => now()->subWeeks(2)]);
    $older = Tournament::factory()->completed()->create(['starts_at' => now()->subMonths(2)]);

    expect(Tournament::upcoming()->pluck('id')->all())->toBe([$sooner->id, $later->id])
        ->and(Tournament::ongoing()->pluck('id')->all())->toBe([$ongoing->id])
        ->and(Tournament::past()->pluck('id')->all())->toBe([$recent->id, $older->id])
        ->and(Tournament::query()->visibleTo(null)->upcoming()->pluck('id')->all())->toBe([$later->id]);
});

test('deleting a tournament removes its entries and draw', function () {
    $tournament = Tournament::factory()->withEntries(2)->create();
    BadmintonMatch::factory()->inTournament($tournament)->create();

    $tournament->delete();

    expect(TournamentEntry::count())->toBe(0)
        ->and(BadmintonMatch::count())->toBe(0);
});
