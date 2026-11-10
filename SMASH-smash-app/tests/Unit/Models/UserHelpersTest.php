<?php

use App\Enums\Discipline;
use App\Enums\UserRole;
use App\Models\User;
use Tests\TestCase;

uses(TestCase::class);

test('a new user starts as an active member at the initial rating', function () {
    $user = new User(['name' => 'Sam de Vries']);

    expect($user->role)->toBe(UserRole::Member)
        ->and($user->isAdmin())->toBeFalse()
        ->and($user->is_active)->toBeTrue()
        ->and($user->singles_rating)->toBe(1500.0)
        ->and($user->doubles_peak)->toBe(1500.0)
        ->and($user->rating(Discipline::Singles))->toBe(1500)
        ->and($user->played(Discipline::Doubles))->toBe(0)
        ->and($user->winRate(Discipline::Singles))->toBeNull()
        ->and($user->isProvisional(Discipline::Singles))->toBeTrue();
});

test('rating helpers read the discipline columns', function () {
    $user = (new User)->forceFill([
        'name' => 'Lotte Jansen',
        'role' => UserRole::Admin,
        'singles_rating' => 1612.6,
        'singles_peak' => 1650.4,
        'singles_played' => 14,
        'singles_wins' => 9,
        'doubles_rating' => 1488.2,
        'doubles_peak' => 1500.0,
        'doubles_played' => 3,
        'doubles_wins' => 1,
    ]);

    expect($user->isAdmin())->toBeTrue()
        ->and($user->rating(Discipline::Singles))->toBe(1613)
        ->and($user->ratingExact(Discipline::Singles))->toBe(1612.6)
        ->and($user->peak(Discipline::Singles))->toBe(1650)
        ->and($user->played(Discipline::Singles))->toBe(14)
        ->and($user->wins(Discipline::Singles))->toBe(9)
        ->and($user->losses(Discipline::Singles))->toBe(5)
        ->and($user->winRate(Discipline::Singles))->toBe(9 / 14)
        ->and($user->isProvisional(Discipline::Singles))->toBeFalse()
        ->and($user->rating(Discipline::Doubles))->toBe(1488)
        ->and($user->losses(Discipline::Doubles))->toBe(2)
        ->and($user->isProvisional(Discipline::Doubles))->toBeTrue();
});

test('the provisional threshold comes from config', function () {
    $user = (new User)->forceFill(['name' => 'X Y', 'singles_played' => 4]);

    config(['smash.rating.provisional_matches' => 4]);
    expect($user->isProvisional(Discipline::Singles))->toBeFalse();

    config(['smash.rating.provisional_matches' => 5]);
    expect($user->isProvisional(Discipline::Singles))->toBeTrue();
});

test('names split into first and family name, keeping particles', function (string $name, string $first, string $last, string $initials) {
    $user = new User(['name' => $name]);

    expect($user->firstName())->toBe($first)
        ->and($user->lastName())->toBe($last)
        ->and($user->initials())->toBe($initials);
})->with([
    ['Sam de Vries', 'Sam', 'de Vries', 'SV'],
    ['Lotte Jansen', 'Lotte', 'Jansen', 'LJ'],
    ['Jan van der Berg', 'Jan', 'van der Berg', 'JB'],
    ['Ana María de la Cruz', 'Ana', 'de la Cruz', 'AC'],
    ['Anna Maria Rossi', 'Anna', 'Rossi', 'AR'],
    ['Lukas von Stein', 'Lukas', 'von Stein', 'LS'],
    ['  Mehmet   Yılmaz ', 'Mehmet', 'Yılmaz', 'MY'],
    ['Wei', 'Wei', 'Wei', 'W'],
]);
