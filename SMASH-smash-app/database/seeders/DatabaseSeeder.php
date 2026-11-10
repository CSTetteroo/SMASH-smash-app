<?php

namespace Database\Seeders;

use App\Services\Rating\RatingEngine;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with the SMASH demo world.
     *
     * Order matters: the demo world (members, club nights, matches, tournaments, pick'em, the live
     * match), then the audited rating adjustments, then one final recalculation so every stored
     * rating reflects all matches and adjustments.
     *
     * Model events stay on: tournaments get their slugs from a creating hook.
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
        $this->call(RatingAdjustmentSeeder::class);

        RatingEngine::recalculate();
    }
}
