<?php

use App\Models\RatingAdjustment;
use App\Models\User;
use App\Services\Rating\RatingEngine;
use Tests\Feature\Domain\Support\Play;

test('a profile says when a rating includes manual adjustments, and admins get a link to the log', function () {
    Play::reset();
    $player = User::factory()->create();
    Play::win($player, User::factory()->create());

    RatingAdjustment::factory()->for($player)->singles()->amount(40)->create(['effective_at' => now()->subYear()]);
    RatingAdjustment::factory()->for($player)->singles()->amount(-10)->create(['effective_at' => now()->subYear()]);
    RatingEngine::recalculate();

    $link = route('rating-adjustments.index', ['player' => $player->id, 'discipline' => 'singles']);

    $this->get(route('players.show', $player))
        ->assertOk()
        ->assertSee('+30 by 2 adjustments')
        ->assertDontSee($link);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('players.show', $player))
        ->assertOk()
        ->assertSee($link);
});

test('a profile without adjustments shows no note', function () {
    Play::reset();
    $player = User::factory()->create();
    Play::win($player, User::factory()->create());

    $this->get(route('players.show', $player))
        ->assertOk()
        ->assertDontSee('data-test="rating-adjusted-', false);
});
