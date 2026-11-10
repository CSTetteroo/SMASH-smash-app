@props([
    'value',
    'label',
    'sublabel' => null,
    // Signed change shown next to the label (e.g. rating change). See x-ui.delta.
    'delta' => null,
    'deltaSuffix' => null,
    // Small unit after the value ("%", "pts").
    'suffix' => null,
    // sm · md · lg
    'size' => 'md',
    // Colour of the number: heading (default) · ink · smash · green · orange
    'tone' => null,
    'align' => 'start',
])

@php
    $valueSize = match ($size) {
        'sm' => 'text-[2rem]',
        'lg' => 'text-[clamp(3.25rem,2.4rem+3vw,5rem)]',
        default => 'text-stat',
    };

    $valueColour = match ($tone) {
        'ink' => 'text-ink',
        'smash' => 'text-smash-500',
        'green' => 'text-win',
        'orange' => 'text-orange-500',
        default => 'text-heading',
    };
@endphp

<div {{ $attributes->class(['flex min-w-0 flex-col gap-1.5', 'items-center text-center' => $align === 'center', 'items-end text-end' => $align === 'end']) }}>
    <p class="num font-extrabold leading-none {{ $valueSize }} {{ $valueColour }}">
        {{ $value }}@if ($suffix)<span class="ms-0.5 text-[0.5em] font-bold">{{ $suffix }}</span>@endif
    </p>
    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 label-caps text-ink-muted">
        <span>{{ $label }}</span>
        @if ($delta !== null)
            <x-ui.delta :value="$delta" :suffix="$deltaSuffix" size="sm" />
        @endif
    </p>
    @if ($sublabel)
        <p class="text-sm text-ink-soft">{{ $sublabel }}</p>
    @endif
    {{ $slot }}
</div>
