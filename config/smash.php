<?php

/*
|--------------------------------------------------------------------------
| SMASH — BUas Badminton Club tunables
|--------------------------------------------------------------------------
|
| Rating (Elo) parameters, prediction model weights and club details. The
| outreach links are null unless configured in .env; the UI hides them.
|
*/

return [

    'rating' => [
        'initial' => 1500,
        'provisional_matches' => 10,
        'k_provisional' => 40,
        'k_established' => 24,
        'tournament_multiplier' => 1.25,
        'straight_games_bonus' => 0.10,
        'decider_factor' => 0.90,
        'max_point_bonus' => 0.20,
        'scale' => 400,
    ],

    'prediction' => [
        'form_window' => 5,
        'form_points_per_net_win' => 8,
        'form_cap' => 40,
        'h2h_max_shift' => 50,
    ],

    'upset_threshold' => 100,

    'club' => [
        'name' => 'SMASH',
        'full_name' => 'SMASH — BUas Badminton Club',
        'venue' => 'BUas Sports Hall',
        'courts' => 4,
        'email' => env('SMASH_EMAIL'),
        'instagram_url' => env('SMASH_INSTAGRAM_URL'),
        'whatsapp_url' => env('SMASH_WHATSAPP_URL'),
        'questionnaire_url' => env('SMASH_QUESTIONNAIRE_URL'),
    ],

];
