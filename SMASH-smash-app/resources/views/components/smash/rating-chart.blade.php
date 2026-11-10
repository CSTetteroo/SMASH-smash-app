@props([
    /*
     | x-smash.rating-chart — rating over time as a pure inline SVG line chart. The canonical
     | rating chart (x-ui.line-chart renders this one with scale="index").
     |
     | points       Chronological [['date' => Carbon|string, 'rating' => float, 'change' => ?float,
     |              'match_id' => ?int], …] (PlayerStats::ratingHistory format).
     | label        What is plotted ("Singles rating"): accessible name and summary.
     | height       Chart height in px (default 240). Width is always 100 % of the container.
     | scale        time (x = date, default) · index (x = match order, evenly spaced)
     | baseline     Optional reference line, e.g. config('smash.rating.initial').
     | baselineLabel Legend text for the baseline (default "Starting rating 1500").
     | interactive  Alpine crosshair + tooltip on hover, ← / → keys when focused.
     | markers      Start, peak and current markers with labels.
     | table        "Show the numbers" toggle with every value (newest first, linked to matches).
     | empty        Text for the empty state.
     */
    'points' => [],
    'label' => 'Rating',
    'height' => 240,
    'scale' => 'time',
    'baseline' => null,
    'baselineLabel' => null,
    'interactive' => true,
    'markers' => true,
    'table' => false,
    'empty' => null,
])

