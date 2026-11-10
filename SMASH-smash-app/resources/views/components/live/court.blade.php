@props([
    /*
     | x-live.court — the umpire console's playing surface: a top-down badminton court seen from
     | the umpire's chair (net in the middle, one end per side of the screen), drawn in thin lines
     | and stretched to fill its box. Decorative; the console says the same in text.
     |
     | doubles   Doubles service courts (short and wide) instead of singles ones (long and narrow).
     | server    'left' · 'right' · null — the end serving the next rally.
     | court     'right' · 'left' · null — the server's service court.
     | choice    Alpine expression for the start screen: the server's end follows the umpire's
     |           pick before anything is saved, e.g. "$wire.firstServer === 'a' ? 'left' : …".
     */
    'doubles' => false,
    'server' => null,
    'court' => null,
    'choice' => null,
])

@php
    // Court in decimetres: 134 long (x), 61 wide (y). Singles sidelines 4.6 in, doubles long service line 7.6 in.
    $top = $doubles ? 0 : 4.6;
    $bottom = $doubles ? 61 : 56.4;

    /**
     * The service court [x, y, width, height] of a player standing at $end, for their $court
     * (right / left as they face the net). The left end faces east (their right = bottom of the
     * screen), the right end faces west (their right = top).
     */
    $box = function (string $end, string $court) use ($doubles, $top, $bottom): array {
        $x1 = $end === 'left' ? ($doubles ? 7.6 : 0) : 86.8;
        $x2 = $end === 'left' ? 47.2 : ($doubles ? 126.4 : 134);
        $lower = ($end === 'left') === ($court === 'right');
        [$y1, $y2] = $lower ? [30.5, $bottom] : [$top, 30.5];

        return [$x1, $y1, $x2 - $x1, $y2 - $y1];
    };

    $lines = [
        [0, 4.6, 134, 4.6], [0, 56.4, 134, 56.4],          // singles sidelines
        [7.6, 0, 7.6, 61], [126.4, 0, 126.4, 61],          // doubles long service lines
        [47.2, 0, 47.2, 61], [86.8, 0, 86.8, 61],          // short service lines
        [0, 30.5, 47.2, 30.5], [86.8, 30.5, 134, 30.5],    // centre lines
    ];

    $boxes = [];

    if ($server !== null && $court !== null) {
        $receiver = $server === 'left' ? 'right' : 'left';
        $boxes[] = ['serve', $box($server, $court), null];
        $boxes[] = ['receive', $box($receiver, $court), null];
    } elseif ($choice !== null) {
        // Start screen: the first serve is always from the right service court (0 is even).
        foreach (['left', 'right'] as $end) {
            $boxes[] = ['serve', $box($end, 'right'), "({$choice}) === '{$end}'"];
            $boxes[] = ['receive', $box($end === 'left' ? 'right' : 'left', 'right'), "({$choice}) === '{$end}'"];
        }
    }
@endphp

<svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 134 61"
    preserveAspectRatio="none"
    fill="none"
    aria-hidden="true"
    focusable="false"
    {{ $attributes->class('pointer-events-none select-none') }}
>
    @foreach ($boxes as [$kind, [$x, $y, $w, $h], $show])
        <rect
            x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="{{ $h }}"
            @if ($show) x-show="{{ $show }}" x-cloak @endif
            @if ($kind === 'serve')
                class="fill-white/[0.06] stroke-orange-400"
                stroke-width="2"
            @else
                class="stroke-white/40"
                stroke-width="1.5"
                stroke-dasharray="6 5"
            @endif
            vector-effect="non-scaling-stroke"
        />
    @endforeach

    <rect x="0.5" y="0.5" width="133" height="60" class="stroke-white/22" stroke-width="1.5" vector-effect="non-scaling-stroke" />
    @foreach ($lines as [$x1, $y1, $x2, $y2])
        <line x1="{{ $x1 }}" y1="{{ $y1 }}" x2="{{ $x2 }}" y2="{{ $y2 }}" class="stroke-white/14" stroke-width="1.5" vector-effect="non-scaling-stroke" />
    @endforeach

    {{-- The net. --}}
    <line x1="67" y1="0" x2="67" y2="61" class="stroke-white/70" stroke-width="3" vector-effect="non-scaling-stroke" />
</svg>
