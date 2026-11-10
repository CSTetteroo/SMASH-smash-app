<?php

namespace App\Services\Prediction;

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Services\Rating\EloCalculator;
use Illuminate\Support\Facades\DB;

/**
 * How good is the rating at predicting results? Replays every completed, rated match with the
 * pure Elo probability from the stored pre-match ratings (rating_before) and scores it.
 *
 * Matches where the model had no favourite (exactly 50 %, e.g. two newcomers at 1500) are not
 * scored; they are counted under "skipped".
 */
final class PredictionBacktest
{
    /**
     * Upsets listed in biggestUpsets.
     */
    public const UPSETS = 5;

    /**
     * Calibration buckets by the favourite's probability.
     */
    private const BUCKETS = ['50–60%', '60–70%', '70–80%', '80–90%', '90–100%'];

    /**
     * @return array{
     *     matches: int,
     *     correct: int,
     *     accuracy: float|null,
     *     brier: float|null,
     *     calibration: list<array{bucket: string, predicted: float, actual: float, count: int}>,
     *     biggestUpsets: list<array{match_id: int, probability: float}>,
     *     skipped: int,
     * }
     */
    public static function run(?Discipline $discipline = null): array
    {
        $rows = DB::table('match_players')
            ->join('matches', 'matches.id', '=', 'match_players.match_id')
            ->where('matches.status', MatchStatus::Completed->value)
            ->where('matches.is_rated', true)
            ->whereNotNull('matches.winning_side')
            ->when($discipline !== null, fn ($query) => $query->where('matches.discipline', $discipline?->value))
            ->orderBy('matches.id')
            ->get(['matches.id as match_id', 'matches.discipline', 'matches.winning_side', 'match_players.side', 'match_players.rating_before']);

        /** @var array<int, array{discipline: string, winner: string, a: list<float|null>, b: list<float|null>}> $matches */
        $matches = [];

        foreach ($rows as $row) {
            $id = (int) $row->match_id;
            $matches[$id] ??= ['discipline' => (string) $row->discipline, 'winner' => (string) $row->winning_side, 'a' => [], 'b' => []];
            $side = (string) $row->side === Side::B->value ? 'b' : 'a';
            $matches[$id][$side][] = is_numeric($row->rating_before) ? (float) $row->rating_before : null;
        }

        $scored = 0;
        $correct = 0;
        $skipped = 0;
        $brier = 0.0;
        $buckets = [];
        $upsets = [];

        foreach ($matches as $id => $match) {
            $perSide = Discipline::tryFrom($match['discipline'])?->playersPerSide() ?? 1;

            if (count($match['a']) !== $perSide || count($match['b']) !== $perSide
                || in_array(null, $match['a'], true) || in_array(null, $match['b'], true)) {
                continue;
            }

            /** @var list<float> $ratingsA */
            $ratingsA = $match['a'];
            /** @var list<float> $ratingsB */
            $ratingsB = $match['b'];

            $probabilityA = EloCalculator::expected(array_sum($ratingsA) / count($ratingsA), array_sum($ratingsB) / count($ratingsB));

            if (abs($probabilityA - 0.5) < 1e-9) {
                $skipped++;

                continue;
            }

            $aWon = $match['winner'] === Side::A->value;
            $favouriteWon = ($probabilityA > 0.5) === $aWon;
            $favouriteProbability = max($probabilityA, 1.0 - $probabilityA);
            $winnerProbability = $aWon ? $probabilityA : 1.0 - $probabilityA;

            $scored++;
            $correct += $favouriteWon ? 1 : 0;
            $brier += ($probabilityA - ($aWon ? 1.0 : 0.0)) ** 2;

            $bucket = min(count(self::BUCKETS) - 1, (int) floor(($favouriteProbability - 0.5) * 10));
            $buckets[$bucket]['predicted'] = ($buckets[$bucket]['predicted'] ?? 0.0) + $favouriteProbability;
            $buckets[$bucket]['won'] = ($buckets[$bucket]['won'] ?? 0) + ($favouriteWon ? 1 : 0);
            $buckets[$bucket]['count'] = ($buckets[$bucket]['count'] ?? 0) + 1;

            if ($winnerProbability < 0.5) {
                $upsets[] = ['match_id' => $id, 'probability' => $winnerProbability];
            }
        }

        ksort($buckets);

        $calibration = [];

        foreach ($buckets as $index => $bucket) {
            $calibration[] = [
                'bucket' => self::BUCKETS[$index],
                'predicted' => $bucket['predicted'] / $bucket['count'],
                'actual' => $bucket['won'] / $bucket['count'],
                'count' => $bucket['count'],
            ];
        }

        usort($upsets, fn (array $a, array $b): int => [$a['probability'], $b['match_id']] <=> [$b['probability'], $a['match_id']]);

        return [
            'matches' => $scored,
            'correct' => $correct,
            'accuracy' => $scored === 0 ? null : $correct / $scored,
            'brier' => $scored === 0 ? null : $brier / $scored,
            'calibration' => $calibration,
            'biggestUpsets' => array_slice($upsets, 0, self::UPSETS),
            'skipped' => $skipped,
        ];
    }
}
