<?php

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Enums\TournamentFormat;
use App\Models\BadmintonMatch;
use App\Models\MatchGame;
use App\Models\MatchPlayer;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Build an unsaved match with in-memory games: [[21, 15], [18, 21]] etc.
 *
 * @param  list<array{0: int, 1: int}>  $scores
 */
function modelsSpecMatchWithGames(MatchStatus $status, array $scores, ?Side $winner = null): BadmintonMatch
{
    $match = new BadmintonMatch([
        'discipline' => Discipline::Singles,
        'status' => $status,
        'winning_side' => $winner,
    ]);

    $games = [];

    foreach ($scores as $index => [$a, $b]) {
        $games[] = new MatchGame(['number' => $index + 1, 'side_a_points' => $a, 'side_b_points' => $b]);
    }

    return $match->setRelation('games', new Collection($games));
}

/**
 * @param  array<int, array{0: string, 1: Side}>  $players  [name, side]
 */
function modelsSpecMatchWithPlayers(array $players, Discipline $discipline = Discipline::Singles): BadmintonMatch
{
    $match = new BadmintonMatch(['discipline' => $discipline, 'status' => MatchStatus::Scheduled]);
    $participants = [];

    foreach ($players as $id => [$name, $side]) {
        $user = (new User(['name' => $name]))->forceFill(['id' => $id + 1]);
        $participant = (new MatchPlayer(['user_id' => $id + 1, 'side' => $side]))->forceFill(['id' => $id + 1]);
        $participants[] = $participant->setRelation('user', $user);
    }

    return $match->setRelation('participants', new Collection($participants));
}

test('scoreline counts games won, side A first', function () {
    $match = modelsSpecMatchWithGames(MatchStatus::Completed, [[21, 15], [18, 21], [21, 19]], Side::A);

    expect($match->gamesWon(Side::A))->toBe(2)
        ->and($match->gamesWon(Side::B))->toBe(1)
        ->and($match->scoreline())->toBe('2–1')
        ->and($match->loserGames())->toBe(1)
        ->and($match->pointsWon(Side::A))->toBe(60)
        ->and($match->pointsWon(Side::B))->toBe(55)
        ->and($match->isFinished())->toBeTrue();
});

test('a live match does not count the game in progress', function () {
    $match = modelsSpecMatchWithGames(MatchStatus::InProgress, [[21, 15], [10, 8]]);

    expect($match->scoreline())->toBe('1–0')
        ->and($match->loserGames())->toBeNull()
        ->and($match->isLive())->toBeTrue()
        ->and($match->isFinished())->toBeFalse();
});

test('walkovers read W/O', function () {
    $match = modelsSpecMatchWithGames(MatchStatus::Walkover, [], Side::B);

    expect($match->scoreline())->toBe('W/O')
        ->and($match->loserGames())->toBeNull()
        ->and($match->isFinished())->toBeTrue();
});

test('a scheduled match has no score yet', function () {
    expect(modelsSpecMatchWithGames(MatchStatus::Scheduled, [])->scoreline())->toBe('0–0');
});

test('players are grouped by side in the order they were added', function () {
    $match = modelsSpecMatchWithPlayers([
        ['Lotte Jansen', Side::B],
        ['Sam de Vries', Side::A],
        ['Ji-woo Park', Side::B],
        ['Marco Rossi', Side::A],
    ], Discipline::Doubles);

    expect($match->playersOn(Side::A)->pluck('name')->all())->toBe(['Sam de Vries', 'Marco Rossi'])
        ->and($match->sideLabel(Side::A))->toBe('Sam de Vries & Marco Rossi')
        ->and($match->sideLabel(Side::B))->toBe('Lotte Jansen & Ji-woo Park');
});

test('singles side labels are the player name and TBD without players', function () {
    $match = modelsSpecMatchWithPlayers([['Sam de Vries', Side::A]]);

    expect($match->sideLabel(Side::A))->toBe('Sam de Vries')
        ->and($match->sideLabel(Side::B))->toBe('TBD');
});

test('participant helpers find the side and rating change of a user', function () {
    $match = modelsSpecMatchWithPlayers([['Sam de Vries', Side::A], ['Lotte Jansen', Side::B]]);
    $match->participants[1]->forceFill(['rating_before' => 1520.0, 'rating_after' => 1508.0]);

    $sam = (new User)->forceFill(['id' => 1]);
    $lotte = (new User)->forceFill(['id' => 2]);
    $stranger = (new User)->forceFill(['id' => 99]);

    expect($match->hasParticipant($sam))->toBeTrue()
        ->and($match->hasParticipant($stranger))->toBeFalse()
        ->and($match->sideOf($lotte))->toBe(Side::B)
        ->and($match->sideOf($stranger))->toBeNull()
        ->and($match->ratingChangeFor($lotte))->toBe(-12.0)
        ->and($match->ratingChangeFor($sam))->toBeNull()
        ->and($match->ratingChangeFor($stranger))->toBeNull();
});

