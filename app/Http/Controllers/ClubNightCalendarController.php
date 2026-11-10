<?php

namespace App\Http\Controllers;

use App\Enums\ClubNightKind;
use App\Models\ClubNight;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * iCalendar (RFC 5545) feed of club nights, for subscribing in Google Calendar, Outlook or Apple Calendar.
 *
 * GET /club-nights.ics             upcoming nights (plus the last four weeks, so subscribed calendars
 *                                  keep recent history); ?kind=training limits the feed to one kind.
 * GET /club-nights/{clubNight}.ics one night, for "Add to calendar" on its page.
 *
 * Times are written in UTC (DTSTART:20261110T183000Z), so no VTIMEZONE block is needed.
 */
final class ClubNightCalendarController extends Controller
{
    /**
     * How far back the feed reaches, so recent nights don't vanish from subscribed calendars.
     */
    private const HISTORY_WEEKS = 4;

    public function __invoke(Request $request, ?ClubNight $clubNight = null): Response
    {
        $kind = ClubNightKind::tryFrom((string) $request->query('kind', ''));

        $nights = $clubNight !== null
            ? collect([$clubNight])
            : ClubNight::query()
                ->where('ends_at', '>=', now()->subWeeks(self::HISTORY_WEEKS))
                ->when($kind, fn ($query, ClubNightKind $kind) => $query->where('kind', $kind->value))
                ->orderBy('starts_at')
                ->orderBy('id')
                ->get();

        $name = $clubNight !== null
            ? $clubNight->title.' · '.$this->clubName()
            : $this->clubName().' club nights'.($kind !== null ? ' · '.$kind->label() : '');

        $filename = $clubNight !== null
            ? 'smash-club-night-'.$clubNight->id.'.ics'
            : 'smash-club-nights'.($kind !== null ? '-'.$kind->value : '').'.ics';

        return response($this->calendar($nights, $name), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    /**
     * @param  Collection<int, ClubNight>  $nights
     */
    private function calendar(Collection $nights, string $name): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//SMASH BUas Badminton Club//Club nights//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->text($name),
            'X-WR-CALDESC:'.$this->text('Club nights of '.config('smash.club.full_name', 'SMASH').'. RSVP on the club night page.'),
            'X-WR-TIMEZONE:'.config('app.timezone'),
            'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
            'X-PUBLISHED-TTL:PT6H',
        ];

        foreach ($nights as $night) {
            array_push($lines, ...$this->event($night));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    /**
     * @return list<string>
     */
    private function event(ClubNight $night): array
    {
        $url = route('club-nights.show', $night);
        $stamp = $night->updated_at ?? $night->created_at ?? now();

        $details = array_filter([
            $night->kind->label().' · '.($night->level !== null ? $night->level->label().' level' : 'All levels welcome'),
            $night->courts.' '.Str::plural('court', $night->courts).' · '.$night->capacity.' places',
            $night->description,
            'RSVP: '.$url,
        ]);

        return [
            'BEGIN:VEVENT',
            'UID:club-night-'.$night->id.'@'.$this->host(),
            'DTSTAMP:'.$this->utc($stamp),
            'LAST-MODIFIED:'.$this->utc($stamp),
            'DTSTART:'.$this->utc($night->starts_at),
            'DTEND:'.$this->utc($night->ends_at),
            'SUMMARY:'.$this->text($night->title.' · '.$this->clubName()),
            'LOCATION:'.$this->text($night->venue),
            'DESCRIPTION:'.$this->text(implode("\n\n", $details)),
            'CATEGORIES:'.$this->text($night->kind->label()),
            'URL:'.$url,
            'STATUS:CONFIRMED',
            'TRANSP:OPAQUE',
            'END:VEVENT',
        ];
    }

    private function utc(CarbonInterface $moment): string
    {
        return $moment->copy()->utc()->format('Ymd\THis\Z');
    }

    /**
     * Escape a TEXT value (RFC 5545 §3.3.11).
     */
    private function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $value);
    }

    /**
     * Fold content lines longer than 75 octets (RFC 5545 §3.1), never splitting a UTF-8 character.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $parts = [];
        $limit = 75;

        while ($line !== '') {
            $chunk = mb_strcut($line, 0, $limit, 'UTF-8');
            $parts[] = $chunk;
            $line = substr($line, strlen($chunk));
            $limit = 74; // continuation lines start with a space
        }

        return implode("\r\n ", $parts);
    }

    private function clubName(): string
    {
        return (string) config('smash.club.name', 'SMASH');
    }

    private function host(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'smash.buas';
    }
}
