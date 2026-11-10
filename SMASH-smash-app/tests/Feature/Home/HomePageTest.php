<?php

use App\Models\ClubNight;
use App\Models\Tournament;
use App\Models\User;

require_once __DIR__.'/HomeHelpers.php';

test('the landing page renders for guests with an empty database', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('SMASH it')
        ->assertSee('Join the club')
        ->assertSee('See the rankings')
        ->assertSee(route('register'), false)
        ->assertSee('New dates are on their way')
        ->assertSee('The first rally is still to come')
        ->assertSee('No tournaments on the calendar yet')
        ->assertSee('How SMASH works')
        ->assertSee('The club in numbers')
        ->assertSee('No fixtures to call right now');
});

test('the landing page shows the club alive with real data', function () {
    $wei = User::factory()->create(['name' => 'Wei Chen']);
    $lotte = User::factory()->create(['name' => 'Lotte Jansen']);
    $sam = User::factory()->create(['name' => 'Sam de Vries']);
    $mila = User::factory()->create(['name' => 'Mila Kowalski']);

    homePlay([$wei], [$lotte]);
    homePlay([$wei, $sam], [$lotte, $mila]);
    homeFixture($sam, $mila);

    ClubNight::factory()->upcoming()->capacity(24)->withRsvps(5)->create(['title' => 'Thursday social']);
    Tournament::factory()->open()->doubles()->create(['name' => 'Winter Doubles Showdown']);
    Tournament::factory()->draft()->create(['name' => 'Secret Draft Cup']);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Next club night')
        ->assertSee('Thursday social')
        ->assertSee('19 places left of 24')
        ->assertSee('Latest results')
        ->assertSee('Wei Chen')
        ->assertSee('Lotte Jansen')
        ->assertSee('Top of the rankings')
        ->assertSee('Winter Doubles Showdown')
        ->assertDontSee('Secret Draft Cup')
        ->assertSee('Model forecast')
        ->assertDontSee($wei->email);
});

test('logged-in members are sent to their dashboard instead of the sign-up', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Your dashboard')
        ->assertSee(route('dashboard'), false)
        ->assertSee('Keep smashing it forward');
});

test('the questionnaire band only appears when a questionnaire is configured', function () {
    config(['smash.club.questionnaire_url' => null]);
    $this->get(route('home'))->assertOk()->assertDontSee('Answer the questionnaire');

    config(['smash.club.questionnaire_url' => 'https://forms.example.test/smash']);
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Answer the questionnaire')
        ->assertSee('https://forms.example.test/smash', false);
});

test('completed tournaments fill the row when nothing is open', function () {
    Tournament::factory()->completed()->create(['name' => 'Welcome Week Open']);

    $this->get(route('home'))->assertOk()->assertSee('Welcome Week Open')->assertSee('See the results');
});
