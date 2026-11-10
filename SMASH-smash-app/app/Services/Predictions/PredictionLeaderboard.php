<?php

namespace App\Services\Predictions;

use App\Models\Prediction;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The pick'em table: everybody who made a prediction, ranked by points, then correct picks,
 * exact picks, accuracy and name. Points follow Prediction::points() (3 right winner, +2 exact).
 */
final class PredictionLeaderboard
{
    /**
     * @param  int  $limit  rows returned (0 or less = everybody)
     * @return Collection<int, PredictorRow>
     */
    public static function rows(int $limit = 50): Collection
    {
        /** @var array<int, array{user: User, predictions: int, settled: int, correct: int, exact: int, points: int}> $table */
        $table = [];

        foreach (Prediction::query()->with(['match.games', 'user'])->get() as $prediction) {
            $row = $table[$prediction->user_id] ?? [
                'user' => $prediction->user,
                'predictions' => 0,
                'settled' => 0,
                'correct' => 0,
                'exact' => 0,
                'points' => 0,
            ];

            $row['predictions']++;

            if ($prediction->isSettled()) {
                $row['settled']++;
                $row['correct'] += $prediction->isCorrect() === true ? 1 : 0;
                $row['exact'] += $prediction->isExact() === true ? 1 : 0;
                $row['points'] += $prediction->points() ?? 0;
            }

            $table[$prediction->user_id] = $row;
        }

        $accuracy = fn (array $row): float => $row['settled'] === 0 ? -1.0 : $row['correct'] / $row['settled'];

        usort($table, fn (array $a, array $b): int => [$b['points'], $b['correct'], $b['exact'], $accuracy($b), $a['user']->name, $a['user']->id]
            <=> [$a['points'], $a['correct'], $a['exact'], $accuracy($a), $b['user']->name, $b['user']->id]);

        if ($limit > 0) {
            $table = array_slice($table, 0, $limit);
        }

        $rows = [];

        foreach ($table as $index => $row) {
            $rows[] = new PredictorRow(
                position: $index + 1,
                user: $row['user'],
                predictions: $row['predictions'],
                settled: $row['settled'],
                correct: $row['correct'],
                exact: $row['exact'],
                points: $row['points'],
                accuracy: $row['settled'] === 0 ? null : $row['correct'] / $row['settled'],
            );
        }

        return new Collection($rows);
    }
}
