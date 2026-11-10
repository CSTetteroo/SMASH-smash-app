<?php

use App\Actions\Matches\SaveMatch;
use App\Enums\Discipline;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Prediction\MatchPrediction;
use App\Services\Prediction\MatchPredictor;
use App\Services\Tournament\DrawGenerator;
use App\Support\Badminton\Scoring;
use Tests\Feature\Domain\Support\Play;

beforeEach(fn () => Play::reset());

/**
 * @param  list<string>  $results  'W' or 'L' for $player against fresh opponents, oldest first
 */
function predictorForm(User $player, array $results): void
{
    foreach ($results as $result) {
        $opponent = User::factory()->create();
        $result === 'W' ? Play::win($player, $opponent) : Play::win($opponent, $player);
    }
}

function predictorShift(MatchPrediction $prediction, string $key): float
{
    return $prediction->factor($key)['shift'] ?? 0.0;
}

test('two newcomers are a toss-up', function () {
    [$ann, $ben] = User::factory()->count(2)->create();

    $prediction = MatchPredictor::predict([$ann], [$ben], Discipline::Singles);

    expect($prediction->probabilityA)->toEqualWithDelta(0.5, 1e-12)
        ->and($prediction->probabilityB)->toEqualWithDelta(0.5, 1e-12)
        ->and($prediction->eloProbabilityA)->toBe(0.5)
        ->and($prediction->favourite)->toBeNull()
        ->and($prediction->confidence)->toBe('Toss-up')
        ->and($prediction->gameProbabilityA)->toEqualWithDelta(0.5, 1e-9)
        ->and($prediction->rallyProbabilityA)->toEqualWithDelta(0.5, 1e-9)
        ->and(array_keys($prediction->scoreDistribution))->toBe(['2–0', '2–1', '1–2', '0–2'])
        ->and(array_sum($prediction->scoreDistribution))->toEqualWithDelta(1.0, 1e-12)
        ->and($prediction->scoreDistribution['2–0'])->toEqualWithDelta($prediction->scoreDistribution['0–2'], 1e-9)
        ->and($prediction->expectedGameScore[0])->toBe(21)
        ->and(Scoring::isCompleteGame(...$prediction->expectedGameScore, target: 21))->toBeTrue()
        ->and(array_column($prediction->factors, 'key'))->toBe(['rating', 'form', 'head_to_head', 'provisional'])
        ->and($prediction->factor('head_to_head')['detail'] ?? null)->toBe('First meeting')
        ->and($prediction->factor('form')['detail'] ?? null)->toBe('No rated matches yet')
        ->and($prediction->stakeFor($ann->id, Side::A))->toEqualWithDelta(20.0, 1e-9)
        ->and($prediction->percentFor(Side::A) + $prediction->percentFor(Side::B))->toBe(100);
});

test('a bigger rating gap always means a better chance', function () {
    $opponent = User::factory()->withRating(Discipline::Singles, 1500, 20)->create();
    $previous = 0.0;

    foreach (range(1300, 1900, 50) as $rating) {
        $player = User::factory()->withRating(Discipline::Singles, $rating, 20)->create();
        $prediction = MatchPredictor::predict([$player], [$opponent], Discipline::Singles);

        expect($prediction->probabilityA)->toBeGreaterThan($previous)
            ->and($prediction->gameProbabilityA > 0.5)->toBe($rating > 1500)
            ->and($prediction->favourite)->toBe($rating === 1500 ? null : ($rating > 1500 ? Side::A : Side::B));

        $previous = $prediction->probabilityA;
    }

    $clear = MatchPredictor::predict([User::factory()->withRating(Discipline::Singles, 1700, 20)->create()], [$opponent], Discipline::Singles);
    $strong = MatchPredictor::predict([User::factory()->withRating(Discipline::Singles, 1800, 20)->create()], [$opponent], Discipline::Singles);

    expect($clear->confidence)->toBe('Favourite')
        ->and($strong->confidence)->toBe('Strong favourite')
        ->and($strong->probabilityA)->toEqualWithDelta(1 / (1 + 10 ** (-300 / 400)), 1e-12)
        ->and($strong->scoreDistribution['2–0'])->toBeGreaterThan($strong->scoreDistribution['2–1'])
        ->and($strong->expectedGameScore[1])->toBeLessThan(17);
});

