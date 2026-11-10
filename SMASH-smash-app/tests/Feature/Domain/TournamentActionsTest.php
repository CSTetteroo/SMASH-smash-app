<?php

use App\Actions\Matches\SaveMatch;
use App\Actions\Tournaments\DeleteTournament;
use App\Actions\Tournaments\RegisterForTournament;
use App\Actions\Tournaments\ResetDraw;
use App\Actions\Tournaments\WithdrawFromTournament;
use App\Enums\TournamentStatus;
use App\Models\BadmintonMatch;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use App\Services\Tournament\DrawGenerator;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Domain\Support\Play;

/**
 * @return array<string, list<string>>
 */
function tournamentRegistrationErrors(Tournament $tournament, User $player, ?User $partner = null): array
{
    try {
        RegisterForTournament::handle($tournament, $player, $partner);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

test('players register for singles and pairs for doubles', function () {
    $singles = Tournament::factory()->open()->singles()->create();
    $doubles = Tournament::factory()->open()->doubles()->create();
    [$sam, $lotte] = User::factory()->count(2)->create();

    $entry = RegisterForTournament::handle($singles, $sam);
    $pair = RegisterForTournament::handle($doubles, $sam, $lotte);

    expect($entry->player_id)->toBe($sam->id)
        ->and($entry->partner_id)->toBeNull()
        ->and($entry->seed)->toBeNull()
        ->and($pair->partner_id)->toBe($lotte->id)
        ->and($doubles->entries()->count())->toBe(1);
});

test('registration must be open, before the deadline and not full', function () {
    $player = User::factory()->create();
    $full = Tournament::factory()->open()->maxEntries(1)->withEntries(1)->create();

    expect(tournamentRegistrationErrors(Tournament::factory()->draft()->create(), $player))->toHaveKey('tournament')
        ->and(tournamentRegistrationErrors(Tournament::factory()->inProgress()->create(), $player))->toHaveKey('tournament')
        ->and(tournamentRegistrationErrors(Tournament::factory()->open()->registrationClosed()->create(), $player)['tournament'][0])->toContain('closed')
        ->and(tournamentRegistrationErrors($full, $player)['tournament'][0])->toContain('full');
});

test('nobody can be entered twice, as player or as partner', function () {
    $doubles = Tournament::factory()->open()->doubles()->create();
    [$sam, $lotte, $jan, $mila] = User::factory()->count(4)->create();
    RegisterForTournament::handle($doubles, $sam, $lotte);

    expect(tournamentRegistrationErrors($doubles, $sam, $jan))->toHaveKey('tournament')
        ->and(tournamentRegistrationErrors($doubles, $lotte, $jan))->toHaveKey('tournament')
        ->and(tournamentRegistrationErrors($doubles, $jan, $lotte))->toHaveKey('partner')
        ->and(tournamentRegistrationErrors($doubles, $jan, $mila))->toBe([]);
});

test('doubles need a different, active partner and singles take none', function () {
    $doubles = Tournament::factory()->open()->doubles()->create();
    $singles = Tournament::factory()->open()->singles()->create();
    [$sam, $lotte] = User::factory()->count(2)->create();
    $inactive = User::factory()->inactive()->create();

    expect(tournamentRegistrationErrors($doubles, $sam))->toHaveKey('partner')
        ->and(tournamentRegistrationErrors($doubles, $sam, $sam)['partner'][0])->toContain('other than yourself')
        ->and(tournamentRegistrationErrors($doubles, $sam, $inactive)['partner'][0])->toContain('not an active player')
        ->and(tournamentRegistrationErrors($singles, $sam, $lotte))->toHaveKey('partner')
        ->and(TournamentEntry::query()->count())->toBe(0);
});

test('entries can be withdrawn until the draw is made', function () {
    $tournament = Tournament::factory()->open()->withEntries(3)->create();
    $entry = $tournament->entries()->firstOrFail();

    WithdrawFromTournament::handle($entry);

    expect($tournament->entries()->count())->toBe(2);

    DrawGenerator::generate($tournament);

    expect(fn () => WithdrawFromTournament::handle($tournament->entries()->firstOrFail()))
        ->toThrow(ValidationException::class);
});

test('resetting the draw deletes its matches, reopens registration and recalculates ratings', function () {
    $tournament = Tournament::factory()->open()->withEntries(2)->create();
    DrawGenerator::generate($tournament);
    $final = SaveMatch::handle(['status' => 'completed', 'games' => Play::games([[21, 3], [21, 3]])], $tournament->matches()->sole());
    $winner = $final->winners()->sole();

    expect($winner->refresh()->singles_played)->toBe(1)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Completed);

    ResetDraw::handle($tournament);

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Open)
        ->and(BadmintonMatch::query()->count())->toBe(0)
        ->and($winner->refresh()->singles_played)->toBe(0)
        ->and($winner->singles_rating)->toBe(1500.0)
        ->and($tournament->entries()->whereNotNull('seed')->count())->toBe(2);

    DrawGenerator::generate($tournament);
    ResetDraw::handle($tournament, clearSeeds: true);

    expect($tournament->entries()->whereNotNull('seed')->count())->toBe(0);
});

test('deleting a tournament removes entries and matches and recalculates ratings', function () {
    $tournament = Tournament::factory()->open()->withEntries(2)->create();
    DrawGenerator::generate($tournament);
    $final = SaveMatch::handle(['status' => 'completed', 'games' => Play::games([[21, 3], [21, 3]])], $tournament->matches()->sole());
    $winner = $final->winners()->sole();

    DeleteTournament::handle($tournament);

    expect(Tournament::query()->count())->toBe(0)
        ->and(TournamentEntry::query()->count())->toBe(0)
        ->and(BadmintonMatch::query()->count())->toBe(0)
        ->and($winner->refresh()->singles_played)->toBe(0);
});
