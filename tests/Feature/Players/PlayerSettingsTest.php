<?php

use App\Enums\Discipline;
use App\Enums\Handedness;
use App\Enums\Programme;
use App\Enums\SkillLevel;
use App\Enums\UserRole;
use App\Models\User;
use Livewire\Livewire;

test('guests are sent to the login page', function () {
    $this->get(route('player-profile.edit'))->assertRedirect(route('login'));
});

test('members can open their player profile settings', function () {
    $member = User::factory()->programme(Programme::Games)->create();

    $this->actingAs($member)
        ->get(route('player-profile.edit'))
        ->assertOk()
        ->assertSee('Player profile')
        ->assertSee('Programme');
});

test('the settings navigation links to the player profile', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee(route('player-profile.edit'));
});

test('the form is prefilled with the member\'s current profile', function () {
    $member = User::factory()->create([
        'programme' => Programme::Hotel,
        'level' => SkillLevel::Advanced,
        'handedness' => Handedness::Left,
        'bio' => 'Doubles specialist.',
    ]);

    Livewire::actingAs($member)
        ->test('pages::settings.player')
        ->assertSet('programme', 'hotel')
        ->assertSet('level', 'advanced')
        ->assertSet('handedness', 'left')
        ->assertSet('bio', 'Doubles specialist.');
});

test('members can update their own programme, level, handedness and bio', function () {
    $member = User::factory()->create(['programme' => null, 'level' => null, 'handedness' => null, 'bio' => null]);

    Livewire::actingAs($member)
        ->test('pages::settings.player')
        ->set('programme', 'data_science_ai')
        ->set('level', 'intermediate')
        ->set('handedness', 'right')
        ->set('bio', '  First season at SMASH, already hooked.  ')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $member->refresh();

    expect($member->programme)->toBe(Programme::DataScienceAi)
        ->and($member->level)->toBe(SkillLevel::Intermediate)
        ->and($member->handedness)->toBe(Handedness::Right)
        ->and($member->bio)->toBe('First season at SMASH, already hooked.');
});

test('members can clear optional fields', function () {
    $member = User::factory()->create(['programme' => Programme::Games, 'bio' => 'Old bio']);

    Livewire::actingAs($member)
        ->test('pages::settings.player')
        ->set('programme', '')
        ->set('bio', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($member->refresh()->programme)->toBeNull()->and($member->bio)->toBeNull();
});

test('invalid values are rejected', function () {
    $member = User::factory()->create(['programme' => Programme::Games]);

    Livewire::actingAs($member)
        ->test('pages::settings.player')
        ->set('programme', 'astrology')
        ->set('level', 'godlike')
        ->set('handedness', 'both')
        ->set('bio', str_repeat('a', 501))
        ->call('save')
        ->assertHasErrors(['programme', 'level', 'handedness', 'bio' => 'max']);

    expect($member->refresh()->programme)->toBe(Programme::Games);
});

test('members cannot escalate their role, activity or ratings through the form', function (string $property, mixed $value) {
    $member = User::factory()->withRating(Discipline::Singles, 1550, 12)->create();

    expect(fn () => Livewire::actingAs($member)
        ->test('pages::settings.player')
        ->set($property, $value)
        ->call('save'))
        ->toThrow(Exception::class);

    $member->refresh();

    expect($member->role)->toBe(UserRole::Member)
        ->and($member->is_active)->toBeTrue()
        ->and($member->singles_rating)->toBe(1550.0)
        ->and($member->singles_played)->toBe(12);
})->with([
    'role' => ['role', 'admin'],
    'is_active' => ['is_active', false],
    'singles_rating' => ['singles_rating', 2400],
    'singles_played' => ['singles_played', 99],
    'email' => ['email', 'hijack@smash.test'],
]);

test('saving only touches the logged-in member', function () {
    $member = User::factory()->create(['programme' => Programme::Games]);
    $other = User::factory()->create(['programme' => Programme::Hotel]);

    Livewire::actingAs($member)
        ->test('pages::settings.player')
        ->set('programme', 'tourism')
        ->call('save')
        ->assertHasNoErrors();

    expect($member->refresh()->programme)->toBe(Programme::Tourism)
        ->and($other->refresh()->programme)->toBe(Programme::Hotel);
});

test('unverified members can still edit their player profile', function () {
    $member = User::factory()->unverified()->create();

    $this->actingAs($member)->get(route('player-profile.edit'))->assertOk();
});
