<?php

use App\Actions\Matches\SaveMatch;
use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\MatchPlayer;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Rating\EloCalculator;
use App\Services\Rating\RatingEngine;
use App\Services\Tournament\DrawGenerator;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Domain\Support\Play;

beforeEach(fn () => Play::reset());

/**
 * Every user's rating columns, keyed by user id (to compare whole rating states).
 *
 * @return array<int, array<string, float|int>>
 */
function ratingEngineSnapshot(): array
{
    return User::query()->orderBy('id')->get()->mapWithKeys(fn (User $user): array => [$user->id => [
        'singles' => round($user->singles_rating, 9),
        'doubles' => round($user->doubles_rating, 9),
        'singles_peak' => round($user->singles_peak, 9),
        'doubles_peak' => round($user->doubles_peak, 9),
        'singles_played' => $user->singles_played,
        'doubles_played' => $user->doubles_played,
        'singles_wins' => $user->singles_wins,
        'doubles_wins' => $user->doubles_wins,
    ]])->all();
}

test('a singles win moves both players by the same amount', function () {
    [$sam, $lotte] = User::factory()->count(2)->create();

    $match = Play::match($sam, $lotte, [[21, 15], [21, 15]]);

    // Straight games (×1.10) and +12 points (×1.08), K 40 for newcomers, expected score 0.5.
    $change = 40 * 1.10 * (1 + 0.20 * 12 / 30) * 0.5;

    $sam->refresh();
    $lotte->refresh();

    expect($sam->singles_rating)->toEqualWithDelta(1500 + $change, 1e-9)
        ->and($lotte->singles_rating)->toEqualWithDelta(1500 - $change, 1e-9)
        ->and($sam->singles_peak)->toEqualWithDelta(1500 + $change, 1e-9)
        ->and($lotte->singles_peak)->toEqualWithDelta(1500.0, 1e-9)
        ->and([$sam->singles_played, $sam->singles_wins, $lotte->singles_played, $lotte->singles_wins])->toBe([1, 1, 1, 0])
        ->and([$sam->doubles_played, $sam->doubles_rating])->toBe([0, 1500.0]);

    $participant = $match->participantFor($sam);

    expect($participant?->rating_before)->toEqualWithDelta(1500.0, 1e-9)
        ->and($participant?->rating_after)->toEqualWithDelta(1500 + $change, 1e-9)
        ->and($match->ratingChangeFor($lotte))->toEqualWithDelta(-$change, 1e-9);
});

test('ratings are stored as exact doubles', function () {
    [$sam, $lotte] = User::factory()->count(2)->create();

    Play::match($sam, $lotte, [[21, 13], [19, 21], [21, 18]]);
    Play::match($sam, $lotte, [[21, 15], [21, 15]]);

    $first = 40 * EloCalculator::marginMultiplier(2, 1, 61 - 52, 3) * 0.5;
    $second = 40 * EloCalculator::marginMultiplier(2, 0, 12, 3) * (1 - EloCalculator::expected(1500 + $first, 1500 - $first));

    // More significant digits than PDO's default float binding keeps.
    expect(User::query()->whereKey($sam->id)->value('singles_rating'))->toBe(1500 + $first + $second)
        ->and(strlen((string) json_encode(1500 + $first + $second)))->toBeGreaterThan(15);
});

