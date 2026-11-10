<?php

use App\Models\BadmintonMatch;
use App\Models\User;
use Livewire\Livewire;
use Tests\Feature\Domain\Support\Play;

test('deleting your own account removes your friendlies and recalculates everyone else', function () {
    Play::reset();
    $leaving = User::factory()->create();
    $opponent = User::factory()->create();

    Play::win($leaving, $opponent);

    expect($opponent->refresh()->singles_played)->toBe(1)
        ->and($opponent->singles_rating)->toBeLessThan(1500.0);

    $this->actingAs($leaving);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($leaving->fresh())->toBeNull()
        ->and(auth()->check())->toBeFalse()
        ->and(BadmintonMatch::query()->count())->toBe(0);

    $opponent->refresh();

    expect($opponent->singles_played)->toBe(0)
        ->and($opponent->singles_rating)->toBe(1500.0);
});
