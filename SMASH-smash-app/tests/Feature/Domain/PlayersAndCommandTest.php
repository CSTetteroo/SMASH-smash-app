<?php

use App\Actions\Players\DeletePlayer;
use App\Actions\Tournaments\RegisterForTournament;
use App\Models\BadmintonMatch;
use App\Models\ClubNightRsvp;
use App\Models\Prediction;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Domain\Support\Play;

beforeEach(fn () => Play::reset());

test('deleting a player removes their friendlies, entries and account and recalculates everybody', function () {
    [$gone, $ann, $ben, $cas] = User::factory()->count(4)->create();
    Play::win($gone, $ann);
    Play::win([$gone, $ben], [$ann, $cas]);
    $kept = Play::win($ann, $ben);
    $doubles = Tournament::factory()->open()->doubles()->create();
    RegisterForTournament::handle($doubles, $cas, $gone);
    ClubNightRsvp::factory()->for($gone)->create();
    Prediction::factory()->for($gone)->for($kept, 'match')->create();

    DeletePlayer::handle($gone);

    expect(User::query()->whereKey($gone->id)->exists())->toBeFalse()
        ->and(BadmintonMatch::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(TournamentEntry::query()->count())->toBe(0)
        ->and(ClubNightRsvp::query()->count())->toBe(0)
        ->and(Prediction::query()->count())->toBe(0)
        ->and($ann->refresh()->singles_played)->toBe(1)
        ->and($ann->doubles_played)->toBe(0)
        ->and($ann->singles_rating)->toBeGreaterThan(1500.0)
        ->and($kept->refresh()->participantFor($ann)?->rating_before)->toBe(1500.0);
});

test('the artisan command rebuilds the ratings', function () {
    [$ann, $ben] = User::factory()->count(2)->create();
    Play::win($ann, $ben);
    $rating = $ann->refresh()->singles_rating;

    DB::table('users')->update(['singles_rating' => 1234, 'singles_played' => 99]);

    $this->artisan('smash:recalculate-ratings')
        ->expectsOutputToContain('Ratings recalculated from 1 rated match for 2 players')
        ->assertSuccessful();

    expect($ann->refresh()->singles_rating)->toBe($rating)
        ->and($ann->singles_played)->toBe(1);
});
