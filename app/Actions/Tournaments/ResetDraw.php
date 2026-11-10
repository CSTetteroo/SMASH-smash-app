<?php

namespace App\Actions\Tournaments;

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Services\Rating\RatingEngine;
use Illuminate\Support\Facades\DB;

/**
 * Throw the draw away: delete every match (with games, participants and predictions), reopen
 * registration and recalculate ratings. Seeds are kept unless $clearSeeds, so a new draw puts
 * the same seeds in the same places; clear them to reseed everybody by current rating.
 */
final class ResetDraw
{
    public static function handle(Tournament $tournament, bool $clearSeeds = false): void
    {
        DB::transaction(static function () use ($tournament, $clearSeeds): void {
            $tournament->matches()->delete();

            if ($clearSeeds) {
                $tournament->entries()->update(['seed' => null]);
            }

            $tournament->status = TournamentStatus::Open;
            $tournament->save();
            $tournament->unsetRelation('matches');
            $tournament->unsetRelation('entries');

            RatingEngine::recalculate();
        });
    }
}