test('recent form shifts the prediction and is explained', function () {
    [$ann, $ben] = User::factory()->count(2)->create();
    predictorForm($ann, ['L', 'W', 'W', 'L', 'W', 'W']);
    predictorForm($ben, ['W', 'L', 'W', 'L', 'L']);

    $prediction = MatchPredictor::predict([$ann->refresh()], [$ben->refresh()], Discipline::Singles);
    $form = $prediction->factor('form');

    expect($form['label'].': '.$form['detail'])->toBe('Recent form: 4–1 vs 2–3 in the last 5')
        ->and($form['shift'])->toEqualWithDelta((3 - (-1)) * 8, 1e-12)
        ->and($prediction->effectiveDiff)->toEqualWithDelta($prediction->ratingA - $prediction->ratingB + 32, 1e-9)
        ->and($prediction->probabilityA)->toBeGreaterThan($prediction->eloProbabilityA);
});

test('the form shift is capped at 40 points', function () {
    [$ann, $ben] = User::factory()->count(2)->create();
    predictorForm($ann, ['W', 'W', 'W', 'W', 'W']);
    predictorForm($ben, ['L', 'L', 'L', 'L', 'L']);

    expect(predictorShift(MatchPredictor::predict([$ann->refresh()], [$ben->refresh()], Discipline::Singles), 'form'))->toBe(40.0);
});

test('earlier meetings between exactly these sides count for head-to-head', function () {
    [$ann, $ben, $cas, $dee] = User::factory()->count(4)->create();
    Play::win($ann, $ben);
    Play::win($ben, $ann);
    Play::win($ann, $ben);
    Play::win($ann, $ben);
    Play::win([$ann, $cas], [$ben, $dee]);

    $prediction = MatchPredictor::predict([$ann->refresh()], [$ben->refresh()], Discipline::Singles);
    $h2h = $prediction->factor('head_to_head');

    // Laplace: (3 + 1) / (4 + 2), weight 4 of 5 meetings.
    expect($h2h['shift'])->toEqualWithDelta((4 / 6 - 0.5) * 120 * 4 / 5, 1e-12)
        ->and($h2h['detail'])->toBe($ann->name.' leads 3–1 in previous meetings')
        ->and(predictorShift(MatchPredictor::predict([$ben], [$ann], Discipline::Singles), 'head_to_head'))->toEqualWithDelta(-$h2h['shift'], 1e-12);

    $pairs = MatchPredictor::predict([$ann, $cas], [$ben, $dee], Discipline::Doubles)->factor('head_to_head');

    expect($pairs['detail'])->toBe($ann->name.' & '.$cas->name.' lead 1–0 in previous meetings')
        ->and(MatchPredictor::predict([$ann, $dee], [$ben, $cas], Discipline::Doubles)->factor('head_to_head')['detail'] ?? null)->toBe('First meeting');
});

