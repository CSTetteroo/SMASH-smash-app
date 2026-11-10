<?php

use App\Enums\ClubNightKind;
use App\Enums\Discipline;
use App\Enums\Handedness;
use App\Enums\MatchStatus;
use App\Enums\Programme;
use App\Enums\RsvpStatus;
use App\Enums\Side;
use App\Enums\SkillLevel;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Enums\UserRole;

dataset('enums', [
    ClubNightKind::class,
    Discipline::class,
    Handedness::class,
    MatchStatus::class,
    Programme::class,
    RsvpStatus::class,
    Side::class,
    SkillLevel::class,
    TournamentFormat::class,
    TournamentStatus::class,
    UserRole::class,
]);

test('every enum maps each value to a non-empty label', function (string $enum) {
    /** @var class-string<BackedEnum> $enum */
    $options = $enum::options();

    expect($options)->toHaveCount(count($enum::cases()));

    foreach ($enum::cases() as $case) {
        expect($options[$case->value])->toBe($case->label())->not->toBeEmpty();
    }

    expect($enum::values())->toBe(array_keys($options));
})->with('enums');

test('the backing values match the database contract', function () {
    expect(Discipline::values())->toBe(['singles', 'doubles'])
        ->and(MatchStatus::values())->toBe(['scheduled', 'in_progress', 'completed', 'walkover', 'cancelled'])
        ->and(Side::values())->toBe(['a', 'b'])
        ->and(TournamentFormat::values())->toBe(['single_elimination', 'round_robin'])
        ->and(TournamentStatus::values())->toBe(['draft', 'open', 'in_progress', 'completed'])
        ->and(ClubNightKind::values())->toBe(['social', 'training', 'clinic', 'ladder'])
        ->and(RsvpStatus::values())->toBe(['going', 'waitlist'])
        ->and(SkillLevel::values())->toBe(['beginner', 'intermediate', 'advanced', 'competitive'])
        ->and(Handedness::values())->toBe(['right', 'left'])
        ->and(UserRole::values())->toBe(['admin', 'member']);
});

test('discipline knows its players per side and user columns', function () {
    expect(Discipline::Singles->playersPerSide())->toBe(1)
        ->and(Discipline::Doubles->playersPerSide())->toBe(2)
        ->and(Discipline::Singles->ratingColumn())->toBe('singles_rating')
        ->and(Discipline::Doubles->ratingColumn())->toBe('doubles_rating')
        ->and(Discipline::Singles->peakColumn())->toBe('singles_peak')
        ->and(Discipline::Doubles->playedColumn())->toBe('doubles_played')
        ->and(Discipline::Singles->winsColumn())->toBe('singles_wins')
        ->and(Discipline::Doubles->icon())->toBe('user-group');
});

test('only completed matches and walkovers are finished', function () {
    $finished = array_values(array_filter(MatchStatus::cases(), fn (MatchStatus $status) => $status->isFinished()));

    expect($finished)->toBe([MatchStatus::Completed, MatchStatus::Walkover]);
});

test('sides have an opposite', function () {
    expect(Side::A->opposite())->toBe(Side::B)
        ->and(Side::B->opposite())->toBe(Side::A)
        ->and(Side::A->label())->toBe('Side A');
});

test('tones come from the design palette', function () {
    $tones = [
        ...array_map(fn (MatchStatus $case) => $case->tone(), MatchStatus::cases()),
        ...array_map(fn (TournamentStatus $case) => $case->tone(), TournamentStatus::cases()),
        ...array_map(fn (ClubNightKind $case) => $case->tone(), ClubNightKind::cases()),
        ...array_map(fn (RsvpStatus $case) => $case->tone(), RsvpStatus::cases()),
    ];

    expect(array_diff($tones, ['navy', 'orange', 'green', 'smash', 'sky', 'grey']))->toBeEmpty()
        ->and(MatchStatus::InProgress->tone())->toBe('smash');
});

test('programme labels follow the BUas academies', function () {
    expect(Programme::options())->toBe([
        'built_environment' => 'Built Environment',
        'data_science_ai' => 'Data Science & AI',
        'facility_management' => 'Facility Management',
        'games' => 'Games',
        'hotel' => 'Hotel',
        'imagineering' => 'Imagineering',
        'leisure_events' => 'Leisure & Events',
        'logistics' => 'Logistics',
        'media_creative_business' => 'Media & Creative Business',
        'tourism' => 'Tourism',
        'staff' => 'BUas staff',
    ]);
});

test('descriptive enums describe every case', function () {
    foreach ([...ClubNightKind::cases(), ...SkillLevel::cases(), ...TournamentFormat::cases()] as $case) {
        expect($case->description())->toBeString()->not->toBeEmpty();
    }
});
