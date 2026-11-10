<?php

/*
|--------------------------------------------------------------------------
| SMASH — BUas Badminton Club
|--------------------------------------------------------------------------
|
| Each feature area owns its own route file in routes/smash. Public pages
| (rankings, results, brackets, profiles) are visible to guests; acting
| requires an account; managing data requires the "manage" gate (admins).
|
*/

require __DIR__.'/smash/home.php';
require __DIR__.'/smash/rankings.php';
require __DIR__.'/smash/players.php';
require __DIR__.'/smash/matches.php';
require __DIR__.'/smash/live.php';
require __DIR__.'/smash/tournaments.php';
require __DIR__.'/smash/club-nights.php';
require __DIR__.'/smash/predictions.php';
require __DIR__.'/smash/ratings.php';

require __DIR__.'/settings.php';

if (app()->isLocal()) {
    require __DIR__.'/dev.php';
}
