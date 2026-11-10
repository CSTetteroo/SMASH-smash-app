<?php

use App\Actions\Matches\SaveMatch;
use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Enums\TournamentStatus;
use App\Models\BadmintonMatch;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use App\Services\Rating\RatingEngine;
use App\Services\Tournament\DrawGenerator;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Domain\Support\Play;

/**
 * Play every knockout match in round order, the better seed always winning.
 */
function drawPlayChalk(Tournament $tournament): void
{
    RatingEngine::deferred(function () use ($tournament): void {
        $rounds = (int) $tournament->matches()->max('round');

        for ($round = 1; $round <= $rounds; $round++) {
            $matches = $tournament->matches()->where('round', $round)->orderBy('bracket_position')->with(['entryA', 'entryB'])->get();

            foreach ($matches as $match) {
                if ($match->isBye()) {
                    continue;
                }

                $aWins = (int) $match->entryA?->seed < (int) $match->entryB?->seed;

                SaveMatch::handle([
                    'status' => MatchStatus::Completed,
                    'games' => Play::games($aWins ? [[21, 10], [21, 10]] : [[10, 21], [10, 21]]),
                ], $match);
            }
        }
    });
}

/**
 * @return array<string, list<string>>
 */
function drawErrors(Tournament $tournament): array
{
    try {
        DrawGenerator::generate($tournament);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

test('a knockout draw has every round up-front, with byes for the top seeds', function (int $entries) {
    $tournament = Tournament::factory()->open()->knockout()->withEntries($entries)->create(['best_of' => 3, 'points_per_game' => 21, 'venue' => 'Court hall 2']);

    DrawGenerator::generate($tournament);

    $tournament->refresh();
    $size = DrawGenerator::bracketSize($entries);
    $matches = $tournament->matches()->orderBy('round')->orderBy('bracket_position')->get();
    $seeds = $tournament->entries()->pluck('seed', 'id');

    expect($tournament->status)->toBe(TournamentStatus::InProgress)
        ->and($matches)->toHaveCount($size - 1)
        ->and($seeds->sort()->values()->all())->toBe(range(1, $entries));

    for ($round = 1, $inRound = intdiv($size, 2); $inRound >= 1; $round++, $inRound = intdiv($inRound, 2)) {
        expect($matches->where('round', $round)->pluck('bracket_position')->all())->toBe(range(1, $inRound));
    }

    $byes = $matches->filter(fn (BadmintonMatch $match): bool => $match->isBye());
    $firstRound = $matches->where('round', 1);

    expect($byes)->toHaveCount($size - $entries)
        ->and($byes->every(fn (BadmintonMatch $match): bool => $match->round === 1 && $match->status === MatchStatus::Walkover
            && ! $match->is_rated && $match->winning_side === Side::A && $match->entry_b_id === null && $match->court === null))->toBeTrue()
        ->and($byes->map(fn (BadmintonMatch $match): int => (int) $seeds[$match->entry_a_id])->sort()->values()->all())->toBe($size - $entries === 0 ? [] : range(1, $size - $entries))
        ->and($firstRound->pluck('entry_a_id')->merge($firstRound->pluck('entry_b_id'))->filter()->sort()->values()->all())->toBe($seeds->keys()->sort()->values()->all());

    // Byes move straight on; matches with both entries known have their players.
    foreach ($byes as $bye) {
        $next = $matches->first(fn (BadmintonMatch $match): bool => $match->round === 2 && $match->bracket_position === intdiv($bye->bracket_position + 1, 2));
        $slot = $bye->bracket_position % 2 === 1 ? 'entry_a_id' : 'entry_b_id';

        expect($next?->getAttribute($slot))->toBe($bye->entry_a_id);
    }

    foreach ($matches as $match) {
        $known = $match->entry_a_id !== null && $match->entry_b_id !== null;

        expect($match->participants()->count())->toBe($known ? 2 : 0)
            ->and($match->best_of)->toBe(3)
            ->and($match->venue)->toBe('Court hall 2')
            ->and($match->discipline)->toBe(Discipline::Singles);

        if (! $match->isBye()) {
            expect($match->court)->toBeBetween(1, 4)
                ->and($match->scheduled_at?->greaterThanOrEqualTo($tournament->starts_at))->toBeTrue();
        }
    }

    // Later rounds are scheduled after earlier ones.
    for ($round = 2; $round <= $matches->max('round'); $round++) {
        $previousEnd = $matches->where('round', $round - 1)->reject->isBye()->max('scheduled_at');

        if ($previousEnd !== null) {
            expect($matches->where('round', $round)->min('scheduled_at')->greaterThan($previousEnd))->toBeTrue();
        }
    }
})->with([2, 3, 5, 8, 13, 16, 24]);

test('seeds 1 and 2 can only meet in the final', function (int $entries) {
    $tournament = Tournament::factory()->open()->knockout()->withEntries($entries)->create();
    DrawGenerator::generate($tournament);

    drawPlayChalk($tournament);

    $tournament->refresh();
    $final = $tournament->finalMatch();
    $seedsIn = fn (?BadmintonMatch $match): array => collect([$match?->entryA?->seed, $match?->entryB?->seed])->filter()->sort()->values()->all();

    expect($seedsIn($final))->toBe([1, 2])
        ->and($tournament->status)->toBe(TournamentStatus::Completed)
        ->and($tournament->champion()?->seed)->toBe(1)
        ->and($tournament->runnerUp()?->seed)->toBe(2);

    if ($entries >= 4) {
        $semis = $tournament->matches()->where('round', $final?->round - 1)->orderBy('bracket_position')->get();

        expect($seedsIn($semis[0]))->toBe([1, 4])
            ->and($seedsIn($semis[1]))->toBe([2, 3]);
    }
})->with([2, 3, 5, 8, 13, 16, 24]);

test('seeding follows rating and keeps seeds given by the committee', function () {
    $tournament = Tournament::factory()->open()->knockout()->create();
    $ratings = [1510, 1720, 1600, 1480, 1655];
    $entries = collect($ratings)->map(fn (int $rating) => TournamentEntry::factory()->for($tournament)->create([
        'player_id' => User::factory()->withRating(Discipline::Singles, $rating)->create()->id,
    ]));
    $entries[3]->update(['seed' => 1]);

    DrawGenerator::generate($tournament);

    $seedOf = fn (int $index): ?int => $entries[$index]->refresh()->seed;

    expect([$seedOf(3), $seedOf(1), $seedOf(4), $seedOf(2), $seedOf(0)])->toBe([1, 2, 3, 4, 5]);
});

test('doubles draws seed pairs by their mean doubles rating and put all four players on court', function () {
    $tournament = Tournament::factory()->open()->doubles()->knockout()->create();
    $pair = fn (int $a, int $b) => TournamentEntry::factory()->for($tournament)->create([
        'player_id' => User::factory()->withRating(Discipline::Doubles, $a)->create()->id,
        'partner_id' => User::factory()->withRating(Discipline::Doubles, $b)->create()->id,
    ]);
    $weak = $pair(1450, 1500);
    $strong = $pair(1700, 1600);
    $middle = $pair(1550, 1560);
    $pair(1500, 1500);

    DrawGenerator::generate($tournament);

    expect([$strong->refresh()->seed, $middle->refresh()->seed, $weak->refresh()->seed])->toBe([1, 2, 4])
        ->and($tournament->matches()->where('round', 1)->get()->every(fn (BadmintonMatch $match): bool => $match->participants()->count() === 4))->toBeTrue();
});

test('a round robin plays everybody once and nobody twice on a matchday', function (int $entries) {
    $tournament = Tournament::factory()->open()->roundRobin()->withEntries($entries)->create();

    DrawGenerator::generate($tournament);

    $matches = $tournament->matches()->get();
    $pairs = $matches->map(fn (BadmintonMatch $match): string => collect([$match->entry_a_id, $match->entry_b_id])->sort()->join('-'));

    expect($matches)->toHaveCount(intdiv($entries * ($entries - 1), 2))
        ->and($pairs->unique())->toHaveCount($matches->count())
        ->and($matches->max('round'))->toBe($entries % 2 === 0 ? $entries - 1 : $entries)
        ->and($matches->every(fn (BadmintonMatch $match): bool => $match->status === MatchStatus::Scheduled && $match->participants()->count() === 2))->toBeTrue()
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::InProgress);

    foreach ($matches->groupBy('round') as $matchday) {
        $playing = $matchday->pluck('entry_a_id')->merge($matchday->pluck('entry_b_id'));

        expect($playing->unique())->toHaveCount($playing->count());
    }
})->with([2, 3, 4, 5, 6, 7]);

test('the draw needs an open or draft tournament with two complete entries and no draw yet', function () {
    $lonely = Tournament::factory()->open()->withEntries(1)->create();
    $started = Tournament::factory()->inProgress()->withEntries(4)->create();
    $drawn = Tournament::factory()->open()->withEntries(2)->create();
    DrawGenerator::generate($drawn);
    $drawn->update(['status' => TournamentStatus::Open]);
    $doubles = Tournament::factory()->open()->doubles()->create();
    TournamentEntry::factory()->for($doubles)->withPartner()->create();
    TournamentEntry::factory()->for($doubles)->withoutPartner()->create();
    $draft = Tournament::factory()->draft()->withEntries(2)->create();

    expect(drawErrors($lonely))->toHaveKey('draw')
        ->and(drawErrors($started))->toHaveKey('draw')
        ->and(drawErrors($drawn)['draw'][0])->toContain('already has a draw')
        ->and(drawErrors($doubles)['draw'][0])->toContain('needs a partner')
        ->and(drawErrors($draft))->toBe([])
        ->and($lonely->matches()->count())->toBe(0);
});
