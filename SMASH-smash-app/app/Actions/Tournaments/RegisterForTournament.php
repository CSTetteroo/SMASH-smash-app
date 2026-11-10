<?php

namespace App\Actions\Tournaments;

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sign a player (and, for doubles, their partner) up for a tournament.
 */
final class RegisterForTournament
{
    /**
     * @throws ValidationException keyed "tournament" (registration closed, full, already entered)
     *                             or "partner" (missing, the player themselves, inactive, already entered, or not allowed in singles)
     */
    public static function handle(Tournament $tournament, User $player, ?User $partner = null): TournamentEntry
    {
        return DB::transaction(static function () use ($tournament, $player, $partner): TournamentEntry {
            $entries = $tournament->entries()->get(['id', 'player_id', 'partner_id']);
            $entered = fn (User $user): bool => $entries->contains(
                fn (TournamentEntry $entry): bool => $entry->player_id === $user->id || $entry->partner_id === $user->id
            );
            $deadline = $tournament->registrationDeadline();

            $problem = match (true) {
                $tournament->status !== TournamentStatus::Open => 'Registration for this tournament is not open.',
                $deadline !== null && ! $deadline->isFuture() => 'Registration for this tournament has closed.',
                $tournament->max_entries !== null && $entries->count() >= $tournament->max_entries => 'This tournament is full.',
                $entered($player) => sprintf('%s is already registered for this tournament.', $player->name),
                default => null,
            };

            if ($problem !== null) {
                throw ValidationException::withMessages(['tournament' => $problem]);
            }

            if ($tournament->isDoubles()) {
                $problem = match (true) {
                    $partner === null => 'Choose a partner for this doubles tournament.',
                    $partner->is($player) => 'Choose a partner other than yourself.',
                    ! $partner->is_active => sprintf('%s is not an active player.', $partner->name),
                    $entered($partner) => sprintf('%s is already registered for this tournament.', $partner->name),
                    default => null,
                };
            } elseif ($partner !== null) {
                $problem = 'Singles entries do not have a partner.';
            }

            if ($problem !== null) {
                throw ValidationException::withMessages(['partner' => $problem]);
            }

            $entry = $tournament->entries()->create([
                'player_id' => $player->id,
                'partner_id' => $tournament->isDoubles() ? $partner?->id : null,
                'seed' => null,
            ]);

            $tournament->unsetRelation('entries');

            return $entry;
        });
    }
}
