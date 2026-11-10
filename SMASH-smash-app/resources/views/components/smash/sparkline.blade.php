@props([
    /*
     | x-smash.sparkline — a tiny inline SVG trend line (rating over the last matches).
     |
     | values       List of numbers, oldest first. Or:
     | points       Rating history ([['rating' => …], …], PlayerStats::ratingHistory format).
     | width/height Size in px (default 88 × 24). Add class="w-full" to stretch; strokes never scale.
     | tone         trend (default: green when up, red when down, grey when flat) · navy · ink
     | label        Accessible name; null = decorative (aria-hidden).
     | dot          Mark the latest value.
     | area         Faint fill under the line.
     */
    'values' => null,
    'points' => null,
    'width' => 88,
    'height' => 24,
    'tone' => 'trend',
    'label' => null,
    'dot' => true,
    'area' => false,
])

@php
    $series = collect($values ?? collect($points ?? [])->map(fn ($p) => is_array($p) ? ($p['rating'] ?? $p['value'] ?? 0) : ($p->rating ?? 0)))
        ->map(fn ($v) => (float) $v)
        ->values();
    $count = $series->count();
    $w = max(16, (int) $width);
    $h = max(8, (int) $height);
    $inset = 3; // room for the dot and the stroke

    $min = $count ? $series->min() : 0;
    $max = $count ? $series->max() : 1;
    $span = max(1e-6, $max - $min);

    $x = fn (int $i): float => round($count > 1 ? $inset + $i / ($count - 1) * ($w - 2 * $inset) : $w / 2, 2);
    $y = fn (float $v): float => round($max - $min < 1e-6 ? $h / 2 : $inset + (1 - ($v - $min) / $span) * ($h - 2 * $inset), 2);

    $path = $series->map(fn ($v, $i) => ($i === 0 ? 'M' : 'L').$x($i).' '.$y($v))->implode(' ');

    $change = $count > 1 ? $series->last() - $series->first() : 0.0;
    $colour = match ($tone) {
        'navy' => 'text-navy-800 dark:text-navy-400 in-[.theme-navy]:text-white',
        'ink' => 'text-ink',
        default => match (true) {
            $change > 0.5 => 'text-win',
            $change < -0.5 => 'text-loss',
            default => 'text-ink-muted',
        },
    };
@endphp

@if ($count > 0)
    <svg
        xmlns="http://www.w3.org/2000/svg"
        width="{{ $w }}"
        height="{{ $h }}"
        viewBox="0 0 {{ $w }} {{ $h }}"
        preserveAspectRatio="none"
        fill="none"
        @if ($label)
            role="img" aria-label="{{ $label }}"
        @else
            aria-hidden="true"
        @endif
        focusable="false"
        {{ $attributes->class(['inline-block shrink-0 overflow-visible align-middle', $colour]) }}
        data-smash-sparkline
    >
        @if ($area && $count > 1)
            <path d="{{ $path }} L{{ $x($count - 1) }} {{ $h }} L{{ $x(0) }} {{ $h }} Z" fill="currentColor" fill-opacity="0.12" />
        @endif
        @if ($count > 1)
            <path d="{{ $path }}" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
        @endif
        @if ($dot)
            {{-- A zero-length round-capped stroke stays a circle even when the SVG is stretched. --}}
            <path d="M{{ $x($count - 1) }} {{ $y($series->last()) }}h0" stroke="currentColor" stroke-width="5" stroke-linecap="round" vector-effect="non-scaling-stroke" />
        @endif
    </svg>
@endif