test('doubles sides are the mean of both partners and every player moves by their own K', function () {
    [$veteran, $rookie, $c, $d, $e, $f] = User::factory()->count(6)->create();

    // The veteran plays ten doubles matches first (no longer provisional), against others.
    foreach (range(1, 10) as $i) {
        $i % 2 === 0
            ? Play::win([$veteran, $e], [$c, $f])
            : Play::win([$c, $f], [$veteran, $e]);
    }

    $match = Play::match([$veteran, $rookie], [$c, $d], [[21, 18], [19, 21], [21, 17]]);

    $before = fn (User $user): float => (float) $match->participantFor($user)?->rating_before;
    $teamA = ($before($veteran) + $before($rookie)) / 2;
    $teamB = ($before($c) + $before($d)) / 2;
    // 61–56 on points over three games: a decider (×0.90) with a +5 point bonus.
    $margin = EloCalculator::marginMultiplier(2, 1, (21 + 19 + 21) - (18 + 21 + 17), 3);
    $expected = EloCalculator::expected($teamA, $teamB);

    expect($before($veteran))->not->toBe(1500.0)
        ->and($match->ratingChangeFor($veteran))->toEqualWithDelta(24 * $margin * (1 - $expected), 1e-9)
        ->and($match->ratingChangeFor($rookie))->toEqualWithDelta(40 * $margin * (1 - $expected), 1e-9)
        ->and($match->ratingChangeFor($c))->toEqualWithDelta(-24 * $margin * (1 - $expected), 1e-9)
        ->and($match->ratingChangeFor($d))->toEqualWithDelta(-40 * $margin * (1 - $expected), 1e-9)
        ->and($veteran->refresh()->doubles_played)->toBe(11)
        ->and($veteran->singles_played)->toBe(0);
});

test('tournament matches count 25 % more', function () {
    $tournament = Tournament::factory()->open()->knockout()->withEntries(2)->create();
    DrawGenerator::generate($tournament);
    $final = $tournament->matches()->sole();

    $match = SaveMatch::handle(['status' => MatchStatus::Completed, 'games' => Play::games([[21, 15], [21, 15]])], $final);
    $winner = $match->playersOn(Side::A)->sole();

    expect($match->ratingChangeFor($winner))->toEqualWithDelta(50 * 1.10 * 1.08 * 0.5, 1e-9);
});

test('unrated, walkover, cancelled and unfinished matches never touch ratings', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $base = ['discipline' => 'singles', 'side_a' => [$a->id], 'side_b' => [$b->id]];

    SaveMatch::handle([...$base, 'status' => 'completed', 'is_rated' => false, 'games' => Play::games([[21, 1], [21, 1]])]);
    SaveMatch::handle([...$base, 'status' => 'walkover', 'walkover_winner' => 'a']);
    SaveMatch::handle([...$base, 'status' => 'cancelled']);
    SaveMatch::handle([...$base, 'status' => 'scheduled']);
    SaveMatch::handle([...$base, 'status' => 'in_progress', 'games' => Play::games([[21, 3], [11, 2]])]);

    expect(MatchPlayer::query()->whereNotNull('rating_before')->orWhereNotNull('rating_after')->count())->toBe(0)
        ->and($a->refresh()->singles_rating)->toBe(1500.0)
        ->and($a->singles_played)->toBe(0)
        ->and($b->refresh()->singles_rating)->toBe(1500.0);
});

test('a match turned unrated afterwards loses its rating effect', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $match = Play::win($a, $b);

    expect($a->refresh()->singles_rating)->toBeGreaterThan(1500.0);

    SaveMatch::handle(['is_rated' => false], $match);

    expect($a->refresh()->singles_rating)->toBe(1500.0)
        ->and($a->singles_played)->toBe(0)
        ->and($match->participantFor($a)?->rating_before)->toBeNull();
});

test('the peak remembers the highest rating reached', function () {
    [$a, $b] = User::factory()->count(2)->create();

    Play::win($a, $b);
    $high = $a->refresh()->singles_rating;
    Play::win($b, $a);
    Play::win($b, $a);

    $a->refresh();

    expect($a->singles_peak)->toBe($high)
        ->and($a->singles_rating)->toBeLessThan($high)
        ->and($a->peak(Discipline::Singles))->toBe((int) round($high));
});

test('recalculating again changes nothing and writes nothing', function () {
    $players = User::factory()->count(4)->create();
    Play::win($players[0], $players[1]);
    Play::match([$players[0], $players[2]], [$players[1], $players[3]], [[19, 21], [21, 12], [21, 19]]);
    Play::win($players[3], $players[2]);

    $before = ratingEngineSnapshot();
    $participants = MatchPlayer::query()->orderBy('id')->get(['id', 'rating_before', 'rating_after'])->toArray();

    DB::enableQueryLog();
    RatingEngine::recalculate();
    $writes = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'update'));
    DB::disableQueryLog();

    expect(ratingEngineSnapshot())->toBe($before)
        ->and(MatchPlayer::query()->orderBy('id')->get(['id', 'rating_before', 'rating_after'])->toArray())->toBe($participants)
        ->and($writes)->toBeEmpty();
});

