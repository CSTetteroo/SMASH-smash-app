@props([
    // Signed number (rating change, rank change…). null renders an en dash.
    'value' => null,
    'precision' => 0,
    'suffix' => null,
    // sm · md · lg
    'size' => 'md',
    // Show ▲ / ▼ triangles.
    'arrow' => true,
    // Invert colours (e.g. for "games conceded").
    'invert' => false,
])

@php
    $number = $value === null ? null : round((float) $value, $precision);
    $direction = $number === null || $number == 0.0 ? 0 : ($number > 0 ? 1 : -1);
    if ($invert) {
        $colourDirection = -$direction;
    } else {
        $colourDirection = $direction;
    }

    $formatted = $number === null
        ? '–'
        : ($direction > 0 ? '+' : ($direction < 0 ? '−' : '±')).number_format(abs($number), $precision);

    $spoken = match (true) {
        $number === null => __('no change'),
        $direction > 0 => __('up :n', ['n' => number_format(abs($number), $precision)]),
        $direction < 0 => __('down :n', ['n' => number_format(abs($number), $precision)]),
        default => __('no change'),
    };

    $sizeClass = match ($size) {
        'sm' => 'text-[13px] gap-0.5',
        'lg' => 'text-xl gap-1',
        default => 'text-[15px] gap-0.5',
    };
@endphp

<span {{ $attributes->class([
    'num inline-flex items-center font-bold leading-none whitespace-nowrap',
    $sizeClass,
    'text-win' => $colourDirection > 0,
    'text-loss' => $colourDirection < 0,
    'text-ink-muted' => $colourDirection === 0,
]) }}>
    <span class="sr-only">{{ $spoken }}{{ $suffix ? ' '.$suffix : '' }}</span>
    @if ($arrow && $direction !== 0)
        <svg viewBox="0 0 10 10" class="size-[0.62em] shrink-0" aria-hidden="true">
            @if ($direction > 0)
                <path d="M5 1.5 9.5 8.5h-9z" fill="currentColor" />
            @else
                <path d="M5 8.5.5 1.5h9z" fill="currentColor" />
            @endif
        </svg>
    @endif
    <span aria-hidden="true">{{ $formatted }}@if ($suffix)<span class="ms-1 font-semibold">{{ $suffix }}</span>@endif</span>
</span>
