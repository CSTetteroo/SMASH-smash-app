@props([
    // horizontal (net runs top→bottom) or vertical (net runs left→right)
    'orientation' => 'horizontal',
    // Line weight in screen pixels (lines never scale).
    'stroke' => 1.5,
])

{{--
    Regulation badminton court (13.40 × 6.10 m) drawn as thin lines in currentColor.
    Purely decorative — colour it with text-* utilities (e.g. text-white/20, text-navy-800/10).
--}}
@php
    $vertical = $orientation === 'vertical';
    // Coordinates in decimetres along the length (x) and the width (y).
    $lines = [
        [0, 4.6, 134, 4.6],      // singles sideline
        [0, 56.4, 134, 56.4],    // singles sideline
        [7.6, 0, 7.6, 61],       // doubles long service line
        [126.4, 0, 126.4, 61],   // doubles long service line
        [47.2, 0, 47.2, 61],     // short service line
        [86.8, 0, 86.8, 61],     // short service line
        [0, 30.5, 47.2, 30.5],   // centre line
        [86.8, 30.5, 134, 30.5], // centre line
    ];
    $point = fn (float $x, float $y): array => $vertical ? [$y, $x] : [$x, $y];
@endphp

<svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="{{ $vertical ? '-1 -1 63 136' : '-1 -1 136 63' }}"
    fill="none"
    stroke="currentColor"
    stroke-width="{{ $stroke }}"
    aria-hidden="true"
    focusable="false"
    {{ $attributes->class('ui-court-lines pointer-events-none select-none') }}
>
    <rect x="0" y="0" width="{{ $vertical ? 61 : 134 }}" height="{{ $vertical ? 134 : 61 }}" />
    @foreach ($lines as [$x1, $y1, $x2, $y2])
        @php [$ax, $ay] = $point($x1, $y1); [$bx, $by] = $point($x2, $y2); @endphp
        <line x1="{{ $ax }}" y1="{{ $ay }}" x2="{{ $bx }}" y2="{{ $by }}" />
    @endforeach
    {{-- Net --}}
    @php [$nx1, $ny1] = $point(67, -1); [$nx2, $ny2] = $point(67, 62); @endphp
    <line x1="{{ $nx1 }}" y1="{{ $ny1 }}" x2="{{ $nx2 }}" y2="{{ $ny2 }}" stroke-width="{{ $stroke * 2 }}" />
</svg>
