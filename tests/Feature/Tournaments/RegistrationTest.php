<?php

use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use App\Services\Tournament\DrawGenerator;
use Livewire\Livewire;

test('a member registers for a singles tournament', function () {
    $tournament = Tournament::factory()->open()->singles()->create();
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test('pages::tournaments.show', ['tournament' => $tournament])
        ->assertSee('Register')
        ->call('register')
        ->assertHasNoErrors()
        ->assertSee("You're in");

    expect($tournament->entries()->sole())
        ->player_id->toBe($member->id)
        ->partner_id->toBeNull();
});

test('guests are sent to the login page to register', function () {
    $tournament = Tournament::factory()->open()->singles()->create();

    $this->get(route('tournaments.show', $tournament))->assertSee('Log in to register');

    Livewire::test('pages::tournaments.show', ['tournament' => $tournament])
        ->call('register')
        ->assertRedirect(route('login'));

    expect($tournament->entries()->count())->toBe(0);
});

test('doubles registration needs a valid partner', function () {
    $tournament = Tournament::factory()->open()->doubles()->create();
    $member = User::factory()->create();
    $partner = User::factory()->create();
    $inactive = User::factory()->inactive()->create();

    $page = Livewire::actingAs($member)->test('pages::tournaments.show', ['tournament' => $tournament]);

    $page->call('register')->assertHasErrors('partner');
    $page->set('partnerId', (string) $member->id)->call('register')->assertHasErrors('partner');
    $page->set('partnerId', (string) $inactive->id)->call('register')->assertHasErrors('partner');
    $page->set('partnerId', '999999')->call('register')->assertHasErrors('partner');

    expect($tournament->entries()->count())->toBe(0);

    $page->set('partnerId', (string) $partner->id)->call('register')->assertHasNoErrors();

    expect($tournament->entries()->sole())
        ->player_id->toBe($member->id)
        ->partner_id->toBe($partner->id);
});

test('doubles registration blocks players who are already entered', function () {
    $tournament = Tournament::factory()->open()->doubles()->create();
    [$sam, $lotte, $jan, $mila] = User::factory()->count(4)->create();
    TournamentEntry::factory()->for($tournament)->create(['player_id' => $sam->id, 'partner_id' => $lotte->id]);

    // Lotte is already in as Sam's partner.
    Livewire::actingAs($lotte)
        ->test('pages::tournaments.show', ['tournament' => $tournament])
        ->assertSee("You're in")
        ->set('partnerId', (string) $jan->id)
        ->call('register')
        ->assertHasErrors('tournament');

    // Jan cannot pick Sam, who is already entered.
    Livewire::actingAs($jan)
        ->test('pages::tournaments.show', ['tournament' => $tournament])
        ->set('partnerId', (string) $sam->id)
        ->call('register')
        ->assertHasErrors('partner');

    expect($tournament->entries()->count())->toBe(1);

    // Jan and Mila are both free.
    Livewire::actingAs($jan)
        ->test('pages::tournaments.show', ['tournament' => $tournament])
        ->set('partnerId', (string) $mila->id)
        ->call('register')
        ->assertHasNoErrors();

    expect($tournament->entries()->count())->toBe(2);
});

test('the partner list only offers active players who are not entered', function () {
    $tournament = Tournament::factory()->open()->doubles()->create();
    $member = User::factory()->create(['name' => 'Member Person']);
    $free = User::factory()->create(['name' => 'Free Agent']);
    $inactive = User::factory()->inactive()->create(['name' => 'Gone Quiet']);
    $entered = User::factory()->create(['name' => 'Already Paired']);
    TournamentEntry::factory()->for($tournament)->create(['player_id' => $entered->id]);

    Livewire::actingAs($member)
        ->test('pages::tournaments.show', ['tournament' => $tournament])
        ->assertSee('Register with partner')
        ->assertSeeHtml('value="'.$free->id.'"')
        ->assertDontSeeHtml('value="'.$inactive->id.'"')
        ->assertDontSeeHtml('value="'.$entered->id.'"')
        ->assertDontSeeHtml('value="'.$member->id.'"');
});

test('a full tournament blocks registration', function () {
    $tournament = Tournament::factory()->open()->singles()->maxEntries(2)->withEntries(2)->create();
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test('pages::tournaments.show', ['tournament' => $tournament])
        ->assertSee('This tournament is full')
        ->call('register')
        ->assertHasErrors('tournament');

    expect($tournament->entries()->count())->toBe(2);
});

test('registration is closed after the deadline and for drafts', function () {
    $closed = Tournament::factory()->open()->registrationClosed()->create();
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test('pages::tournaments.show', ['tournament' => $closed])
        ->assertSee('Registration has closed')
        ->call('register')
        ->assertHasErrors('tournament');

    expect($closed->entries()->count())->toBe(0);
});

test('inactive and unverified members cannot register', function () {
    $tournament = Tournament::factory()->open()->singles()->create();

    Livewire::actingAs(User::factory()->inactive()->create())
        ->test('pages::tournaments.show', ['tournament' => $tournament])
        ->call('register')
        ->assertHasErrors('tournament');

    Livewire::actingAs(User::factory()->unverified()->create())
        ->test('pages::tournaments.show', ['tournament' => $tournament])
        ->call('register')
        ->assertHasErrors('tournament');

    expect($tournament->entries()->count())->toBe(0);
});

test('members withdraw before the draw, not after', function () {
    $tournament = Tournament::factory()->open()->doubles()->create();
    [$sam, $lotte] = User::factory()->count(2)->create();
    TournamentEntry::factory()->for($tournament)->create(['player_id' => $sam->id, 'partner_id' => $lotte->id]);

    // The partner can withdraw the pair too.
    Livewire::actingAs($lotte)
        ->test('pages::tournaments.show', ['tournament' => $tournament])
        ->call('withdraw')
        ->assertHasNoErrors();

    expect($tournament->entries()->count())->toBe(0);

    TournamentEntry::factory()->for($tournament)->create(['player_id' => $sam->id, 'partner_id' => $lotte->id]);
    TournamentEntry::factory()->for($tournament)->create();
    DrawGenerator::generate($tournament);

    Livewire::actingAs($sam)
        ->test('pages::tournaments.show', ['tournament' => $tournament->fresh()])
        ->call('withdraw')
        ->assertHasErrors('tournament');

    expect($tournament->entries()->count())->toBe(2);
});

test('withdrawing without an entry is an error, and guests are refused', function () {
    $tournament = Tournament::factory()->open()->singles()->withEntries(2)->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::tournaments.show', ['tournament' => $tournament])
        ->call('withdraw')
        ->assertHasErrors('tournament');

    auth()->logout();

    Livewire::test('pages::tournaments.show', ['tournament' => $tournament])
        ->call('withdraw')
        ->assertForbidden();

    expect($tournament->entries()->count())->toBe(2);
});
