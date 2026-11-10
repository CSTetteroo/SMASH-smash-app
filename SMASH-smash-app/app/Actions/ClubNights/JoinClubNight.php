<?php

namespace App\Actions\ClubNights;

use App\Enums\RsvpStatus;
use App\Models\ClubNight;
use App\Models\ClubNightRsvp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RSVP for a club night: going while there are places, otherwise on the waitlist.
 * Idempotent: an existing RSVP is returned unchanged.
 */
final class JoinClubNight
{
    /**
     * @throws ValidationException (key "rsvp") when the night is over
     */
    public static function handle(ClubNight $night, User $user): ClubNightRsvp
    {
        if ($night->isPast()) {
            throw ValidationException::withMessages(['rsvp' => 'This club night has already finished.']);
        }

        return DB::transaction(static function () use ($night, $user): ClubNightRsvp {
            $existing = $night->rsvps()->where('user_id', $user->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            // Nobody joins ahead of the waitlist when places have opened up.
            PromoteFromWaitlist::handle($night);

            $going = $night->rsvps()->where('status', RsvpStatus::Going->value)->count();

            $rsvp = $night->rsvps()->create([
                'user_id' => $user->id,
                'status' => $going < $night->capacity ? RsvpStatus::Going : RsvpStatus::Waitlist,
            ]);

            $night->unsetRelation('rsvps');
            $night->unsetRelation('attendees');

            return $rsvp;
        });
    }
}
