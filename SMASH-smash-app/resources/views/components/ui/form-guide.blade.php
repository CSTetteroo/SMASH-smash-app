@props([
    // Most recent first: ['W','L',…] or [['result' => 'W', 'match_id' => 12], …] (PlayerStats::form format).
    'results' => [],
    // Link squares to matches.show when match ids are present.
    'links' => true,
    // sm · md
    'size' => 'md',
    // Pad to this many squares with empty slots.
    'length' => 5,
])

@php
    $items = collect($results)
        ->map(fn ($r) => is_array($r)
            ? ['result' => strtoupper((string) ($r['result'] ?? '')), 'match_id' => $r['match_id'] ?? null]
            : ['result' => strtoupper((string) $r), 'match_id' => null])
        ->take($length)
        ->values();

    $spoken = $items->isEmpty()
        ? __('No recent matches')
        : __('Recent form, newest first: :form', ['form' => $items->pluck('result')->map(fn ($r) => $r === 'W' ? __('win') : __('loss'))->implode(', ')]);

    $box = $size === 'sm' ? 'size-[18px] text-[11px]' : 'size-[22px] text-[13px]';
@endphp

<span {{ $attributes->class('inline-flex items-center gap-1') }}>
    <span class="sr-only">{{ $spoken }}</span>
    @foreach ($items as $item)
        @php
            $classes = ['num inline-flex items-center justify-center font-bold leading-none', $box, $item['result'] === 'W' ? 'bg-win text-white dark:text-navy-950' : 'bg-loss text-white dark:text-navy-950'];
        @endphp
        @if ($links && $item['match_id'] && Route::has('matches.show'))
            <a href="{{ route('matches.show', $item['match_id']) }}" wire:navigate @class(array_merge($classes, ['hover:opacity-85'])) aria-hidden="true" tabindex="-1">{{ $item['result'] }}</a>
        @else
            <span @class($classes) aria-hidden="true">{{ $item['result'] }}</span>
        @endif
    @endforeach
    @for ($i = $items->count(); $i < $length; $i++)
        <span class="{{ $box }} inline-block border border-dashed border-line-strong" aria-hidden="true"></span>
    @endfor
</span>