@php
    $rows = collect($points)->values()->map(function ($point): array {
        $point = is_array($point) ? $point : (array) $point;
        $date = $point['date'] ?? $point['x'] ?? null;

        return [
            'date' => $date instanceof \DateTimeInterface
                ? \Carbon\CarbonImmutable::instance($date)
                : (filled($date) ? \Carbon\CarbonImmutable::parse((string) $date) : null),
            'rating' => (float) ($point['rating'] ?? $point['value'] ?? $point['y'] ?? 0),
            'change' => isset($point['change']) ? (float) $point['change'] : null,
            'match_id' => isset($point['match_id']) && is_numeric($point['match_id']) ? (int) $point['match_id'] : null,
        ];
    });
    $count = $rows->count();
    $height = max(140, (int) $height);

    // ---- Horizontal scale: by date when every point has one, otherwise by match order -------
    $first = $rows->first();
    $last = $rows->last();
    $byTime = $scale === 'time' && $count > 1
        && $rows->every(fn (array $row) => $row['date'] !== null)
        && $last['date']->getTimestamp() > $first['date']->getTimestamp();
    $stamps = $rows->map(fn (array $row): int => $byTime ? $row['date']->getTimestamp() : 0)->all();
    if ($byTime && $stamps[0] >= $stamps[1]) {
        // PlayerStats::ratingHistory starts with the rating before the first match, dated at that
        // match: draw it a little earlier so the first result is a visible step, not a vertical jump.
        $stamps[0] = $stamps[1] - max(43200, (int) round(($stamps[$count - 1] - $stamps[1]) * 0.03));
    }
    $t0 = $byTime ? $stamps[0] : 0;
    $tSpan = $byTime ? max(1, $stamps[$count - 1] - $t0) : 1;

    $pad = 0.012; // keeps end markers inside the plot
    $fraction = fn (array $row, int $i): float => $byTime
        ? ($stamps[$i] - $t0) / $tSpan
        : ($count > 1 ? $i / ($count - 1) : 0.5);
    $xPercent = fn (float $f): float => round(($pad + $f * (1 - 2 * $pad)) * 100, 3);

    // ---- Vertical scale with "nice" gridlines ------------------------------------------------
    $values = $rows->pluck('rating');
    if ($baseline !== null) {
        $values = $values->push((float) $baseline);
    }
    $min = $count ? (float) $values->min() : 1450.0;
    $max = $count ? (float) $values->max() : 1550.0;
    $spread = max(24.0, $max - $min);
    $step = collect([5, 10, 20, 25, 50, 100, 200, 250, 500])->first(fn ($s) => $spread / $s <= 5) ?? 500;
    $lo = floor(($min - $spread * 0.1) / $step) * $step;
    $hi = ceil(($max + $spread * 0.14) / $step) * $step;
    $range = max(1, $hi - $lo);

    $top = 30;
    $bottom = 26;
    $plotBottom = $height - $bottom;
    $y = fn (float $value): float => round($top + (1 - ($value - $lo) / $range) * ($plotBottom - $top), 2);

    $ticks = [];
    for ($tick = $lo; $tick <= $hi + 0.001; $tick += $step) {
        $ticks[] = (int) $tick;
    }

    // ---- Series ------------------------------------------------------------------------------
    $series = $rows->map(fn (array $row, int $i) => [
        'x' => $xPercent($fraction($row, $i)),
        'y' => $y($row['rating']),
        'rating' => $row['rating'],
        // The opening point of a history (change 0) is the rating before the first match.
        'change' => $i === 0 && $count > 1 && ! $row['change'] ? null : $row['change'],
        'date' => $row['date'],
        'match_id' => $row['match_id'],
    ])->values();

    $line = $series->map(fn ($p, $i) => ($i === 0 ? 'M' : 'L').round($p['x'] * 10, 2).' '.$p['y'])->implode(' ');
    $area = $count > 1
        ? $line.' L'.round($series->last()['x'] * 10, 2).' '.$plotBottom.' L'.round($series->first()['x'] * 10, 2).' '.$plotBottom.' Z'
        : '';

    $peakIndex = $count ? (int) $series->keys()->reduce(fn ($carry, $i) => $series[$i]['rating'] > $series[$carry]['rating'] ? $i : $carry, 0) : null;
    $currentIndex = $count - 1;

    // Label anchors that stay inside the chart near its edges.
    $anchor = fn (float $x): string => $x > 88 ? 'end' : ($x < 12 ? 'start' : 'middle');

    // Start label goes on the side of the point away from the line's first move.
    $startBelow = $count > 1 && $series[1]['y'] < $series[0]['y'];

    // ---- X axis ticks: month starts for long spans, Mondays for short ones ---------------
    $xTicks = [];
    if ($byTime) {
        $start = $first['date'];
        $end = $last['date'];
        $days = $start->diffInDays($end);
        $cursor = $days > 45 ? $start->startOfMonth()->addMonth() : $start->startOfWeek()->addWeek();
        while ($cursor->lessThanOrEqualTo($end) && count($xTicks) < 14) {
            $f = ($cursor->getTimestamp() - $t0) / $tSpan;
            // Leave room for the start and end dates at the edges.
            if ($f > 0.1 && $f < 0.9) {
                $xTicks[] = ['x' => $xPercent($f), 'label' => $days > 45 ? $cursor->format('M') : $cursor->format('j M')];
            }
            $cursor = $days > 45 ? $cursor->addMonth() : $cursor->addWeek(($days > 21 ? 2 : 1));
        }
    }

    // ---- Text alternatives ----------------------------------------------------------------
    $peak = $peakIndex !== null ? $series[$peakIndex] : null;
    $summary = $count
        ? __(':label: started at :start:from, peaked at :peak:on, now :now.', [
            'label' => $label,
            'start' => (int) round($first['rating']),
            'from' => $first['date'] ? ' ('.$first['date']->format('j M').')' : '',
            'peak' => (int) round($peak['rating']),
            'on' => $peak['date'] ? ' ('.$peak['date']->format('j M').')' : '',
            'now' => (int) round($last['rating']),
        ])
        : __(':label: no rated matches yet.', ['label' => $label]);

    $js = $series->map(fn ($p) => [
        'x' => $p['x'],
        'y' => $p['y'],
        'v' => (int) round($p['rating']),
        'c' => $p['change'] === null ? null : (int) round($p['change']),
        'd' => $p['date']?->format('D j M Y'),
    ])->all();
@endphp

