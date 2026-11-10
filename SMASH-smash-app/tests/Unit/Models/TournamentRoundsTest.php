<?php

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\TournamentFormat;
use App\Models\BadmintonMatch;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

uses(TestCase::class);

test('rounds needed for a number of entries', function (TournamentFormat $format, int $entries, int $rounds) {
    expect(Tournament::roundsFor($format, $entries))->toBe($rounds);
})->with([
    'knockout 0' => [TournamentFormat::SingleElimination, 0, 0],
    'knockout 1' => [TournamentFormat::SingleElimination, 1, 0],
    'knockout 2' => [TournamentFormat::SingleElimination, 2, 1],
    'knockout 3' => [TournamentFormat::SingleElimination, 3, 2],
    'knockout 4' => [TournamentFormat::SingleElimination, 4, 2],
    'knockout 5' => [TournamentFormat::SingleElimination, 5, 3],
    'knockout 8' => [TournamentFormat::SingleElimination, 8, 3],
    'knockout 9' => [TournamentFormat::SingleElimination, 9, 4],
    'knockout 16' => [TournamentFormat::SingleElimination, 16, 4],
    'knockout 24' => [TournamentFormat::SingleElimination, 24, 5],
    'knockout 32' => [TournamentFormat::SingleElimination, 32, 5],
    'knockout 33' => [TournamentFormat::SingleElimination, 33, 6],
    'round robin 2' => [TournamentFormat::RoundRobin, 2, 1],
    'round robin 3' => [TournamentFormat::RoundRobin, 3, 3],
    'round robin 4' => [TournamentFormat::RoundRobin, 4, 3],
    'round robin 5' => [TournamentFormat::RoundRobin, 5, 5],
    'round robin 6' => [TournamentFormat::RoundRobin, 6, 5],
]);

test('knockout round labels count back from the final', function () {
    $entries = new Collection(array_map(fn (int $i) => (new TournamentEntry)->forceFill(['id' => $i]), range(1, 24)));
    $tournament = (new Tournament(['format' => TournamentFormat::SingleElimination, 'discipline' => Discipline::Singles]))
        ->setRelation('entries', $entries);

    expect($tournament->totalRounds())->toBe(5)
        ->and($tournament->roundLabel(1))->toBe('Round of 32')
        ->and($tournament->roundLabel(2))->toBe('Round of 16')
        ->and($tournament->roundLabel(3))->toBe('Quarter-final')
        ->and($tournament->roundLabel(3, plural: true))->toBe('Quarter-finals')
        ->and($tournament->roundLabel(4))->toBe('Semi-final')
        ->and($tournament->roundLabel(4, plural: true))->toBe('Semi-finals')
        ->and($tournament->roundLabel(5))->toBe('Final')
        ->and($tournament->roundLabel(5, plural: true))->toBe('Final');
});

test('the draw decides the number of rounds once it exists', function () {
    $matches = new Collection([
        new BadmintonMatch(['discipline' => Discipline::Singles, 'status' => MatchStatus::Scheduled, 'round' => 1]),
        new BadmintonMatch(['discipline' => Discipline::Singles, 'status' => MatchStatus::Scheduled, 'round' => 2]),
        new BadmintonMatch(['discipline' => Discipline::Singles, 'status' => MatchStatus::Scheduled, 'round' => 4]),
    ]);

    $tournament = (new Tournament(['format' => TournamentFormat::SingleElimination, 'discipline' => Discipline::Singles]))
        ->setRelation('entries', new Collection)
        ->setRelation('matches', $matches);

    expect($tournament->totalRounds())->toBe(4)
        ->and($tournament->hasDraw())->toBeTrue()
        ->and($tournament->roundLabel(1))->toBe('Round of 16')
        ->and($tournament->roundLabel(2))->toBe('Quarter-final');
});

test('round robin rounds are matchdays', function () {
    $tournament = (new Tournament(['format' => TournamentFormat::RoundRobin, 'discipline' => Discipline::Doubles]))
        ->forceFill(['id' => 4]);

    $match = (new BadmintonMatch(['discipline' => Discipline::Doubles, 'status' => MatchStatus::Scheduled]))
        ->forceFill(['tournament_id' => 4, 'round' => 3])
        ->setRelation('tournament', $tournament);

    expect($match->roundLabel())->toBe('Matchday 3')
        ->and($match->isRoundRobin())->toBeTrue()
        ->and($match->isKnockout())->toBeFalse()
        ->and($tournament->roundLabel(1))->toBe('Matchday 1');
});
