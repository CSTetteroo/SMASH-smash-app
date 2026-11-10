@props([
    /*
     | x-predictions.factor — one line of the predictor's factor breakdown: what it is, the
     | plain-English detail, and a diverging bar that points towards the side it helps
     | (side A left in navy, side B right in orange, like the win-probability bar).
     |
     | label    "Rating", "Recent form", "Head-to-head", "Effective gap"
     | detail   The predictor's sentence ("4–1 vs 2–3 in the last 5").
     | shift    Elo points in side A's favour (negative = side B).
     | scale    The shift that fills half the track (use the largest shift in the breakdown).
     | nameA/B  Short side names for the value line ("Wei", "Chen & De Vries").
     | total    Emphasised summary row.
     */
    'label',
    'detail' => null,
    'shift' => 0.0,
    'scale' => 100.0,
    'nameA' => 'Side A',
    'nameB' => 'Side B',
    'total' => false,
])

@php
    $shift = (float) $shift;
    $points = (int) round(abs($shift));
    $towards = $points === 0 ? null : ($shift > 0 ? 'a' : 'b');
    $width = $towards === null ? 0 : max(1.5, min(50, abs($shift) / max(1.0, (float) $scale) * 50));

    $spoken = $towards === null
        ? __(':label: no shift either way.', ['label' => $label])
        : __(':label: :n Elo points towards :side.', ['label' => $label, 'n' => $points, 'side' => $towards === 'a' ? $nameA : $nameB]);
@endphp

<div {{ $attributes->class([
    'grid gap-x-8 gap-y-3 py-4 sm:grid-cols-[minmax(0,1fr)_minmax(12rem,16rem)] sm:items-center',
]) }} data-prediction-factor>
    <div class="min-w-0">
        <p @class([
            'text-[15px] text-ink',
            'font-bold' => $total,
            'font-semibold' => ! $total,
        ])>{{ $label }}</p>
        @if ($detail)
            <p class="mt-0.5 text-sm leading-snug text-ink-soft">{{ $detail }}</p>
        @endif
    </div>

    <div class="min-w-0">
        <p class="sr-only">{{ $spoken }}</p>
        <div @class(['relative bg-canvas-muted', $total ? 'h-3' : 'h-2']) aria-hidden="true">
            @if ($towards === 'a')
                <span class="absolute inset-y-0 right-1/2 bg-heading" style="width: {{ round($width, 2) }}%"></span>
            @elseif ($towards === 'b')
                <span class="absolute inset-y-0 left-1/2 bg-orange-400" style="width: {{ round($width, 2) }}%"></span>
            @endif
            <span class="absolute -inset-y-1 left-1/2 w-px bg-line-strong"></span>
        </div>
        <p class="mt-2 flex items-baseline justify-between gap-3 text-[13px] leading-none" aria-hidden="true">
            @if ($towards === 'a')
                <span class="min-w-0 truncate"><span class="num text-[17px] font-extrabold text-heading">+{{ $points }}</span> <span class="text-ink-soft">{{ $nameA }}</span></span>
            @elseif ($towards === null)
                <span class="w-full text-center label-caps text-ink-muted">{{ __('Even') }}</span>
            @endif
            @if ($towards === 'b')
                <span class="ms-auto min-w-0 truncate text-end"><span class="text-ink-soft">{{ $nameB }}</span> <span class="num text-[17px] font-extrabold text-orange-700 dark:text-orange-300">+{{ $points }}</span></span>
            @endif
        </p>
    </div>
</div>
