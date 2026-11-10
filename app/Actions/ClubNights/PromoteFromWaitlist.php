<?php

namespace App\Actions\ClubNights;

use App\Enums\RsvpStatus;
use App\Models\ClubNight;
use Illuminate\Support\Facades\DB;

/**
 * Fill free places from the waitlist, first come first served. Called when somebody leaves;
 * call it after raising a night's capacity too.
 */
final class PromoteFromWaitlist
{
    /**
     * @return int how many RSVPs were promoted to going
     */
    public static function handle(ClubNight $night): int
    {
        return DB::transaction(static function () use ($night): int {
            $free = $night->capacity - $night->rsvps()->where('status', RsvpStatus::Going->value)->count();

            if ($free <= 0) {
                return 0;
            }

            $promoted = $night->rsvps()
                ->where('status', RsvpStatus::Waitlist->value)
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($free)
                ->get();

            foreach ($promoted as $rsvp) {
                $rsvp->status = RsvpStatus::Going;
                $rsvp->save();
            }

            $night->unsetRelation('rsvps');
            $night->unsetRelation('attendees');

            return $promoted->count();
        });
    }
}
