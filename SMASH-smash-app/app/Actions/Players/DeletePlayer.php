<?php

namespace App\Actions\Players;

use App\Models\BadmintonMatch;
use App\Models\TournamentEntry;
use App\Models\User;
use App\Services\Rating\RatingEngine;
use Illuminate\Support\Facades\DB;

/**
 * Remove a player for good: their friendlies (for everybody in them), their tournament entries
 * (as player or partner), their RSVPs and predictions, the account itself; then recalculate
 * ratings. Tournament matches stay in the draw without that entry. Prefer deactivating.
 */
final class DeletePlayer
{
    public static function handle(User $user): void
    {
        DB::transaction(static function () use ($user): void {
            $friendlies = BadmintonMatch::query()->friendly()->forPlayer($user)->pluck('id');

            BadmintonMatch::query()->whereKey($friendlies->all())->delete();
            TournamentEntry::query()->forPlayer($user)->delete();

            $user->delete();

            RatingEngine::recalculate();
        });
    }
}
