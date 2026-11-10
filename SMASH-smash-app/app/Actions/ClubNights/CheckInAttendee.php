<?php

namespace App\Actions\ClubNights;

use App\Enums\RsvpStatus;
use App\Models\ClubNightRsvp;
use Carbon\CarbonImmutable;

/**
 * Mark an attendee present (or not) at the door. Somebody on the waitlist who is checked in
 * has turned up and played, so they become "going".
 */
final class CheckInAttendee
{
    public static function handle(ClubNightRsvp $rsvp, bool $present = true): void
    {
        if ($present) {
            $rsvp->checked_in_at ??= CarbonImmutable::now();
            $rsvp->status = RsvpStatus::Going;
        } else {
            $rsvp->checked_in_at = null;
        }

        $rsvp->save();
    }
}
