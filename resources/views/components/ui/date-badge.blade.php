@props([
    // CarbonInterface | DateTimeInterface | string
    'date',
    // light (navy serif on the surface) · navy (white on navy block) · outline (hairline box)
    'variant' => 'light',
    // Optional time line under the month ("19:30"). Pass true to format from $date.
    'time' => null,
    // Show the weekday above the day ("TUE").
    'weekday' => false,
    // sm · md · lg
    'size' => 'md',
])

@php
    $moment = $date instanceof \DateTimeInterface
        ? \Carbon\CarbonImmutable::instance($date)
        : \Carbon\CarbonImmutable::parse((string) $date);

    $timeLabel = $time === true ? $moment->format('H:i') : $time;

    $sizes = [
        'sm' => ['box' => 'w-12', 'day' => 'text-[22px]', 'month' => 'text-[13px]', 'pad' => 'py-1.5'],
        'md' => ['box' => 'w-16', 'day' => 'text-[30px]', 'month' => 'text-[17px]', 'pad' => 'py-2.5'],
        'lg' => ['box' => 'w-20', 'day' => 'text-[40px]', 'month' => 'text-[21px]', 'pad' => 'py-3'],
    ];
    $s = $sizes[$size] ?? $sizes['md'];

    $variantClass = match ($variant) {
        'navy' => 'theme-navy',
        'outline' => 'border border-line bg-surface',
        default => '',
    };
@endphp

<time
    datetime="{{ $moment->toIso8601String() }}"
    {{ $attributes->class([
        'inline-flex shrink-0 flex-col items-center text-center font-serif leading-none text-heading',
        $s['box'],
        $variantClass,
        $s['pad'] => $variant !== 'light',
    ]) }}
    data-ui-date-badge
>
    @if ($weekday)
        <span class="mb-1 font-sans text-[10px] font-semibold uppercase tracking-label text-ink-muted">{{ $moment->format('D') }}</span>
    @endif
    <span class="{{ $s['day'] }}">{{ $moment->format('j') }}</span>
    <span class="mt-0.5 uppercase tracking-[0.04em] {{ $s['month'] }}">{{ $moment->format('M') }}</span>
    @if ($timeLabel)
        <span class="mt-1.5 font-sans text-xs font-medium text-ink-soft">{{ $timeLabel }}</span>
    @endif
</time>
