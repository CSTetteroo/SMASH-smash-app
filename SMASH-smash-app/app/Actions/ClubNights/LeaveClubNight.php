<?php

namespace App\Actions\ClubNights;

use App\Models\ClubNight;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancel an RSVP. A freed place goes to the earliest RSVP on the waitlist. Nothing happens when
 * the user had not RSVP'd.
 */
final class LeaveClubNight
{
    /**
     * @throws ValidationException (key "rsvp") when the night is over
     */
    public static function handle(ClubNight $night, User $user): void
    {
        if ($night->isPast()) {
            throw ValidationException::withMessages(['rsvp' => 'This club night has already finished.']);
        }

        DB::transaction(static function () use ($night, $user): void {
            $night->rsvps()->where('user_id', $user->id)->delete();

            PromoteFromWaitlist::handle($night);
        });
    }
}
