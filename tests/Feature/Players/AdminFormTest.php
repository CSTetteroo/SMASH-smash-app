<?php

use App\Enums\Discipline;
use App\Enums\Handedness;
use App\Enums\Programme;
use App\Enums\SkillLevel;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

// ---------------------------------------------------------------------------
// Access matrix
// ---------------------------------------------------------------------------

test('guests are sent to the login page from the admin player pages', function () {
    $sam = User::factory()->create();

    $this->get(route('players.create'))->assertRedirect(route('login'));
    $this->get(route('players.edit', $sam))->assertRedirect(route('login'));
});

test('members cannot open the admin player pages', function () {
    $member = User::factory()->create();
    $sam = User::factory()->create();

    $this->actingAs($member)->get(route('players.create'))->assertForbidden();
    $this->actingAs($member)->get(route('players.edit', $sam))->assertForbidden();
    $this->actingAs($member)->get(route('players.edit', $member))->assertForbidden();
});

test('admins can open the admin player pages', function () {
    $admin = User::factory()->admin()->create();
    $sam = User::factory()->create(['name' => 'Sam de Vries']);

    $this->actingAs($admin)->get(route('players.create'))->assertOk()->assertSee('New player');
    $this->actingAs($admin)->get(route('players.edit', $sam))->assertOk()->assertSee('Sam de Vries');
});

test('members cannot mount the admin components directly', function () {
    $member = User::factory()->create();
    $sam = User::factory()->create();

    Livewire::actingAs($member)->test('pages::players.create')->assertForbidden();
    Livewire::actingAs($member)->test('pages::players.edit', ['user' => $sam])->assertForbidden();
});

test('the save and delete actions re-check authorisation on every call', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $sam = User::factory()->create(['name' => 'Sam de Vries']);

    $create = Livewire::actingAs($admin)->test('pages::players.create')
        ->set('name', 'Sneaky Sid')->set('email', 'sid@smash.test')->set('role', 'admin');
    $edit = Livewire::actingAs($admin)->test('pages::players.edit', ['user' => $sam])->set('role', 'admin');
    $delete = Livewire::actingAs($admin)->test('pages::players.edit', ['user' => $sam]);

    // The session now belongs to a member (e.g. a snapshot replayed by somebody else).
    $this->actingAs($member);

    $create->call('save')->assertForbidden();
    $edit->call('save')->assertForbidden();
    $delete->call('delete')->assertForbidden();

    expect(User::query()->where('email', 'sid@smash.test')->exists())->toBeFalse()
        ->and($sam->refresh()->role)->toBe(UserRole::Member)
        ->and($sam->exists)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Create
// ---------------------------------------------------------------------------

test('admins can add a player with a password', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::players.create')
        ->set('name', '  Sam de Vries ')
        ->set('email', 'Sam.DeVries@smash.test')
        ->set('password', 'shuttle-2026')
        ->set('role', 'member')
        ->set('programme', 'games')
        ->set('level', 'intermediate')
        ->set('handedness', 'left')
        ->set('bio', 'Left-handed, loves a cross-court drop.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $sam = User::query()->where('email', 'sam.devries@smash.test')->firstOrFail();

    expect($sam->name)->toBe('Sam de Vries')
        ->and($sam->role)->toBe(UserRole::Member)
        ->and($sam->programme)->toBe(Programme::Games)
        ->and($sam->level)->toBe(SkillLevel::Intermediate)
        ->and($sam->handedness)->toBe(Handedness::Left)
        ->and($sam->bio)->toBe('Left-handed, loves a cross-court drop.')
        ->and($sam->is_active)->toBeTrue()
        ->and($sam->email_verified_at)->not->toBeNull()
        ->and($sam->singles_rating)->toBe(1500.0)
        ->and(Hash::check('shuttle-2026', $sam->password))->toBeTrue();
});

test('a random password is generated when none is given', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::players.create')
        ->set('name', 'Lotte Jansen')
        ->set('email', 'lotte@smash.test')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $lotte = User::query()->where('email', 'lotte@smash.test')->firstOrFail();

    expect($lotte->password)->not->toBeEmpty()
        ->and(Hash::check('password', $lotte->password))->toBeFalse()
        ->and($lotte->programme)->toBeNull()
        ->and($lotte->level)->toBeNull()
        ->and($lotte->bio)->toBeNull();
});

test('admins can add another admin or an inactive player', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::players.create')
        ->set('name', 'Daan Visser')
        ->set('email', 'daan@smash.test')
        ->set('role', 'admin')
        ->set('isActive', false)
        ->call('save')
        ->assertHasNoErrors();

    $daan = User::query()->where('email', 'daan@smash.test')->firstOrFail();

    expect($daan->role)->toBe(UserRole::Admin)->and($daan->is_active)->toBeFalse();
});