test('swapping the sides mirrors the whole prediction', function () {
    [$ann, $ben] = User::factory()->count(2)->create();
    predictorForm($ann, ['W', 'W', 'L']);
    predictorForm($ben, ['L', 'W']);
    Play::win($ann, $ben);
    Play::win($ben, $ann);
    Play::win($ann, $ben);

    $ab = MatchPredictor::predict([$ann->refresh()], [$ben->refresh()], Discipline::Singles);
    $ba = MatchPredictor::predict([$ben], [$ann], Discipline::Singles);

    expect($ab->probabilityA)->toEqualWithDelta($ba->probabilityB, 1e-12)
        ->and($ab->eloProbabilityA)->toEqualWithDelta(1 - $ba->eloProbabilityA, 1e-12)
        ->and($ab->gameProbabilityA)->toEqualWithDelta(1 - $ba->gameProbabilityA, 1e-9)
        ->and($ab->rallyProbabilityA)->toEqualWithDelta(1 - $ba->rallyProbabilityA, 1e-9)
        ->and($ab->scoreDistribution['2–0'])->toEqualWithDelta($ba->scoreDistribution['0–2'], 1e-9)
        ->and($ab->scoreDistribution['2–1'])->toEqualWithDelta($ba->scoreDistribution['1–2'], 1e-9)
        ->and($ab->expectedGameScore)->toBe($ba->expectedGameScore)
        ->and($ab->favourite)->toBe($ba->favourite?->opposite())
        ->and($ab->confidence)->toBe($ba->confidence);

    foreach ([$ann->id, $ben->id] as $id) {
        expect($ab->stakes['a_wins'][$id])->toEqualWithDelta($ba->stakes['b_wins'][$id], 1e-9)
            ->and($ab->stakes['b_wins'][$id])->toEqualWithDelta($ba->stakes['a_wins'][$id], 1e-9);
    }

    foreach (['rating', 'form', 'head_to_head'] as $key) {
        expect(predictorShift($ab, $key))->toEqualWithDelta(-predictorShift($ba, $key), 1e-9);
    }
});

test('doubles sides are rated as the mean of the partners', function () {
    $a1 = User::factory()->withRating(Discipline::Doubles, 1700, 20)->create();
    $a2 = User::factory()->withRating(Discipline::Doubles, 1500, 20)->create();
    $b1 = User::factory()->withRating(Discipline::Doubles, 1550, 20)->create();
    $b2 = User::factory()->withRating(Discipline::Doubles, 1550, 20)->create();

    $prediction = MatchPredictor::predict([$a1, $a2], [$b1->id, $b2->id], Discipline::Doubles, bestOf: 1, pointsPerGame: 15);

    expect($prediction->ratingA)->toBe(1600.0)
        ->and($prediction->ratingB)->toBe(1550.0)
        ->and($prediction->factor('rating')['detail'] ?? null)->toBe('Team ratings 1600 vs 1550 (average of both partners)')
        ->and(array_keys($prediction->scoreDistribution))->toBe(['1–0', '0–1'])
        ->and($prediction->gameProbabilityA)->toEqualWithDelta($prediction->probabilityA, 1e-9)
        ->and($prediction->expectedGameScore[0])->toBe(15)
        ->and($prediction->factor('provisional'))->toBeNull()
        ->and($prediction->stakes['a_wins'])->toHaveKeys([$a1->id, $a2->id, $b1->id, $b2->id]);
});

test('a finished match is predicted as it stood beforehand', function () {
    [$ann, $ben] = User::factory()->count(2)->create();
    Play::win($ann, $ben);
    $match = Play::win($ann, $ben);
    Play::win($ann, $ben);

    $before = $match->participantFor($ann)?->rating_before;
    $prediction = MatchPredictor::forMatch($match);

    expect($prediction->ratingA)->toBe($before)
        ->and($ann->refresh()->singles_rating)->not->toBe($before)
        ->and($prediction->factor('rating')['detail'] ?? '')->toContain('before the match')
        ->and($prediction->factor('head_to_head')['detail'] ?? null)->toBe($ann->name.' leads 1–0 in previous meetings')
        ->and($prediction->factor('form')['detail'] ?? null)->toBe('1–0 vs 0–1 in the last 5')
        // Stakes use the typical margin 1.0; the real 21–15, 21–15 win had a margin of 1.10 × 1.08.
        ->and($prediction->stakes['a_wins'][$ann->id])->toEqualWithDelta((float) $match->ratingChangeFor($ann) / (1.10 * 1.08), 1e-9);
});

