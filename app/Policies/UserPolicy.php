<?php

namespace App\Policies;

use App\Models\User;

/**
 * Player administration (members edit their own profile via /settings, not via this policy).
 */
class UserPolicy
{
    /**
     * The player directory is public.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, User $player): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $player): bool
    {
        return $user->isAdmin();
    }

    /**
     * Admins may delete players, but never themselves.
     */
    public function delete(User $user, User $player): bool
    {
        return $user->isAdmin() && $user->isNot($player);
    }
}
