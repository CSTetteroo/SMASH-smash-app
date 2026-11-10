<?php

use App\Enums\Discipline;
use App\Models\BadmintonMatch;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

test('doubles entries get a partner automatically, singles entries do not', function () {
    $doubles = TournamentEntry::factory()->for(Tournament::factory()->doubles())->create();
    $singles = TournamentEntry::factory()->for(Tournament::factory()->singles())->create();
    $standalone = TournamentEntry::factory()->create();

    expect($doubles->partner)->toBeInstanceOf(User::class)
        ->and($doubles->partner_id)->not->toBe($doubles->player_id)
        ->and($singles->partner)->toBeNull()
        ->and($standalone->partner_id)->toBeNull()
        ->and($standalone->tournament)->toBeInstanceOf(Tournament::class);
});

test('entry names list the members', function () {
    $sam = User::factory()->create(['name' => 'Sam de Vries']);
    $lotte = User::factory()->create(['name' => 'Lotte Jansen']);

    $pair = TournamentEntry::factory()->for(Tournament::factory()->doubles())->withPartner($lotte)->create(['player_id' => $sam->id]);
    $solo = TournamentEntry::factory()->for(Tournament::factory()->singles())->create(['player_id' => $sam->id]);

    expect($pair->members()->pluck('id')->all())->toBe([$sam->id, $lotte->id])
        ->and($pair->name())->toBe('Sam de Vries & Lotte Jansen')
        ->and($pair->shortName())->toBe('De Vries / Jansen')
        ->and($solo->members())->toHaveCount(1)
        ->and($solo->name())->toBe('Sam de Vries')
        ->and($solo->shortName())->toBe('De Vries');
});

test('entry rating is the singles rating or the mean doubles rating', function () {
    $sam = User::factory()->withRating(Discipline::Singles, 1620, 10)->withRating(Discipline::Doubles, 1550, 10)->create();
    $lotte = User::factory()->withRating(Discipline::Doubles, 1450, 10)->create();

    $pair = TournamentEntry::factory()->for(Tournament::factory()->doubles())->withPartner($lotte)->create(['player_id' => $sam->id]);
    $solo = TournamentEntry::factory()->for(Tournament::factory()->singles())->create(['player_id' => $sam->id]);

    expect($pair->rating())->toBe(1500.0)
        ->and($pair->discipline())->toBe(Discipline::Doubles)
        ->and($solo->rating())->toBe(1620.0)
        ->and($solo->load('tournament')->discipline())->toBe(Discipline::Singles);
});

test('hasPlayer and the forPlayer scope match the player and the partner', function () {
    [$player, $partner, $stranger] = User::factory()->count(3)->create()->all();
    $entry = TournamentEntry::factory()->for(Tournament::factory()->doubles())->withPartner($partner)->create(['player_id' => $player->id]);
    TournamentEntry::factory()->count(2)->create();

    expect($entry->hasPlayer($player))->toBeTrue()
        ->and($entry->hasPlayer($partner))->toBeTrue()
        ->and($entry->hasPlayer($stranger))->toBeFalse()
        ->and(TournamentEntry::forPlayer($partner)->pluck('id')->all())->toBe([$entry->id])
        ->and(TournamentEntry::forPlayer($player->id)->pluck('id')->all())->toBe([$entry->id])
        ->and(TournamentEntry::forPlayer($stranger)->count())->toBe(0);
});

test('seeded orders seeds first, then unseeded entries by registration', function () {
    $tournament = Tournament::factory()->create();
    $second = TournamentEntry::factory()->for($tournament)->seed(2)->create();
    $unseeded = TournamentEntry::factory()->for($tournament)->create();
    $first = TournamentEntry::factory()->for($tournament)->seed(1)->create();

    expect($tournament->entries()->seeded()->pluck('id')->all())->toBe([$first->id, $second->id, $unseeded->id]);
});

test('a player can enter a tournament only once', function () {
    $entry = TournamentEntry::factory()->create();

    TournamentEntry::factory()->create(['tournament_id' => $entry->tournament_id, 'player_id' => $entry->player_id]);
})->throws(UniqueConstraintViolationException::class);

test('deleting an entry opens its slot in the draw', function () {
    $tournament = Tournament::factory()->create();
    $entries = TournamentEntry::factory()->count(2)->for($tournament)->create();
    $match = BadmintonMatch::factory()->inTournament($tournament)->betweenEntries($entries[0], $entries[1])->create();

    $entries[1]->delete();

    expect($match->fresh()?->entry_b_id)->toBeNull()
        ->and($match->fresh()?->entry_a_id)->toBe($entries[0]->id);
});
