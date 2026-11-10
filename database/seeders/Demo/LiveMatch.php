<?php

namespace Database\Seeders\Demo;

use App\Actions\Matches\SaveMatch;
use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Models\BadmintonMatch;

/**
 * The one match being played right now: a singles challenge announced two days ago (with
 * predictions), started 24 minutes ago, first game done and the second under way.
 */
final class LiveMatch
{
    public function __construct(private readonly World $world, private readonly Pickem $pickem) {}

    public function create(): BadmintonMatch
    {
        $world = $this->world;
        $now = $world->calendar->now;
        $start = $now->subMinutes(24);
        $announced = $now->subDays(2)->setTime(12, 10);
        $sideA = [$world->profile('jonas')->id];
        $sideB = [$world->profile('hao')->id];

        $world->travel($announced);

        $match = SaveMatch::handle([
            'discipline' => Discipline::Singles,
            'side_a' => $sideA,
            'side_b' => $sideB,
            'status' => MatchStatus::Scheduled,
            'scheduled_at' => $start,
            'court' => 2,
            'venue' => config()->string('smash.club.venue', 'BUas Sports Hall'),
            'best_of' => 3,
            'points_per_game' => 21,
            'is_rated' => true,
        ], null, $world->user('daan'));

        $world->announce($match->id, $announced);
        $this->pickem->place($match, $announced, $start->subMinutes(5));

        $rally = $world->simulator->rallyProbability($world->probability($sideA, $sideB, Discipline::Singles), 3, 21);
        $first = $world->simulator->game($rally, 21);
        $second = $world->simulator->partialGame($rally, 21, $world->play->int(16, 27));

        $world->travel($now->subMinutes(1));

        return SaveMatch::handle([
            'status' => MatchStatus::InProgress,
            'games' => [$first, $second],
        ], $match, $world->user('daan'));
    }
}