test('a walkover is predicted from the ratings the players had at the time', function () {
    [$ann, $ben] = User::factory()->count(2)->create();
    Play::win($ann, $ben);
    $annThen = $ann->refresh()->singles_rating;
    $walkover = SaveMatch::handle([
        'discipline' => 'singles', 'side_a' => [$ann->id], 'side_b' => [$ben->id],
        'status' => 'walkover', 'walkover_winner' => 'a', 'played_at' => Play::nextTime(),
    ]);
    Play::win($ben, $ann);

    $prediction = MatchPredictor::forMatch($walkover);

    expect($prediction->ratingA)->toBe($annThen)
        ->and($ann->refresh()->singles_rating)->not->toBe($annThen)
        ->and($prediction->factor('head_to_head')['detail'] ?? null)->toBe($ann->name.' leads 1–0 in previous meetings');
});

test('an upcoming match uses current ratings and tournament stakes', function () {
    $tournament = Tournament::factory()->open()->knockout()->withEntries(2)->create();
    DrawGenerator::generate($tournament);
    $final = $tournament->matches()->sole();

    $prediction = MatchPredictor::forMatch($final);
    $playerA = $final->playersOn(Side::A)->sole();

    expect($prediction->probabilityA)->toEqualWithDelta(0.5, 1e-12)
        ->and($prediction->stakeFor($playerA->id, Side::A))->toEqualWithDelta(25.0, 1e-9);
});

test('a match without known players gives a neutral prediction', function () {
    $tournament = Tournament::factory()->open()->knockout()->withEntries(4)->create();
    DrawGenerator::generate($tournament);
    $final = $tournament->matches()->where('round', 2)->sole();

    $prediction = MatchPredictor::forMatch($final);

    expect($prediction->probabilityA)->toBe(0.5)
        ->and($prediction->factor('rating')['detail'] ?? null)->toBe('Players not known yet')
        ->and($prediction->stakes)->toBe(['a_wins' => [], 'b_wins' => []]);
});

test('the live win probability follows the score', function () {
    [$ann, $ben] = User::factory()->count(2)->create();
    $match = BadmintonMatch::factory()->inProgress()->withPlayers($ann, $ben)->create();

    $start = MatchPredictor::liveProbability($match);
    $ahead = MatchPredictor::liveProbability(SaveMatch::handle(['status' => 'in_progress', 'games' => Play::games([[21, 14], [12, 6]])], $match));
    $behind = MatchPredictor::liveProbability(SaveMatch::handle(['status' => 'in_progress', 'games' => Play::games([[14, 21], [6, 12]])], $match));
    $won = MatchPredictor::liveProbability(SaveMatch::handle(['status' => 'completed', 'games' => Play::games([[14, 21], [21, 12], [21, 19]])], $match));

    expect($start)->toEqualWithDelta(0.5, 1e-9)
        ->and($ahead)->toBeGreaterThan(0.9)
        ->and($behind)->toEqualWithDelta(1 - $ahead, 1e-9)
        ->and($won)->toBe(1.0);
});

test('confidence labels', function (float $probability, string $label) {
    expect(MatchPredictor::confidence($probability))->toBe($label);
})->with([
    [0.5, 'Toss-up'], [0.549, 'Toss-up'], [0.55, 'Slight edge'], [0.64, 'Slight edge'], [0.65, 'Favourite'],
    [0.79, 'Favourite'], [0.8, 'Strong favourite'], [0.97, 'Strong favourite'], [0.3, 'Favourite'], [0.1, 'Strong favourite'],
]);

test('a friendly match id list can be predicted without extra configuration', function () {
    [$ann, $ben] = User::factory()->count(2)->create();
    $match = BadmintonMatch::factory()->withPlayers($ann, $ben)->create();

    expect(MatchPredictor::forMatch($match)->confidence)->toBe('Toss-up');
});
