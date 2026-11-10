@props([
    /*
     | x-smash.court-pattern — decorative badminton court line drawing for hero backgrounds,
     | after the club's "Smash it forward" poster: a court in perspective and a shuttle smashed
     | over the net into the far court. Everything is drawn in currentColor at set opacities, so
     | colour it with one text class (text-white on navy, text-navy-800 on grey or white).
     |
     | variant      perspective (default; view from behind the baseline) · plan (top-down)
     | shuttle      Draw the shuttle.
     | trail        Draw its flight path and the landing mark.
     | net          Draw the net (perspective variant).
     | intensity    subtle · default · bold (opacity of lines, trail and shuttle)
     | stroke       Line weight in screen pixels (lines never scale).
     | fit          contain (default) · cover (fills the box, cropping the edges)
     | Decorative only: aria-hidden. Position it with classes (absolute, h-full, w-auto …).
     */
    'variant' => 'perspective',
    'shuttle' => true,
    'trail' => true,
    'net' => true,
    'intensity' => 'default',
    'stroke' => 1.5,
    'fit' => 'contain',
])

@php
    [$lineOpacity, $trailOpacity, $shuttleOpacity] = match ($intensity) {
        'subtle' => [0.1, 0.22, 0.45],
        'bold' => [0.4, 0.65, 1.0],
        default => [0.2, 0.42, 0.85],
    };
    $stroke = (float) $stroke;
    $aspect = $fit === 'cover' ? 'xMidYMid slice' : 'xMidYMid meet';

    $segments = [];   // [x1, y1, x2, y2, weight, opacity]
    $mesh = [];
    $flight = null;   // [[x0, y0], [control x, y], [x1, y1]] — a quadratic curve
    $landing = null;  // [cx, cy, rx, ry]
    $shuttleSize = 0;
    $shuttleT = 0.2;  // how far along the flight the shuttle is drawn

    if ($variant === 'plan') {
        // Top-down court, 13.40 × 6.10 m in decimetres, net across the middle.
        $viewBox = '-4 -4 142 69';
        $half = 30.5;
        foreach ([
            [0, 0, 134, 0], [0, 61, 134, 61], [0, 0, 0, 61], [134, 0, 134, 61],   // doubles boundary
            [0, 4.6, 134, 4.6], [0, 56.4, 134, 56.4],                           // singles sidelines
            [7.6, 0, 7.6, 61], [126.4, 0, 126.4, 61],                           // doubles long service
            [47.2, 0, 47.2, 61], [86.8, 0, 86.8, 61],                           // short service lines
            [0, $half, 47.2, $half], [86.8, $half, 134, $half],                 // centre lines
        ] as [$x1, $y1, $x2, $y2]) {
            $segments[] = [$x1, $y1, $x2, $y2, 1, 1];
        }
        if ($net) {
            $segments[] = [67, -2.5, 67, 63.5, 2.2, 1.4];
        }
        $flight = [[22, 50], [60, 12], [113, 17]];
        $landing = [113, 17, 3.2, 3.2];
        $shuttleSize = 13;
        $shuttleT = 0.3;
    } else {
        // Perspective: camera behind the near baseline. World X across (±3.05 m), Z into the
        // court (0 → 13.4 m), E up. A pinhole projection keeps every court line straight.
        $viewBox = '0 0 800 520';
        $d = 11.83;
        $project = fn (float $x, float $z, float $e = 0.0): array => [
            round(400 + 1241 * $x / ($z + $d), 2),
            round(-121.4 + 7351 * (1 - $e / 15) / ($z + $d), 2),
        ];
        $ground = function (float $x1, float $z1, float $x2, float $z2, float $weight = 1) use ($project, &$segments): void {
            [$ax, $ay] = $project($x1, $z1);
            [$bx, $by] = $project($x2, $z2);
            $segments[] = [$ax, $ay, $bx, $by, $weight, 1];
        };

        foreach ([-3.05, 3.05] as $x) { $ground($x, 0, $x, 13.4); }        // doubles sidelines
        foreach ([-2.59, 2.59] as $x) { $ground($x, 0, $x, 13.4); }        // singles sidelines
        foreach ([0, 13.4] as $z) { $ground(-3.05, $z, 3.05, $z); }        // baselines
        foreach ([0.76, 12.64] as $z) { $ground(-3.05, $z, 3.05, $z); }    // doubles long service
        foreach ([4.72, 8.68] as $z) { $ground(-3.05, $z, 3.05, $z); }     // short service lines
        $ground(0, 0, 0, 4.72);                                            // centre lines
        $ground(0, 8.68, 0, 13.4);

        if ($net) {
            $netZ = 6.7;
            foreach ([-3.2, 3.2] as $x) {                                   // posts
                [$px, $py] = $project($x, $netZ);
                [$tx, $ty] = $project($x, $netZ, 1.55);
                $segments[] = [$px, $py, $tx, $ty, 1.6, 1.3];
            }
            [$lx, $ly] = $project(-3.2, $netZ, 1.55);                       // top tape
            [$rx, $ry] = $project(3.2, $netZ, 1.55);
            $segments[] = [$lx, $ly, $rx, $ry, 2.4, 1.5];
            [$lx, $ly] = $project(-3.05, $netZ, 0.79);                      // bottom edge
            [$rx, $ry] = $project(3.05, $netZ, 0.79);
            $segments[] = [$lx, $ly, $rx, $ry, 1, 0.8];
            for ($x = -2.75; $x <= 2.76; $x += 0.5) {                        // mesh
                [$bx, $by] = $project($x, $netZ, 0.79);
                [$tx, $ty] = $project($x, $netZ, 1.55);
                $mesh[] = [$bx, $by, $tx, $ty];
            }
        }

        // The smash: hit high in the near court, over the net, down into the far court.
        $hit = $project(1.7, 1.0, 3.3);
        $overNet = $project(-0.1, 6.7, 1.95);
        $land = $project(-1.9, 11.2);
        // Control point so the curve passes over the net at t = 0.5.
        $control = [
            round(2 * $overNet[0] - ($hit[0] + $land[0]) / 2, 2),
            round(2 * $overNet[1] - ($hit[1] + $land[1]) / 2, 2),
        ];
        $flight = [$hit, $control, $land];
        $landing = [$land[0], $land[1], 19, 4.9];
        $shuttleSize = 64;
    }

    $trailPath = null;
    $shuttleAt = null; // [x, y, size, angle]
    if ($flight !== null) {
        [[$x0, $y0], [$qx, $qy], [$x1, $y1]] = $flight;
        $trailPath = "M{$x0} {$y0} Q{$qx} {$qy} {$x1} {$y1}";

        // Point and tangent on the curve at t; the drawing's cork points down (+y), so rotate
        // that onto the direction of travel: the cork leads.
        $t = $shuttleT;
        $sx = (1 - $t) ** 2 * $x0 + 2 * (1 - $t) * $t * $qx + $t ** 2 * $x1;
        $sy = (1 - $t) ** 2 * $y0 + 2 * (1 - $t) * $t * $qy + $t ** 2 * $y1;
        $dx = 2 * (1 - $t) * ($qx - $x0) + 2 * $t * ($x1 - $qx);
        $dy = 2 * (1 - $t) * ($qy - $y0) + 2 * $t * ($y1 - $qy);
        $shuttleAt = [round($sx, 2), round($sy, 2), $shuttleSize, round(rad2deg(atan2($dy, $dx)) - 90, 1)];
    }
