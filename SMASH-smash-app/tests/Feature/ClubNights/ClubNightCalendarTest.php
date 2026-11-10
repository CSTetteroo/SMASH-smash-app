<?php

use App\Enums\ClubNightKind;
use App\Models\ClubNight;
use Carbon\CarbonImmutable;

/*
| The iCalendar feed (GET /club-nights.ics) and the single-night "Add to calendar" file.
*/

/**
 * Unfold RFC 5545 content lines (CRLF + space continues the previous line).
 */
function unfoldIcs(string $ics): string
{
    return str_replace("\r\n ", '', $ics);
}

test('the feed lists upcoming club nights as valid events', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00'));

    $night = ClubNight::factory()->create([
        'title' => 'Thursday social',
        'venue' => 'BUas Sports Hall',
        'starts_at' => '2026-11-12 20:00',
        'ends_at' => '2026-11-12 22:30',
    ]);

    $response = $this->get(route('club-nights.ics'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/calendar');

    $ics = unfoldIcs($response->getContent());

    expect($ics)
        ->toStartWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n")
        ->toContain('PRODID:')
        ->toContain("BEGIN:VEVENT\r\n")
        ->toContain('UID:club-night-'.$night->id.'@')
        // Breda is UTC+1 in November: 20:00 local = 19:00Z.
        ->toContain("DTSTART:20261112T190000Z\r\n")
        ->toContain("DTEND:20261112T213000Z\r\n")
        ->toContain('SUMMARY:Thursday social · SMASH')
        ->toContain('LOCATION:BUas Sports Hall')
        ->toContain('URL:'.route('club-nights.show', $night))
        ->toContain("END:VEVENT\r\n")
        ->toEndWith("END:VCALENDAR\r\n");

    // Every physical line stays within 75 octets.
    foreach (explode("\r\n", rtrim($response->getContent())) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }
});

test('the feed leaves out old nights and can be filtered by kind', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00'));

    $training = ClubNight::factory()->create(['title' => 'Tuesday training', 'kind' => ClubNightKind::Training, 'starts_at' => '2026-11-10 19:30', 'ends_at' => '2026-11-10 22:00']);
    $social = ClubNight::factory()->create(['title' => 'Thursday social', 'kind' => ClubNightKind::Social, 'starts_at' => '2026-11-12 20:00', 'ends_at' => '2026-11-12 22:30']);
    $old = ClubNight::factory()->create(['title' => 'Welcome social', 'starts_at' => '2026-09-03 20:00', 'ends_at' => '2026-09-03 22:30']);

    $all = unfoldIcs($this->get(route('club-nights.ics'))->getContent());

    expect(substr_count($all, 'BEGIN:VEVENT'))->toBe(2)
        ->and($all)->toContain('UID:club-night-'.$training->id.'@')
        ->and($all)->toContain('UID:club-night-'.$social->id.'@')
        ->and($all)->not->toContain('UID:club-night-'.$old->id.'@');

    $filtered = unfoldIcs($this->get(route('club-nights.ics', ['kind' => 'training']))->getContent());

    expect(substr_count($filtered, 'BEGIN:VEVENT'))->toBe(1)
        ->and($filtered)->toContain('UID:club-night-'.$training->id.'@');
});

test('text values are escaped', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00'));

    ClubNight::factory()->create([
        'title' => 'Social; doubles, mixed',
        'description' => "Line one\nLine two",
        'starts_at' => '2026-11-12 20:00',
        'ends_at' => '2026-11-12 22:30',
    ]);

    $ics = unfoldIcs($this->get(route('club-nights.ics'))->getContent());

    expect($ics)->toContain('SUMMARY:Social\; doubles\, mixed · SMASH')
        ->toContain('Line one\nLine two');
});

test('a single night can be downloaded on its own', function () {
    $night = ClubNight::factory()->upcoming()->create();
    ClubNight::factory()->upcoming()->create();

    $response = $this->get(route('club-nights.show.ics', $night));

    $response->assertOk();
    expect(substr_count($response->getContent(), 'BEGIN:VEVENT'))->toBe(1)
        ->and(unfoldIcs($response->getContent()))->toContain('UID:club-night-'.$night->id.'@');

    $this->get('/club-nights/999999.ics')->assertNotFound();
});

test('an empty schedule is still a valid calendar', function () {
    $ics = $this->get(route('club-nights.ics'))->assertOk()->getContent();

    expect($ics)->toContain('BEGIN:VCALENDAR')
        ->toContain('END:VCALENDAR')
        ->not->toContain('BEGIN:VEVENT');
});
