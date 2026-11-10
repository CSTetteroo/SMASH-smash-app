<?php

namespace App\Actions\Tournaments;

use App\Enums\TournamentStatus;
use App\Models\TournamentEntry;
use Illuminate\Validation\ValidationException;

/**
 * Withdraw an entry (player, or the whole pair) before the draw is made.
 */
final class WithdrawFromTournament
{
    /**
     * @throws ValidationException (key "tournament") once the draw exists or the tournament has started
     */
    public static function handle(TournamentEntry $entry): void
    {
        $tournament = $entry->tournament;

        if ($tournament->hasDraw() || in_array($tournament->status, [TournamentStatus::InProgress, TournamentStatus::Completed], true)) {
            throw ValidationException::withMessages([
                'tournament' => 'The draw has been made, so entries can no longer be withdrawn. Ask the committee if you cannot play.',
            ]);
        }

        $entry->delete();
        $tournament->unsetRelation('entries');
    }
}
