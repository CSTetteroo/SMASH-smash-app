@props([
    /*
     | x-smash.form — recent form as W/L squares, newest first (built on x-ui.form-guide).
     |
     | results      ['W', 'L', …] (LeaderboardRow::form) or [['result' => 'W', 'match_id' => 12], …]
     |              (PlayerStats::form). Squares link to the match when an id is known.
     | matches      Alternatively: BadmintonMatch models (eager-load participants) …
     | for          … and the User whose results to show. Only completed matches count.
     | length       Number of squares (empty slots pad the rest). Default 5.
     | size         sm · md
     | links        Link squares to matches.show.
     | label        Optional caption before the squares ("Form").
     */
    'results' => null,
    'matches' => null,
    'for' => null,
    'length' => 5,
    'size' => 'md',
    'links' => true,
    'label' => null,
])

@php
    if ($results === null && $matches !== null && $for instanceof \App\Models\User) {
        $results = collect($matches)
            ->filter(fn ($match) => $match instanceof \App\Models\BadmintonMatch
                && $match->status === \App\Enums\MatchStatus::Completed
                && $match->winning_side !== null
                && $match->sideOf($for) !== null)
            ->sortByDesc(fn ($match) => sprintf('%012d-%012d', $match->played_at?->getTimestamp() ?? 0, (int) $match->getKey()))
            ->take((int) $length)
            ->map(fn ($match) => [
                'result' => $match->isWonBy($for) ? 'W' : 'L',
                'match_id' => $match->getKey(),
            ])
            ->values()
            ->all();
    }
@endphp

<span {{ $attributes->class('inline-flex items-center gap-2.5') }} data-smash-form>
    @if ($label)
        <span class="label-caps text-ink-muted">{{ $label }}</span>
    @endif
    <x-ui.form-guide :results="$results ?? []" :length="(int) $length" :size="$size" :links="$links" />
</span>
