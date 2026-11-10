<?php

namespace App\Policies;

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\User;

class TournamentPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * Drafts are only visible to admins.
     */
    public function view(?User $user, Tournament $tournament): bool
    {
        return $tournament->status !== TournamentStatus::Draft || $user?->isAdmin() === true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Tournament $tournament): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Tournament $tournament): bool
    {
        return $user->isAdmin();
    }

    /**
     * Generate or reset the draw.
     */
    public function manageDraw(User $user, Tournament $tournament): bool
    {
        return $user->isAdmin();
    }

    /**
     * Sign up (the RegisterForTournament action checks duplicates and partners).
     */
    public function register(User $user, Tournament $tournament): bool
    {
        return $tournament->isOpenForRegistration();
    }
}
