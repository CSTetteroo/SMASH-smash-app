<?php

namespace Database\Seeders;

use App\Services\Rating\RatingEngine;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\ClubNights;
use Database\Seeders\Demo\LiveMatch;
use Database\Seeders\Demo\Members;
use Database\Seeders\Demo\Pickem;
use Database\Seeders\Demo\Timeline;
use Database\Seeders\Demo\Tournaments;
use Database\Seeders\Demo\World;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The SMASH demo world: 66 members, a season of club nights with RSVPs, check-ins and
 * friendlies, five tournaments, pick'em predictions and one live match.
 *
 * Deterministic (fixed random seeds) and anchored on the moment it runs: the season started on
 * the Tuesday about nine weeks ago and the story is replayed in order with the clock set to each
 * moment, through the app's own actions, so timestamps, waitlists, draws and ratings come out as
 * they would have in real life. Demo logins: admin@smash.test / member@smash.test, password "password".
 */
class DemoSeeder extends Seeder
{
    /**
     * Which roll of the (seeded) dice the demo uses. Every variant follows the same rules; this
     * one was picked because its rankings, upsets and demo-account stories read best.
     */
    public const VARIANT = 17;

    public function run(): void
    {
        $world = new World(CarbonImmutable::now(), self::VARIANT);

        try {
            DB::transaction(function () use ($world): void {
                $this->build($world);
            });
        } finally {
            $world->travelBack();
        }
    }

    private function build(World $world): void
    {
        $members = new Members($world);
        $members->create(Hash::make('password'));

        $nights = new ClubNights($world);
        $nights->create();

        $timeline = new Timeline;
        $members->schedule($timeline);
        $nights->schedule($timeline);
        (new Tournaments($world))->schedule($timeline);
        $timeline->run($world);

        $pickem = new Pickem($world);
        $pickem->history();
        (new LiveMatch($world, $pickem))->create();
        $pickem->open();

        $world->travel($world->calendar->now);
        RatingEngine::recalculate();
    }
}
