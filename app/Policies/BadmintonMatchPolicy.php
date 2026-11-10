<?php

namespace App\Policies;

use App\Enums\MatchStatus;
use App\Models\BadmintonMatch;
use App\Models\User;

class BadmintonMatchPolicy
{
    /**
     * Results and fixtures are public.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, BadmintonMatch $match): bool
    {
        return true;
    }

    /**
     * Any member may log a match; the form limits members to matches they played in.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, BadmintonMatch $match): bool
    {
        return $user->isAdmin();
    }

    /**
     * Tournament matches belong to the draw: reset the draw instead.
     */
    public function delete(User $user, BadmintonMatch $match): bool
    {
        return $user->isAdmin() && $match->isFriendly();
    }

    /**
     * Live scoring: admins always; participants while the match is still to be decided
     * (scheduled or in progress, so not once finished or cancelled).
     */
    public function score(User $user, BadmintonMatch $match): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return in_array($match->status, [MatchStatus::Scheduled, MatchStatus::InProgress], true)
            && $match->hasParticipant($user);
    }

    /**
     * Pick'em: members who are not playing, on scheduled matches that have not started yet.
     */
    public function predict(User $user, BadmintonMatch $match): bool
    {
        return $match->status === MatchStatus::Scheduled
            && ($match->scheduled_at === null || $match->scheduled_at->isFuture())
            && ! $match->hasParticipant($user);
    }
}
