<?php

use App\Models\RatingAdjustment;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

test('amounts are shown signed, with a real minus and no needless decimals', function (float $amount, string $shown) {
    expect(RatingAdjustment::format($amount))->toBe($shown)
        ->and((new RatingAdjustment(['amount' => $amount]))->formattedAmount())->toBe($shown);
})->with([
    [150.0, '+150'],
    [-25.0, '−25'],
    [12.5, '+12.5'],
    [-0.75, '−0.75'],
    [200.004, '+200'],
    [0.0, '0'],
    [-0.001, '0'],
]);

test('the season starts on 1 September', function (string $at, string $start) {
    expect(RatingAdjustment::seasonStart(CarbonImmutable::parse($at))->format('Y-m-d H:i:s'))->toBe($start);
})->with([
    ['2026-11-04 15:00:00', '2026-09-01 00:00:00'],
    ['2026-09-01 00:00:00', '2026-09-01 00:00:00'],
    ['2026-08-31 23:59:00', '2025-09-01 00:00:00'],
    ['2027-01-10 10:00:00', '2026-09-01 00:00:00'],
]);

test('the season start keeps the timezone it was asked in', function () {
    $start = RatingAdjustment::seasonStart(CarbonImmutable::parse('2026-11-04 15:00:00', 'Europe/Amsterdam'));

    expect($start->getTimezone()->getName())->toBe('Europe/Amsterdam')
        ->and($start->format('Y-m-d H:i'))->toBe('2026-09-01 00:00');
});

test('a record counts as edited only when it changed after it was created', function () {
    $adjustment = new RatingAdjustment;
    $adjustment->created_at = CarbonImmutable::parse('2026-11-03 21:00:00');
    $adjustment->updated_at = CarbonImmutable::parse('2026-11-03 21:00:00');

    expect($adjustment->wasEdited())->toBeFalse();

    $adjustment->updated_at = CarbonImmutable::parse('2026-11-04 09:12:00');

    expect($adjustment->wasEdited())->toBeTrue();
});
