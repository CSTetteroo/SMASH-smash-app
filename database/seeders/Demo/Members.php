<?php

namespace Database\Seeders\Demo;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Creates the members in user id order with believable sign-up dates: the founders in spring,
 * most members over the summer and in welcome week, a few during the season, and four brand-new
 * members in the last week. Players who stopped (exchange over, internship, injury) are switched
 * to inactive on the timeline the day after their last week.
 */
final class Members
{
    public function __construct(private readonly World $world) {}

    public function create(string $passwordHash): void
    {
        $calendar = $this->world->calendar;
        $founded = $calendar->seasonStart->subWeeks(20)->setTime(20, 15);
        $preSeason = array_values(array_filter(
            $this->world->profiles,
            fn (PlayerProfile $player): bool => ! $player->isNew && $player->joinedWeek <= 0,
        ));
        $preSeasonSpan = $calendar->seasonStart->subDays(2)->getTimestamp() - $founded->getTimestamp();
        $newDays = [6, 4, 2, 1];
        $index = 0;
        $newIndex = 0;

        foreach ($this->world->profiles as $player) {
            if ($player->isNew) {
                $daysAgo = $newDays[$newIndex % count($newDays)];
                $joinedAt = $calendar->today->subDays($daysAgo)->setTime(12, 40)->addMinutes(37 * $newIndex);
                $newIndex++;
            } elseif ($player->joinedWeek <= 0) {
                $joinedAt = $founded->addSeconds((int) ($preSeasonSpan * ($index / max(1, count($preSeason)))));
                $index++;
            } else {
                $joinedAt = $calendar->at($player->joinedWeek, -2, '18:00')->addMinutes(13 * $player->joinedWeek + strlen($player->key));
            }

            $this->world->travel($joinedAt);

            $user = new User;
            $user->forceFill([
                'name' => $player->name,
                'email' => $player->email,
                'email_verified_at' => $joinedAt->addMinutes(3),
                'password' => $passwordHash,
                'role' => $player->admin ? UserRole::Admin : UserRole::Member,
                'programme' => $player->programme,
                'level' => $player->level,
                'handedness' => $player->handedness,
                'bio' => $player->bio,
                'is_active' => true,
            ])->save();

            $player->id = $user->id;
            $this->world->byId[$user->id] = $player;
            $this->world->users[$user->id] = $user;
        }
    }

    /**
     * Players who left are marked inactive the day after their last club week.
     */
    public function schedule(Timeline $timeline): void
    {
        foreach ($this->world->profiles as $player) {
            if ($player->leftWeek === null) {
                continue;
            }

            $user = $this->world->userById($player->id);
            $moment = $this->world->calendar->at($player->leftWeek, 3, '10:30');

            if ($moment->greaterThan($this->world->calendar->now)) {
                $moment = $this->world->calendar->now->subHour();
            }

            $timeline->add($moment, function () use ($user): void {
                $user->forceFill(['is_active' => false])->save();
            });
        }
    }
}
