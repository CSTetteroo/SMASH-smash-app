@props([
    'value' => 0,
    'max' => 100,
    // navy · sky · orange · green · smash · grey. null = navy, or automatic when "capacity" is on.
    'tone' => null,
    // Capacity mode: tone turns orange at 85% and smash red when full.
    'capacity' => false,
    // Label on the left above the bar and value text on the right ("18 / 24").
    'label' => null,
    'valueLabel' => null,
    // sm (4px) · md (6px) · lg (10px)
    'size' => 'md',
])

@php
    $max = max(1, (float) $max);
    $ratio = max(0, min(1, ((float) $value) / $max));
    $percent = round($ratio * 100, 1);

    $tone ??= $capacity
        ? ($ratio >= 1 ? 'smash' : ($ratio >= 0.85 ? 'orange' : 'navy'))
        : 'navy';

    $height = match ($size) {
        'sm' => 'h-1',
        'lg' => 'h-2.5',
        default => 'h-1.5',
    };

    $valueText = $valueLabel ?? (rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.').' / '.rtrim(rtrim(number_format($max, 1, '.', ''), '0'), '.'));
@endphp

<div {{ $attributes->class(['tone-'.$tone, 'min-w-0']) }}>
    @if ($label || $valueLabel !== null)
        <div class="mb-2 flex items-baseline justify-between gap-3">
            <span class="label-caps text-ink-muted">{{ $label }}</span>
            <span class="num text-[15px] font-bold text-ink">{{ $valueText }}</span>
        </div>
    @endif
    <div
        role="progressbar"
        aria-valuemin="0"
        aria-valuemax="{{ $max }}"
        aria-valuenow="{{ (float) $value }}"
        @if ($label) aria-label="{{ $label }}" @endif
        aria-valuetext="{{ $valueText }}"
        class="{{ $height }} w-full overflow-hidden bg-zinc-200 dark:bg-white/10"
    >
        <div class="h-full bg-[var(--tone)] transition-[width] duration-500 ease-brand" style="width: {{ $percent }}%"></div>
    </div>
</div>
