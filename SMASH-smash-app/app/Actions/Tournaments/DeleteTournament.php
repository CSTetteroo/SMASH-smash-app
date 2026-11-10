<?php

namespace App\Actions\Tournaments;

use App\Models\Tournament;
use App\Services\Rating\RatingEngine;
use Illuminate\Support\Facades\DB;

/**
 * Delete a tournament with its entries and draw, then recalculate ratings.
 */
final class DeleteTournament
{
    public static function handle(Tournament $tournament): void
    {
        DB::transaction(static function () use ($tournament): void {
            $tournament->matches()->delete();
            $tournament->delete();

            RatingEngine::recalculate();
        });
    }
}