<figure {{ $attributes->class('min-w-0') }} data-smash-rating-chart>
    @if ($count === 0)
        <x-ui.empty :title="__('No rated matches yet')" variant="bordered" class="py-10">
            {{ $empty ?? __('The chart appears after the first rated match.') }}
        </x-ui.empty>
    @else
        <div
            class="flex text-navy-800 dark:text-navy-400 in-[.theme-navy]:text-white"
            @if ($interactive)
                x-data="{
                    i: null,
                    pts: @js($js),
                    move(e) {
                        const r = this.$refs.plot.getBoundingClientRect();
                        const f = Math.min(100, Math.max(0, (e.clientX - r.left) / r.width * 100));
                        let best = 0;
                        this.pts.forEach((p, k) => { if (Math.abs(p.x - f) < Math.abs(this.pts[best].x - f)) best = k; });
                        this.i = best;
                    },
                    step(d) {
                        const n = this.pts.length - 1;
                        this.i = Math.min(n, Math.max(0, (this.i ?? n) + d));
                    },
                }"
            @endif
        >
            {{-- Y axis labels --}}
            <svg class="w-11 shrink-0 overflow-visible" height="{{ $height }}" aria-hidden="true" focusable="false">
                @foreach ($ticks as $tick)
                    <text x="36" y="{{ $y($tick) + 4 }}" text-anchor="end" class="num fill-ink-muted text-[11px] font-semibold">{{ $tick }}</text>
                @endforeach
            </svg>

            {{-- Plot --}}
            <div
                class="relative min-w-0 flex-1 outline-offset-4 {{ $interactive ? 'cursor-crosshair touch-pan-y' : '' }}"
                role="img"
                aria-label="{{ $summary }}"
                @if ($interactive)
                    x-ref="plot"
                    tabindex="0"
                    x-on:pointermove="move($event)"
                    x-on:pointerdown="move($event)"
                    x-on:pointerleave="i = null"
                    x-on:keydown.arrow-right.prevent="step(1)"
                    x-on:keydown.arrow-left.prevent="step(-1)"
                    x-on:keydown.escape="i = null"
                    x-on:blur="i = null"
                @endif
            >
                <svg width="100%" height="{{ $height }}" class="block overflow-visible" aria-hidden="true" focusable="false">
                    {{-- Gridlines --}}
                    @foreach ($ticks as $tick)
                        <line x1="0" x2="100%" y1="{{ $y($tick) }}" y2="{{ $y($tick) }}" stroke="var(--color-line)" stroke-width="1" shape-rendering="crispEdges" />
                    @endforeach

                    @if ($baseline !== null)
                        <line x1="0" x2="100%" y1="{{ $y((float) $baseline) }}" y2="{{ $y((float) $baseline) }}" stroke="var(--color-line-strong)" stroke-width="1.5" stroke-dasharray="5 4" />
                    @endif

                    {{-- X axis --}}
                    <line x1="0" x2="100%" y1="{{ $plotBottom }}" y2="{{ $plotBottom }}" stroke="var(--color-line-strong)" stroke-width="1" shape-rendering="crispEdges" />
                    @foreach ($xTicks as $tick)
                        <line x1="{{ $tick['x'] }}%" x2="{{ $tick['x'] }}%" y1="{{ $plotBottom }}" y2="{{ $plotBottom + 5 }}" stroke="var(--color-line-strong)" stroke-width="1" />
                        <text x="{{ $tick['x'] }}%" y="{{ $height - 5 }}" text-anchor="middle" class="fill-ink-muted text-[11px]">{{ $tick['label'] }}</text>
                    @endforeach
                    @if ($count > 1)
                        <text x="0" y="{{ $height - 5 }}" text-anchor="start" class="fill-ink-muted text-[11px]">{{ $first['date']?->format('j M') ?? __('Match 1') }}</text>
                        <text x="100%" y="{{ $height - 5 }}" text-anchor="end" class="fill-ink-muted text-[11px]">{{ $last['date']?->format('j M') ?? __('Match :n', ['n' => $count]) }}</text>
                    @endif

                    {{-- Series: stretched horizontally, 1:1 vertically; strokes never scale --}}
                    <svg x="0" y="0" width="100%" height="{{ $height }}" viewBox="0 0 1000 {{ $height }}" preserveAspectRatio="none" overflow="visible">
                        @if ($area)
                            <path d="{{ $area }}" fill="currentColor" fill-opacity="0.08" />
                        @endif
                        @if ($count > 1)
                            <path d="{{ $line }}" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                        @endif
                    </svg>

                    {{-- Markers --}}
                    @if ($markers && $count > 1)
                        @php $s = $series[0]; @endphp
                        <circle cx="{{ $s['x'] }}%" cy="{{ $s['y'] }}" r="4" fill="var(--color-surface)" stroke="currentColor" stroke-width="2" />
                        <text x="{{ $s['x'] }}%" y="{{ $startBelow ? $s['y'] + 18 : $s['y'] - 10 }}" text-anchor="start" class="num fill-ink-soft text-xs font-semibold">{{ __('Start') }} {{ (int) round($s['rating']) }}</text>

                        @if ($peakIndex !== $currentIndex && $peakIndex !== 0)
                            @php $p = $series[$peakIndex]; @endphp
                            <circle cx="{{ $p['x'] }}%" cy="{{ $p['y'] }}" r="4.5" fill="var(--color-orange-500)" stroke="var(--color-surface)" stroke-width="2" />
                            @if (abs($p['x'] - $series[$currentIndex]['x']) > 12 && abs($p['x'] - $series[0]['x']) > 12)
                                <text x="{{ $p['x'] }}%" y="{{ $p['y'] - 11 }}" text-anchor="{{ $anchor($p['x']) }}" class="num fill-ink-soft text-xs font-bold">{{ __('Peak') }} {{ (int) round($p['rating']) }}</text>
                            @endif
                        @endif
                    @endif

                    @php $c = $series[$currentIndex]; @endphp
                    <circle cx="{{ $c['x'] }}%" cy="{{ $c['y'] }}" r="5" fill="currentColor" stroke="var(--color-surface)" stroke-width="2" />
                    @if ($markers)
                        <text x="{{ $c['x'] }}%" y="{{ $c['y'] - 12 }}" text-anchor="{{ $count > 1 ? $anchor($c['x']) : 'middle' }}" class="num fill-ink text-[17px] font-extrabold">{{ (int) round($c['rating']) }}</text>
                    @endif

                    {{-- Hover crosshair --}}
                    @if ($interactive)
                        <g x-show="i !== null" x-cloak>
                            <line x-bind:x1="i === null ? 0 : pts[i].x + '%'" x-bind:x2="i === null ? 0 : pts[i].x + '%'" y1="{{ $top - 6 }}" y2="{{ $plotBottom }}" stroke="var(--color-line-strong)" stroke-width="1" />
                            <circle x-bind:cx="i === null ? 0 : pts[i].x + '%'" x-bind:cy="i === null ? 0 : pts[i].y" r="5.5" fill="currentColor" stroke="var(--color-surface)" stroke-width="2.5" />
                        </g>
                    @endif
                </svg>

                @if ($interactive)
                    <div
                        x-show="i !== null"
                        x-cloak
                        class="pointer-events-none absolute z-10 min-w-32 border border-line bg-surface px-3 py-2 shadow-card"
                        x-bind:style="i === null ? '' : (pts[i].x > 70
                            ? `right: calc(${100 - pts[i].x}% + 12px); top: ${pts[i].y}px; transform: translateY(-50%)`
                            : `left: calc(${pts[i].x}% + 12px); top: ${pts[i].y}px; transform: translateY(-50%)`)"
                        aria-hidden="true"
                    >
                        <p class="flex items-baseline gap-2">
                            <span class="num text-xl font-extrabold leading-none text-ink" x-text="i === null ? '' : pts[i].v"></span>
                            <span
                                class="num text-sm font-bold"
                                x-show="i !== null && pts[i].c !== null"
                                x-bind:class="i === null ? '' : (pts[i].c > 0 ? 'text-win' : (pts[i].c < 0 ? 'text-loss' : 'text-ink-muted'))"
                                x-text="i === null || pts[i].c === null ? '' : (pts[i].c > 0 ? `+${pts[i].c}` : (pts[i].c < 0 ? `−${Math.abs(pts[i].c)}` : '±0'))"
                            ></span>
                        </p>
                        <p class="mt-1 text-xs whitespace-nowrap text-ink-muted" x-text="i === null ? '' : pts[i].d"></p>
                    </div>
                @endif
            </div>
        </div>

        <figcaption class="sr-only">
            {{ $summary }}
            @if ($count <= 120)
                <span>{{ __('All values:') }}</span>
                @foreach ($series as $p)
                    <span>{{ $p['date']?->format('j M') }} {{ (int) round($p['rating']) }}{{ $loop->last ? '.' : ',' }}</span>
                @endforeach
            @endif
        </figcaption>

        @if ($baseline !== null || $table)
            <div class="ms-11 mt-1 flex flex-wrap items-start gap-x-6 gap-y-2">
                @if ($baseline !== null)
                    <p class="inline-flex items-center gap-2 text-xs text-ink-muted" aria-hidden="true">
                        <svg width="22" height="4" class="shrink-0"><line x1="0" x2="22" y1="2" y2="2" stroke="var(--color-line-strong)" stroke-width="1.5" stroke-dasharray="5 4" /></svg>
                        {{ $baselineLabel ?? __('Starting rating :n', ['n' => (int) $baseline]) }}
                    </p>
                @endif

                @if ($table)
                    <details class="group/table w-full">
                        <summary class="inline-flex min-h-6 cursor-pointer list-none items-center gap-1 text-xs font-medium text-link hover:text-link-hover [&::-webkit-details-marker]:hidden">
                            <flux:icon.chevron-right variant="micro" class="size-3.5 transition-transform group-open/table:rotate-90" />
                            {{ __('Show the numbers') }}
                        </summary>
                        <div class="relative mt-3 max-h-72 overflow-y-auto border border-line">
                            <table class="w-full text-sm">
                                <caption class="sr-only">{{ $label }}</caption>
                                <thead class="sticky top-0 bg-surface-muted text-[11px] uppercase tracking-label text-ink-muted">
                                    <tr>
                                        <th class="px-3 py-2 text-start font-semibold">{{ __('Date') }}</th>
                                        <th class="px-3 py-2 text-end font-semibold">{{ $label }}</th>
                                        <th class="px-3 py-2 text-end font-semibold">{{ __('Change') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($series->reverse() as $i => $p)
                                        @php $opening = $i === 0 && $count > 1 && $p['change'] === null; @endphp
                                        <tr class="border-t border-line">
                                            <td class="px-3 py-1.5 text-ink-soft">
                                                @if ($p['match_id'] && ! $opening && Route::has('matches.show'))
                                                    <a href="{{ route('matches.show', $p['match_id']) }}" wire:navigate class="hover:text-link hover:underline">{{ $p['date']?->format('D j M Y') ?? '–' }}</a>
                                                @else
                                                    {{ $p['date']?->format('D j M Y') ?? '–' }}
                                                @endif
                                            </td>
                                            <td class="num px-3 py-1.5 text-end text-[15px] font-bold text-ink">{{ (int) round($p['rating']) }}</td>
                                            <td class="px-3 py-1.5 text-end">
                                                @if ($opening)
                                                    <span class="label-caps text-ink-muted">{{ __('Start') }}</span>
                                                @elseif ($p['change'] !== null)
                                                    <x-smash.rating-delta :value="$p['change']" size="sm" label="" />
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endif
            </div>
        @endif
    @endif
</figure>
