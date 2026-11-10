<?php

namespace App\Policies;

use App\Models\ClubNight;
use App\Models\User;

class ClubNightPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, ClubNight $clubNight): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, ClubNight $clubNight): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, ClubNight $clubNight): bool
    {
        return $user->isAdmin();
    }

    /**
     * Mark attendees present at the door.
     */
    public function checkIn(User $user, ClubNight $clubNight): bool
    {
        return $user->isAdmin();
    }

    /**
     * Join / leave (going or waitlist) until the night is over.
     */
    public function rsvp(User $user, ClubNight $clubNight): bool
    {
        return ! $clubNight->isPast();
    }
}