test('perspective helpers read the match from one player\'s side', function () {
    $match = modelsSpecMatchWithPlayers([
        ['Sam de Vries', Side::A],
        ['Marco Rossi', Side::A],
        ['Lotte Jansen', Side::B],
        ['Ji-woo Park', Side::B],
    ], Discipline::Doubles);

    $match->forceFill(['status' => MatchStatus::Completed, 'winning_side' => Side::B])
        ->setRelation('games', new Collection([
            new MatchGame(['number' => 1, 'side_a_points' => 21, 'side_b_points' => 18]),
            new MatchGame(['number' => 2, 'side_a_points' => 15, 'side_b_points' => 21]),
            new MatchGame(['number' => 3, 'side_a_points' => 19, 'side_b_points' => 21]),
        ]));

    $sam = (new User)->forceFill(['id' => 1]);
    $lotte = (new User)->forceFill(['id' => 3]);
    $stranger = (new User)->forceFill(['id' => 99]);

    expect($match->matchup())->toBe('Sam de Vries & Marco Rossi vs Lotte Jansen & Ji-woo Park')
        ->and($match->partnerOf($sam)?->name)->toBe('Marco Rossi')
        ->and($match->opponentsOf($sam)->pluck('name')->all())->toBe(['Lotte Jansen', 'Ji-woo Park'])
        ->and($match->opponentsOf($stranger))->toHaveCount(0)
        ->and($match->partnerOf($stranger))->toBeNull()
        ->and($match->isWonBy($sam))->toBeFalse()
        ->and($match->isWonBy($lotte))->toBeTrue()
        ->and($match->isWonBy($stranger))->toBeNull()
        ->and($match->scorelineFor($sam))->toBe('1–2')
        ->and($match->scorelineFor($lotte))->toBe('2–1')
        ->and($match->scorelineFor($stranger))->toBe('1–2');
});

test('a match without a result has no winner yet', function () {
    $match = modelsSpecMatchWithPlayers([['Sam de Vries', Side::A], ['Lotte Jansen', Side::B]]);

    expect($match->isWonBy((new User)->forceFill(['id' => 1])))->toBeNull()
        ->and($match->partnerOf((new User)->forceFill(['id' => 1])))->toBeNull();
});

test('tournament side labels use the entry name, Bye or TBD', function () {
    $tournament = (new Tournament(['format' => TournamentFormat::SingleElimination]))->forceFill(['id' => 7]);
    $entry = (new TournamentEntry)->forceFill(['id' => 3, 'player_id' => 1])
        ->setRelation('player', new User(['name' => 'Sam de Vries']))
        ->setRelation('partner', null);

    $bye = (new BadmintonMatch(['discipline' => Discipline::Singles, 'status' => MatchStatus::Walkover, 'winning_side' => Side::A]))
        ->forceFill(['tournament_id' => 7, 'round' => 1, 'entry_a_id' => 3, 'entry_b_id' => null])
        ->setRelation('tournament', $tournament)
        ->setRelation('entryA', $entry)
        ->setRelation('participants', new Collection);

    expect($bye->isBye())->toBeTrue()
        ->and($bye->isFriendly())->toBeFalse()
        ->and($bye->isKnockout())->toBeTrue()
        ->and($bye->sideLabel(Side::A))->toBe('Sam de Vries')
        ->and($bye->sideLabel(Side::B))->toBe('Bye')
        ->and($bye->winningEntry())->toBe($entry)
        ->and($bye->losingEntry())->toBeNull();

    $openSlot = (new BadmintonMatch(['discipline' => Discipline::Singles, 'status' => MatchStatus::Scheduled]))
        ->forceFill(['tournament_id' => 7, 'round' => 2, 'entry_a_id' => 3, 'entry_b_id' => null])
        ->setRelation('tournament', $tournament)
        ->setRelation('entryA', $entry)
        ->setRelation('participants', new Collection);

    expect($openSlot->isBye())->toBeFalse()
        ->and($openSlot->sideLabel(Side::B))->toBe('TBD');
});

test('friendlies have no round label and are not knockouts', function () {
    $match = new BadmintonMatch(['discipline' => Discipline::Singles, 'status' => MatchStatus::Scheduled, 'round' => 1]);

    expect($match->roundLabel())->toBeNull()
        ->and($match->isFriendly())->toBeTrue()
        ->and($match->isKnockout())->toBeFalse()
        ->and($match->isBye())->toBeFalse()
        ->and($match->winners())->toHaveCount(0);
});
