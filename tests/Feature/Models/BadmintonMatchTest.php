<?php

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\MatchGame;
use App\Models\MatchPlayer;
use App\Models\Prediction;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

test('matches live on the matches table and cast their columns', function () {
    $match = BadmintonMatch::factory()->doubles()->completed(Side::B)->create()->fresh();

    expect($match->getTable())->toBe('matches')
        ->and($match->discipline)->toBe(Discipline::Doubles)
        ->and($match->status)->toBe(MatchStatus::Completed)
        ->and($match->winning_side)->toBe(Side::B)
        ->and($match->is_rated)->toBeTrue()
        ->and($match->best_of)->toBe(3)
        ->and($match->points_per_game)->toBe(21)
        ->and($match->scheduled_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($match->played_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($match->tournament_id)->toBeNull();
});

test('a new match defaults to a scheduled best of three to 21', function () {
    $match = BadmintonMatch::create(['discipline' => Discipline::Singles]);

    expect($match->status)->toBe(MatchStatus::Scheduled)
        ->and($match->best_of)->toBe(3)
        ->and($match->points_per_game)->toBe(21)
        ->and($match->is_rated)->toBeTrue()
        ->and($match->fresh()?->status)->toBe(MatchStatus::Scheduled);
});

test('the completed factory state creates a valid straight-games result without ratings', function () {
    $singles = BadmintonMatch::factory()->completed()->create();
    $doubles = BadmintonMatch::factory()->doubles()->completed(Side::B)->create();

    expect($singles->games)->toHaveCount(2)
        ->and($singles->games->every(fn (MatchGame $game) => $game->isComplete(21) && $game->winner() === Side::A))->toBeTrue()
        ->and($singles->scoreline())->toBe('2–0')
        ->and($singles->played_at)->not->toBeNull()
        ->and($singles->participants)->toHaveCount(2)
        ->and($singles->playersOn(Side::A))->toHaveCount(1)
        ->and($singles->participants->every(fn (MatchPlayer $p) => $p->rating_before === null && $p->rating_after === null))->toBeTrue()
        ->and($doubles->participants)->toHaveCount(4)
        ->and($doubles->playersOn(Side::B))->toHaveCount(2)
        ->and($doubles->scoreline())->toBe('0–2');
});

test('withPlayers puts the given players on their sides', function () {
    [$a1, $a2, $b1, $b2] = User::factory()->count(4)->create()->all();

    $match = BadmintonMatch::factory()->doubles()->withPlayers([$a1, $a2], [$b1->id, $b2->id])->completed()->create();

    expect($match->participants)->toHaveCount(4)
        ->and($match->playersOn(Side::A)->pluck('id')->all())->toBe([$a1->id, $a2->id])
        ->and($match->playersOn(Side::B)->pluck('id')->all())->toBe([$b1->id, $b2->id])
        ->and($match->sideLabel(Side::A))->toBe($a1->name.' & '.$a2->name)
        ->and($match->players)->toHaveCount(4)
        ->and($match->players->firstWhere('id', $b1->id)?->getRelation('pivot')->getAttribute('side'))->toBe('b');
});

test('games are returned in playing order', function () {
    $match = BadmintonMatch::factory()->create();
    MatchGame::factory()->for($match, 'match')->number(2)->score(15, 21)->create();
    MatchGame::factory()->for($match, 'match')->number(1)->score(21, 18)->create();

    expect($match->games->pluck('number')->all())->toBe([1, 2])
        ->and($match->games->first()?->match->is($match))->toBeTrue();
});

test('a match belongs to its tournament, entries, creator and predictions', function () {
    $admin = User::factory()->admin()->create();
    $tournament = Tournament::factory()->doubles()->create();
    $entryA = TournamentEntry::factory()->for($tournament)->create();
    $entryB = TournamentEntry::factory()->for($tournament)->create();

    $match = BadmintonMatch::factory()
        ->inTournament($tournament, 1, 1)
        ->betweenEntries($entryA, $entryB)
        ->create(['created_by' => $admin->id]);

    Prediction::factory()->count(2)->for($match, 'match')->create();

    expect($match->tournament?->is($tournament))->toBeTrue()
        ->and($match->discipline)->toBe(Discipline::Doubles)
        ->and($match->entryA?->is($entryA))->toBeTrue()
        ->and($match->entryB?->is($entryB))->toBeTrue()
        ->and($match->creator?->is($admin))->toBeTrue()
        ->and($match->predictions)->toHaveCount(2)
        ->and($match->participants)->toHaveCount(4)
        ->and($match->sideLabel(Side::A))->toBe($entryA->name())
        ->and($match->hasParticipant($entryB->player))->toBeTrue()
        ->and($match->sideOf($entryB->partner ?? $entryB->player))->toBe(Side::B)
        ->and($match->isKnockout())->toBeTrue()
        ->and($match->isFriendly())->toBeFalse();
});

test('tournament matches show TBD for open slots and Bye for byes', function () {
    $tournament = Tournament::factory()->knockout()->create();
    $seed = TournamentEntry::factory()->for($tournament)->create();

    $bye = BadmintonMatch::factory()->inTournament($tournament, 1, 1)->bye($seed)->create();
    $next = BadmintonMatch::factory()->inTournament($tournament, 2, 1)->betweenEntries($seed, null)->create();

    expect($bye->isBye())->toBeTrue()
        ->and($bye->isFinished())->toBeTrue()
        ->and($bye->sideLabel(Side::A))->toBe($seed->name())
        ->and($bye->sideLabel(Side::B))->toBe('Bye')
        ->and($bye->scoreline())->toBe('W/O')
        ->and($next->isBye())->toBeFalse()
        ->and($next->sideLabel(Side::A))->toBe($seed->name())
        ->and($next->sideLabel(Side::B))->toBe('TBD')
        ->and($next->participants)->toHaveCount(0);
});

test('knockout round labels follow the size of the draw', function () {
    $tournament = Tournament::factory()->knockout()->create();
    $rounds = [];

    foreach ([1, 1, 1, 1, 2, 2, 3] as $position => $round) {
        $rounds[$round] = BadmintonMatch::factory()->inTournament($tournament, $round, $position + 1)->create();
    }

    expect($tournament->totalRounds())->toBe(3)
        ->and($rounds[1]->roundLabel())->toBe('Quarter-final')
        ->and($rounds[2]->roundLabel())->toBe('Semi-final')
        ->and($rounds[3]->roundLabel())->toBe('Final');
});

test('round robin matches are labelled by matchday', function () {
    $tournament = Tournament::factory()->roundRobin()->create();
    $match = BadmintonMatch::factory()->inTournament($tournament, 4, null)->create();

    expect($match->roundLabel())->toBe('Matchday 4')
        ->and($match->isRoundRobin())->toBeTrue()
        ->and($match->isKnockout())->toBeFalse();
});

test('participant helpers use the stored rating change', function () {
    [$sam, $lotte, $stranger] = User::factory()->count(3)->create()->all();
    $match = BadmintonMatch::factory()->withPlayers($sam, $lotte)->completed()->create();
    $match->participants()->where('user_id', $sam->id)->update(['rating_before' => 1500, 'rating_after' => 1516.5]);
    $match->unsetRelation('participants');

    expect($match->hasParticipant($sam))->toBeTrue()
        ->and($match->hasParticipant($stranger))->toBeFalse()
        ->and($match->sideOf($lotte))->toBe(Side::B)
        ->and($match->sideOf($stranger))->toBeNull()
        ->and($match->ratingChangeFor($sam))->toBe(16.5)
        ->and($match->ratingChangeFor($lotte))->toBeNull()
        ->and($match->winners()->pluck('id')->all())->toBe([$sam->id]);
});

test('status scopes: finished, live and upcoming', function () {
    $completed = BadmintonMatch::factory()->completed()->create();
    $walkover = BadmintonMatch::factory()->walkover()->create();
    $live = BadmintonMatch::factory()->inProgress()->create();
    BadmintonMatch::factory()->cancelled()->create();
    $later = BadmintonMatch::factory()->create(['scheduled_at' => now()->addDays(5)]);
    $soon = BadmintonMatch::factory()->create(['scheduled_at' => now()->addDay()]);
    $unscheduled = BadmintonMatch::factory()->create(['scheduled_at' => null]);

    expect(BadmintonMatch::finished()->pluck('id')->all())->toEqualCanonicalizing([$completed->id, $walkover->id])
        ->and(BadmintonMatch::live()->pluck('id')->all())->toBe([$live->id])
        ->and(BadmintonMatch::upcoming()->pluck('id')->all())->toBe([$soon->id, $later->id, $unscheduled->id]);
});

test('player, discipline, friendly and rated scopes', function () {
    $sam = User::factory()->create();
    $singles = BadmintonMatch::factory()->withPlayers($sam, User::factory()->create())->create();
    $doubles = BadmintonMatch::factory()->doubles()->unrated()->withPlayers([$sam, User::factory()->create()], User::factory()->count(2)->create())->create();
    $tournamentMatch = BadmintonMatch::factory()->inTournament(Tournament::factory()->create())->create();
    $other = BadmintonMatch::factory()->withRandomPlayers()->create();

    expect(BadmintonMatch::forPlayer($sam)->pluck('id')->all())->toEqualCanonicalizing([$singles->id, $doubles->id])
        ->and(BadmintonMatch::forPlayer($sam->id)->count())->toBe(2)
        ->and(BadmintonMatch::forDiscipline(Discipline::Doubles)->pluck('id')->all())->toBe([$doubles->id])
        ->and(BadmintonMatch::friendly()->pluck('id')->all())->toEqualCanonicalizing([$singles->id, $doubles->id, $other->id])
        ->and(BadmintonMatch::rated()->pluck('id')->all())->toEqualCanonicalizing([$singles->id, $tournamentMatch->id, $other->id])
        ->and($other->participants)->toHaveCount(2);
});

test('latestFirst orders by played, then scheduled, then id', function () {
    $older = BadmintonMatch::factory()->completed()->create(['played_at' => now()->subDays(3)]);
    $newer = BadmintonMatch::factory()->completed()->create(['played_at' => now()->subDay()]);
    $fixtureLate = BadmintonMatch::factory()->create(['scheduled_at' => now()->addDays(4)]);
    $fixtureEarly = BadmintonMatch::factory()->create(['scheduled_at' => now()->addDays(2)]);

    expect(BadmintonMatch::latestFirst()->pluck('id')->all())
        ->toBe([$newer->id, $older->id, $fixtureLate->id, $fixtureEarly->id]);
});

test('withDetails eager-loads everything the match helpers need', function () {
    $tournament = Tournament::factory()->doubles()->knockout()->create();
    $entries = TournamentEntry::factory()->count(2)->for($tournament)->create();
    BadmintonMatch::factory()->inTournament($tournament, 1, 1)->betweenEntries($entries[0], $entries[1])->completed()->create();
    BadmintonMatch::factory()->count(3)->completed()->create();
    BadmintonMatch::factory()->doubles()->withRandomPlayers()->create();

    $matches = BadmintonMatch::query()->withDetails()->latestFirst()->get();

    DB::flushQueryLog();
    DB::enableQueryLog();

    foreach ($matches as $match) {
        $match->sideLabel(Side::A);
        $match->sideLabel(Side::B);
        $match->scoreline();
        $match->roundLabel();
        $match->isKnockout();
    }

    // Only the (memoised) draw size lookup for the one tournament is allowed.
    expect(DB::getQueryLog())->toHaveCount(1);
});

test('deleting a match removes its players, games and predictions', function () {
    $match = BadmintonMatch::factory()->completed()->create();
    Prediction::factory()->for($match, 'match')->create();

    $match->delete();

    expect(MatchPlayer::count())->toBe(0)
        ->and(MatchGame::count())->toBe(0)
        ->and(Prediction::count())->toBe(0)
        ->and(User::count())->toBe(3);
});
