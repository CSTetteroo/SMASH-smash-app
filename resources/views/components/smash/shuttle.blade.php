@props([
    /*
     | x-smash.shuttle — shuttlecock illustration (feather skirt, threads, band, red cork).
     | Outlines use currentColor, so colour it with a text class (text-heading, text-white …).
     |
     | variant      colour (white skirt, red cork; default) · line (outline only, for decoration)
     | angle        Rotation in degrees around the centre. 0 = cork down; e.g. -135 flies up-left.
     | cork         Cork colour (default SMASH red).
     | title        Accessible name; null = decorative (aria-hidden).
     */
    'variant' => 'colour',
    'angle' => 0,
    'cork' => 'var(--color-smash-500, #F03F2A)',
    'title' => null,
])

@php
    $line = $variant === 'line';

    // Feather shafts: from the band up to points on the front of the rim (an ellipse at y = 11).
    $shafts = [];
    foreach ([1, 2, 3, 4, 5, 6] as $k) {
        $theta = M_PI * $k / 7;
        $shafts[] = [
            'x1' => round(37.3 - ($k - 0.5) * 1.77, 2),
            'y1' => 43,
            'x2' => round(32 + 19 * cos($theta), 2),
            'y2' => round(11 + 4.2 * sin($theta), 2),
        ];
    }
@endphp

<svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 64 64"
    fill="none"
    @if ($title) role="img" aria-label="{{ $title }}" @else aria-hidden="true" @endif
    focusable="false"
    {{ $attributes->class('shrink-0 overflow-visible') }}
    data-smash-shuttle
>
    <g transform="rotate({{ (float) $angle }} 32 31)" stroke="currentColor" stroke-linejoin="round" stroke-linecap="round">
        {{-- Inside of the skirt, seen through the opening --}}
        <ellipse cx="32" cy="11" rx="19" ry="4.2" @if ($line) fill="none" @else fill="currentColor" fill-opacity="0.14" @endif stroke-width="1.6" />

        {{-- Skirt: sides down to the band, top edge follows the front of the rim --}}
        <path d="M13 11 L26.5 43 L37.5 43 L51 11 A19 4.2 0 0 1 13 11 Z" @if ($line) fill="none" @else fill="#fff" @endif stroke-width="1.8" />

        {{-- Feather shafts --}}
        @foreach ($shafts as $s)
            <line x1="{{ $s['x1'] }}" y1="{{ $s['y1'] }}" x2="{{ $s['x2'] }}" y2="{{ $s['y2'] }}" stroke-width="0.9" stroke-opacity="0.45" />
        @endforeach

        {{-- Two binding threads around the skirt --}}
        <path d="M17.64 22 A14.36 3.17 0 0 0 46.36 22" stroke-width="1.3" />
        <path d="M21.44 31 A10.56 2.33 0 0 0 42.56 31" stroke-width="1.3" />

        {{-- Band where the feathers meet the cork --}}
        <path d="M26.2 42.6 H37.8 V46 A5.8 1.3 0 0 1 26.2 46 Z" @if ($line) fill="none" @else fill="currentColor" @endif stroke-width="1.4" />

        {{-- Cork --}}
        <path d="M26.2 46.4 H37.8 V47.6 A5.8 6.6 0 0 1 26.2 47.6 Z" fill="{{ $cork }}" stroke="{{ $cork }}" stroke-width="1" />
        @unless ($line)
            <ellipse cx="29.4" cy="49.6" rx="1.3" ry="2.1" fill="#fff" fill-opacity="0.35" stroke="none" />
        @endunless
    </g>
</svg>
