<?php

use App\Actions\Matches\SaveMatch;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use App\Services\Tournament\Bracket;
use App\Services\Tournament\DrawGenerator;
use App\Services\Tournament\StandingRow;
use App\Services\Tournament\Standings;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Domain\Support\Play;

/**
 * A round robin with entries for the given players (seeded in this order) and its draw.
 *
 * @param  list<string>  $names
 * @return array{0: Tournament, 1: list<TournamentEntry>}
 */
function standingsRoundRobin(array $names): array
{
    $tournament = Tournament::factory()->open()->roundRobin()->create();
    $entries = [];

    foreach ($names as $index => $name) {
        $entries[] = TournamentEntry::factory()->for($tournament)->seed($index + 1)->create([
            'player_id' => User::factory()->create(['name' => $name])->id,
        ]);
    }

    DrawGenerator::generate($tournament);

    return [$tournament, $entries];
}

/**
 * Record a result between two entries (games from $first's point of view).
 *
 * @param  list<array{0: int, 1: int}>  $games
 */
function standingsResult(Tournament $tournament, TournamentEntry $first, TournamentEntry $second, array $games): BadmintonMatch
{
    $match = $tournament->matches()
        ->where(fn ($query) => $query
            ->where(fn ($pair) => $pair->where('entry_a_id', $first->id)->where('entry_b_id', $second->id))
            ->orWhere(fn ($pair) => $pair->where('entry_a_id', $second->id)->where('entry_b_id', $first->id)))
        ->sole();

    if ($match->entry_a_id !== $first->id) {
        $games = array_map(fn (array $game): array => [$game[1], $game[0]], $games);
    }

    return SaveMatch::handle(['status' => MatchStatus::Completed, 'games' => Play::games($games)], $match);
}

test('the table sorts by wins, then game difference, then point difference', function () {
    [$tournament, [$anna, $bram, $chen]] = standingsRoundRobin(['Anna Smit', 'Bram Peters', 'Chen Li']);

    standingsResult($tournament, $anna, $bram, [[21, 10], [21, 10]]);
    standingsResult($tournament, $bram, $chen, [[21, 5], [21, 5]]);
    standingsResult($tournament, $chen, $anna, [[21, 19], [21, 19]]);

    $rows = Standings::for($tournament->refresh());

    expect($rows->map(fn (StandingRow $row): string => $row->entry->name())->all())->toBe(['Anna Smit', 'Bram Peters', 'Chen Li'])
        ->and($rows->pluck('position')->all())->toBe([1, 2, 3]);

    $first = $rows->first();

    expect([$first->played, $first->won, $first->lost, $first->gamesFor, $first->gamesAgainst])->toBe([2, 1, 1, 2, 2])
        ->and([$first->pointsFor, $first->pointsAgainst])->toBe([42 + 38, 20 + 42])
        ->and($first->pointDifference())->toBe(18)
        ->and($first->gameDifference())->toBe(0)
        ->and($rows->last()?->pointDifference())->toBe(-28);

    // The champion badge agrees with the table.
    expect($tournament->champion()?->id)->toBe($anna->id)
        ->and($tournament->runnerUp()?->id)->toBe($bram->id);
});

test('wins come before game difference, and names settle a dead heat', function () {
    [$tournament, [$zoe, $yara, $xavi, $wim]] = standingsRoundRobin(['Zoe Visser', 'Yara Bakker', 'Xavi Ruiz', 'Wim Mulder']);

    standingsResult($tournament, $zoe, $wim, [[21, 0], [21, 0]]);
    standingsResult($tournament, $yara, $xavi, [[21, 19], [19, 21], [21, 19]]);
    standingsResult($tournament, $yara, $wim, [[21, 19], [19, 21], [21, 19]]);

    $names = Standings::for($tournament)->map(fn (StandingRow $row): string => $row->entry->name())->all();

    // Yara has two wins; Zoe one; Xavi and Wim are level on wins, Xavi has the better game difference.
    expect($names)->toBe(['Yara Bakker', 'Zoe Visser', 'Xavi Ruiz', 'Wim Mulder']);

    [$tournament, [$late, $early]] = standingsRoundRobin(['Zed Last', 'Abe First']);

    expect(Standings::for($tournament)->map(fn (StandingRow $row): string => $row->entry->name())->all())->toBe(['Abe First', 'Zed Last']);
});

test('walkovers count as a win without games and cancelled matches do not count', function () {
    [$tournament, [$a, $b, $c]] = standingsRoundRobin(['Anna Smit', 'Bram Peters', 'Chen Li']);
    $walkover = $tournament->matches()->where('entry_a_id', $a->id)->where('entry_b_id', $b->id)->sole();
    $cancelled = $tournament->matches()->where('entry_a_id', $b->id)->where('entry_b_id', $c->id)->sole();

    SaveMatch::handle(['status' => 'walkover', 'walkover_winner' => 'a'], $walkover);
    SaveMatch::handle(['status' => 'cancelled'], $cancelled);

    $rows = Standings::for($tournament)->keyBy(fn (StandingRow $row): int => $row->entry->id);

    expect([$rows[$a->id]->played, $rows[$a->id]->won, $rows[$a->id]->gamesFor])->toBe([1, 1, 0])
        ->and([$rows[$b->id]->played, $rows[$b->id]->lost])->toBe([1, 1])
        ->and($rows[$c->id]->played)->toBe(0);
});

test('the bracket groups knockout rounds with everything loaded for rendering', function () {
    $tournament = Tournament::factory()->open()->knockout()->withEntries(5)->create();
    DrawGenerator::generate($tournament);

    $rounds = Bracket::for($tournament);

    expect(array_column($rounds, 'label'))->toBe(['Quarter-finals', 'Semi-finals', 'Final'])
        ->and(array_column($rounds, 'round'))->toBe([1, 2, 3])
        ->and(array_map(fn (array $round): int => $round['matches']->count(), $rounds))->toBe([4, 2, 1])
        ->and($rounds[0]['matches']->pluck('bracket_position')->all())->toBe([1, 2, 3, 4]);

    DB::enableQueryLog();

    foreach ($rounds as $round) {
        foreach ($round['matches'] as $match) {
            $match->sideLabel(Side::A);
            $match->sideLabel(Side::B);
            $match->roundLabel();
            $match->scoreline();
            $match->isBye();
            $match->entryA?->shortName();
        }
    }

    $tournament->progress();
    $tournament->totalRounds();

    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});

test('round robin brackets list matchdays', function () {
    $tournament = Tournament::factory()->open()->roundRobin()->withEntries(4)->create();
    DrawGenerator::generate($tournament);

    $rounds = Bracket::for($tournament);

    expect(array_column($rounds, 'label'))->toBe(['Matchday 1', 'Matchday 2', 'Matchday 3'])
        ->and($rounds[2]['matches'])->toHaveCount(2);
});
