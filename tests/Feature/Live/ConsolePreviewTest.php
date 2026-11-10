<?php

use App\Models\BadmintonMatch;
use App\Models\User;

/*
 | The local-only console preview (dev.live-preview) renders every scoring situation in memory,
 | which doubles as a render test of each state of the console. It never saves anything.
 */

function previewMatch(): BadmintonMatch
{
    return BadmintonMatch::factory()->singles()->scheduled()
        ->withPlayers(User::factory()->create(['name' => 'Lucas Janssen']), User::factory()->create(['name' => 'Emre Yilmaz']))
        ->create();
}

test('only the committee can open the console preview', function () {
    $match = previewMatch();
    $url = route('dev.live-preview', ['match' => $match, 'scenario' => 'rally']);

    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get($url)->assertOk();
});

test('every scoring situation renders without saving anything', function (string $scenario, array $expected) {
    $match = previewMatch();
    $before = $match->fresh()->toArray();

    $response = $this->actingAs(User::factory()->admin()->create())
        ->get(route('dev.live-preview', ['match' => $match, 'scenario' => $scenario]))
        ->assertOk();

    foreach ($expected as $text) {
        $response->assertSee($text);
    }

    expect($match->fresh()->toArray())->toBe($before)
        ->and($match->games()->count())->toBe(0);
})->with([
    'start' => ['start', ['Who serves first?', 'Start match', 'Serves first?']],
    'serve' => ['serve', ['Who serves next?', 'Serves next']],
    'interval' => ['interval', ['Interval', '11–7 to Janssen']],
    'game' => ['game', ['Game 1 to Janssen, 21–19', 'Swap sides']],
    'rally' => ['rally', ['Game 2', 'Janssen serving from the right', 'Receiving']],
    'setting' => ['setting', ['Setting', 'From 20-all a side needs a two-point lead']],
    'golden' => ['golden', ['Golden point', 'the next rally wins the game']],
    'change ends' => ['change-ends', ['Interval · change ends', 'Deciding game']],
    'match point' => ['match-point', ['Match point', 'Deciding game']],
    'decided' => ['decided', ['Game, set and match', 'Lucas Janssen wins 2–1', 'Confirm result']],
]);

test('an unknown scenario is not found', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dev.live-preview', ['match' => previewMatch(), 'scenario' => 'nonsense']))
        ->assertNotFound();
});