@endphp

<svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="{{ $viewBox }}"
    preserveAspectRatio="{{ $aspect }}"
    fill="none"
    aria-hidden="true"
    focusable="false"
    {{ $attributes->class('pointer-events-none select-none overflow-visible') }}
    data-smash-court-pattern="{{ $variant }}"
>
    <g stroke="currentColor" stroke-linecap="round">
        @foreach ($segments as [$x1, $y1, $x2, $y2, $weight, $opacity])
            <line x1="{{ $x1 }}" y1="{{ $y1 }}" x2="{{ $x2 }}" y2="{{ $y2 }}" stroke-width="{{ $stroke * $weight }}" stroke-opacity="{{ min(1, $lineOpacity * $opacity) }}" vector-effect="non-scaling-stroke" />
        @endforeach
        @foreach ($mesh as [$x1, $y1, $x2, $y2])
            <line x1="{{ $x1 }}" y1="{{ $y1 }}" x2="{{ $x2 }}" y2="{{ $y2 }}" stroke-width="{{ $stroke * 0.6 }}" stroke-opacity="{{ $lineOpacity * 0.55 }}" vector-effect="non-scaling-stroke" />
        @endforeach
    </g>

    @if ($trail && $trailPath)
        <path d="{{ $trailPath }}" stroke="currentColor" stroke-width="{{ $stroke * 1.4 }}" stroke-opacity="{{ $trailOpacity }}" stroke-linecap="round" stroke-dasharray="0.5 7" vector-effect="non-scaling-stroke" />
        @if ($landing)
            @php [$lx, $ly, $lrx, $lry] = $landing; @endphp
            <ellipse cx="{{ $lx }}" cy="{{ $ly }}" rx="{{ $lrx }}" ry="{{ $lry }}" stroke="currentColor" stroke-width="{{ $stroke }}" stroke-opacity="{{ $trailOpacity }}" vector-effect="non-scaling-stroke" />
            <ellipse cx="{{ $lx }}" cy="{{ $ly }}" rx="{{ $lrx * 1.9 }}" ry="{{ $lry * 1.9 }}" stroke="currentColor" stroke-width="{{ $stroke * 0.8 }}" stroke-opacity="{{ $trailOpacity * 0.5 }}" vector-effect="non-scaling-stroke" />
            <ellipse cx="{{ $lx }}" cy="{{ $ly }}" rx="{{ max(1.2, $lrx * 0.18) }}" ry="{{ max(1.2, $lry * 0.3) }}" fill="var(--color-smash-500, #F03F2A)" fill-opacity="{{ min(1, $shuttleOpacity) }}" />
        @endif
    @endif

    @if ($shuttle && $shuttleAt)
        @php [$sx, $sy, $size, $sAngle] = $shuttleAt; @endphp
        <g opacity="{{ $shuttleOpacity }}">
            <x-smash.shuttle variant="line" :angle="$sAngle" :x="$sx - $size / 2" :y="$sy - $size / 2" :width="$size" :height="$size" />
        </g>
    @endif
</svg>