test('hand-edited ratings are restored from history', function () {
    [$a, $b] = User::factory()->count(2)->create();
    Play::win($a, $b);
    $expected = ratingEngineSnapshot();

    DB::table('users')->update(['singles_rating' => 1999, 'doubles_played' => 7]);
    DB::table('match_players')->update(['rating_before' => 1]);

    RatingEngine::recalculate();

    expect(ratingEngineSnapshot())->toBe($expected);
});

test('editing an old result gives the ratings history would have had all along', function () {
    $players = User::factory()->count(4)->create();
    [$a, $b, $c, $d] = $players;

    $script = function (bool $flipped) use ($a, $b, $c, $d): array {
        Play::reset();
        $first = Play::win($a, $b);
        $second = $flipped ? Play::win($d, $c) : Play::win($c, $d);
        Play::match([$a, $c], [$b, $d], [[21, 17], [21, 19]]);
        Play::win($a, $c);
        Play::match($b, $d, [[21, 23], [21, 15], [21, 11]]);

        return [$first, $second];
    };

    // History as it really happened, then a correction of match two.
    [, $second] = $script(false);
    SaveMatch::handle(['side_a' => [$d->id], 'side_b' => [$c->id]], $second);
    $corrected = ratingEngineSnapshot();

    // The same history with match two right from the start.
    BadmintonMatch::query()->delete();
    RatingEngine::recalculate();
    $script(true);

    expect(ratingEngineSnapshot())->toBe($corrected);
});

test('deleting or back-dating a match gives the ratings of the history without it or in its new place', function () {
    [$a, $b, $c] = User::factory()->count(3)->create();

    Play::win($a, $b);
    $removed = Play::win($b, $c);
    Play::win($c, $a);
    $removed->delete();
    RatingEngine::recalculate();
    $withoutIt = ratingEngineSnapshot();

    BadmintonMatch::query()->delete();
    Play::reset();
    Play::win($a, $b);
    Play::nextTime();
    Play::win($c, $a);

    expect(ratingEngineSnapshot())->toBe($withoutIt);

    // A result entered late but dated before everything else replays first.
    Play::reset();
    $late = Play::win($b, $c, ['played_at' => '2026-08-01 20:00:00']);

    expect($late->participantFor($b)?->rating_before)->toBe(1500.0)
        ->and($late->participantFor($c)?->rating_before)->toBe(1500.0);
});

test('matches with an incomplete line-up are not rated', function () {
    [$a, $b, $c] = User::factory()->count(3)->create();

    $match = BadmintonMatch::factory()->doubles()->completed()->withPlayers([$a, $b], [$c])->create();
    RatingEngine::recalculate();

    expect($match->participants()->whereNotNull('rating_after')->count())->toBe(0)
        ->and($a->refresh()->doubles_played)->toBe(0);
});