test('creating a player validates the input', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['email' => 'taken@smash.test']);

    Livewire::actingAs($admin)
        ->test('pages::players.create')
        ->call('save')
        ->assertHasErrors(['name' => 'required', 'email' => 'required'])
        ->set('name', 'Sam de Vries')
        ->set('email', 'not-an-email')
        ->call('save')
        ->assertHasErrors(['email' => 'email'])
        ->set('email', 'TAKEN@smash.test')
        ->call('save')
        ->assertHasErrors(['email' => 'unique'])
        ->assertSee('Another member already uses this email address.')
        ->set('email', 'sam@smash.test')
        ->set('password', 'short')
        ->set('role', 'overlord')
        ->set('programme', 'astrology')
        ->set('level', 'godlike')
        ->set('handedness', 'both')
        ->set('bio', str_repeat('a', 501))
        ->call('save')
        ->assertHasErrors(['password', 'role', 'programme', 'level', 'handedness', 'bio' => 'max']);

    expect(User::query()->where('email', 'sam@smash.test')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Edit
// ---------------------------------------------------------------------------

test('admins can edit a player', function () {
    $admin = User::factory()->admin()->create();
    $sam = User::factory()->withRating(Discipline::Singles, 1620, 12)->create(['name' => 'Sam de Vries', 'email' => 'sam@smash.test']);

    Livewire::actingAs($admin)
        ->test('pages::players.edit', ['user' => $sam])
        ->assertSet('name', 'Sam de Vries')
        ->assertSet('email', 'sam@smash.test')
        ->set('name', 'Samuel de Vries')
        ->set('email', 'samuel@smash.test')
        ->set('role', 'admin')
        ->set('programme', 'hotel')
        ->set('level', 'competitive')
        ->set('handedness', 'right')
        ->set('bio', '')
        ->set('isActive', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('players.show', $sam));

    $sam->refresh();

    expect($sam->name)->toBe('Samuel de Vries')
        ->and($sam->email)->toBe('samuel@smash.test')
        ->and($sam->role)->toBe(UserRole::Admin)
        ->and($sam->programme)->toBe(Programme::Hotel)
        ->and($sam->level)->toBe(SkillLevel::Competitive)
        ->and($sam->handedness)->toBe(Handedness::Right)
        ->and($sam->bio)->toBeNull()
        ->and($sam->is_active)->toBeFalse()
        ->and($sam->singles_rating)->toBe(1620.0); // ratings are never touched by the form
});

test('editing keeps the own email address valid and rejects somebody else\'s', function () {
    $admin = User::factory()->admin()->create();
    $sam = User::factory()->create(['email' => 'sam@smash.test']);
    User::factory()->create(['email' => 'lotte@smash.test']);

    Livewire::actingAs($admin)
        ->test('pages::players.edit', ['user' => $sam])
        ->call('save')
        ->assertHasNoErrors()
        ->set('email', 'lotte@smash.test')
        ->call('save')
        ->assertHasErrors(['email' => 'unique'])
        ->set('name', '')
        ->set('level', 'godlike')
        ->call('save')
        ->assertHasErrors(['name' => 'required', 'level']);

    expect($sam->refresh()->email)->toBe('sam@smash.test');
});

test('admins cannot demote themselves', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::players.edit', ['user' => $admin])
        ->assertSee('This is your own account')
        ->set('role', 'member')
        ->call('save')
        ->assertHasErrors(['role'])
        ->assertNoRedirect();

    expect($admin->refresh()->role)->toBe(UserRole::Admin);
});

test('admins cannot deactivate themselves from the form', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::players.edit', ['user' => $admin])
        ->set('isActive', false)
        ->call('save')
        ->assertHasErrors(['isActive']);

    expect($admin->refresh()->is_active)->toBeTrue();
});

test('admins can still edit their own name and profile', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::players.edit', ['user' => $admin])
        ->set('name', 'Noor van Dijk')
        ->set('programme', 'leisure_events')
        ->call('save')
        ->assertHasNoErrors();

    expect($admin->refresh()->name)->toBe('Noor van Dijk')
        ->and($admin->programme)->toBe(Programme::LeisureEvents)
        ->and($admin->role)->toBe(UserRole::Admin);
});

test('the edited player cannot be swapped by tampering with the id', function () {
    $admin = User::factory()->admin()->create();
    $sam = User::factory()->create();
    $other = User::factory()->create();

    expect(fn () => Livewire::actingAs($admin)
        ->test('pages::players.edit', ['user' => $sam])
        ->set('user', $other->id))
        ->toThrow(Exception::class);
});

test('admins can delete a player from the edit page but not themselves', function () {
    $admin = User::factory()->admin()->create();
    $sam = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::players.edit', ['user' => $admin])
        ->assertDontSee('Delete player')
        ->call('delete')
        ->assertForbidden();

    Livewire::actingAs($admin)
        ->test('pages::players.edit', ['user' => $sam])
        ->assertSee('Delete player')
        ->call('delete')
        ->assertRedirect(route('players.index'));

    expect($sam->fresh())->toBeNull()->and($admin->fresh())->not->toBeNull();
});
