<?php

namespace App\Policies;

use App\Models\RatingAdjustment;
use App\Models\User;

/**
 * Manual rating adjustments are committee business: only admins (the "manage" gate) see the log
 * or add, edit and delete adjustments. Guests never pass (no nullable user).
 */
class RatingAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, RatingAdjustment $adjustment): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, RatingAdjustment $adjustment): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, RatingAdjustment $adjustment): bool
    {
        return $user->isAdmin();
    }
}