test('the replay is fast and does not query per match', function () {
    $now = now();
    $users = collect(range(1, 70))->map(fn (int $i): array => [
        'name' => "Player {$i}",
        'email' => "player{$i}@smash.test",
        'password' => 'x',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('users')->insert($users->all());
    $ids = User::query()->pluck('id')->all();

    mt_srand(7);
    $matches = [];
    $players = [];
    $games = [];

    for ($id = 1; $id <= 400; $id++) {
        $doubles = $id % 3 === 0;
        $picked = array_rand(array_flip($ids), $doubles ? 4 : 2);
        shuffle($picked);
        $matches[] = [
            'id' => $id, 'discipline' => $doubles ? 'doubles' : 'singles', 'status' => 'completed', 'best_of' => 3,
            'points_per_game' => 21, 'is_rated' => true, 'winning_side' => $id % 2 === 0 ? 'a' : 'b',
            'played_at' => $now->subMinutes(500 - $id), 'created_at' => $now, 'updated_at' => $now,
        ];

        foreach ($picked as $index => $userId) {
            $players[] = ['match_id' => $id, 'user_id' => $userId, 'side' => $index < count($picked) / 2 ? 'a' : 'b', 'created_at' => $now, 'updated_at' => $now];
        }

        $games[] = ['match_id' => $id, 'number' => 1, 'side_a_points' => $id % 2 === 0 ? 21 : 15, 'side_b_points' => $id % 2 === 0 ? 15 : 21, 'created_at' => $now, 'updated_at' => $now];
        $games[] = ['match_id' => $id, 'number' => 2, 'side_a_points' => $id % 2 === 0 ? 21 : 18, 'side_b_points' => $id % 2 === 0 ? 18 : 21, 'created_at' => $now, 'updated_at' => $now];
    }

    foreach ([['matches', $matches], ['match_players', $players], ['match_games', $games]] as [$table, $rows]) {
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    DB::enableQueryLog();
    $started = microtime(true);
    RatingEngine::recalculate();
    $seconds = microtime(true) - $started;
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($seconds)->toBeLessThan(2.0)
        ->and($queries)->toBeLessThan(40)
        ->and(MatchPlayer::query()->whereNull('rating_after')->count())->toBe(0)
        ->and((int) User::query()->sum('singles_played') + (int) User::query()->sum('doubles_played'))->toBe(count($players));

    // Ratings are zero-sum per discipline only up to the different K-factors, but every match moved someone.
    expect(User::query()->where('singles_rating', '!=', 1500)->count())->toBeGreaterThan(50);
});

test('stakes show what each player can win or lose', function () {
    [$a, $b] = User::factory()->count(2)->create();

    $stakes = RatingEngine::stakes([$a], [$b->id], Discipline::Singles);

    expect($stakes['a_wins'][$a->id])->toEqualWithDelta(20.0, 1e-9)
        ->and($stakes['a_wins'][$b->id])->toEqualWithDelta(-20.0, 1e-9)
        ->and($stakes['b_wins'][$a->id])->toEqualWithDelta(-20.0, 1e-9)
        ->and($stakes['b_wins'][$b->id])->toEqualWithDelta(20.0, 1e-9)
        ->and(RatingEngine::stakes([$a], [$b], Discipline::Singles, tournament: true)['a_wins'][$a->id])->toEqualWithDelta(25.0, 1e-9);

    $favourite = User::factory()->withRating(Discipline::Singles, 1700, 30)->create();
    $underdog = User::factory()->withRating(Discipline::Singles, 1500, 3)->create();
    $stakes = RatingEngine::stakes([$favourite], [$underdog], Discipline::Singles);

    expect($stakes['a_wins'][$favourite->id])->toBeLessThan(abs($stakes['b_wins'][$favourite->id]))
        ->and($stakes['b_wins'][$underdog->id])->toBeGreaterThan(24.0);
});

test('players can be given as models or ids, without duplicates', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $a->singles_rating = 1234.5;

    $users = RatingEngine::users([$a, $b->id, $a->id, 99999, $b->id]);

    expect(array_map(fn (User $user): int => $user->id, $users))->toBe([$a->id, $b->id])
        ->and($users[0])->toBe($a)
        ->and($users[0]->singles_rating)->toBe(1234.5);
});

test('deferred recalculation runs once at the end', function () {
    [$a, $b] = User::factory()->count(2)->create();

    $result = RatingEngine::deferred(function () use ($a, $b): string {
        Play::win($a, $b);
        Play::win($a, $b);

        expect($a->refresh()->singles_played)->toBe(0);

        return 'done';
    });

    expect($result)->toBe('done')
        ->and($a->refresh()->singles_played)->toBe(2)
        ->and($a->singles_wins)->toBe(2);
});

test('an exception inside a deferred block is rethrown and leaves recalculation working', function () {
    [$a, $b] = User::factory()->count(2)->create();

    expect(fn () => RatingEngine::deferred(function () use ($a, $b): void {
        Play::win($a, $b);

        throw new RuntimeException('Import failed');
    }))->toThrow(RuntimeException::class, 'Import failed');

    expect($a->refresh()->singles_played)->toBe(0);

    RatingEngine::recalculate();

    expect($a->refresh()->singles_played)->toBe(1);
});
