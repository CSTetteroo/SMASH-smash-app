@props([
    // navy · sky · orange · green · smash · grey (matches the enums' tone() helpers)
    'tone' => 'navy',
    // outline (BUas chip) · solid · soft
    'variant' => 'outline',
    // sm · md · lg
    'size' => 'md',
    // Leading Heroicon name.
    'icon' => null,
    // Leading dot; "pulse" makes it a live indicator.
    'dot' => false,
    // Turns the tag into a link (filter chips).
    'href' => null,
    // Visually selected (for filter chips).
    'active' => false,
])

@php
    $variantClass = match ($active ? 'solid' : $variant) {
        'solid' => 'border border-transparent bg-[var(--tone-solid)] text-[var(--tone-on)]',
        'soft' => 'border border-transparent bg-[var(--tone-soft)] text-[var(--tone-ink)]',
        default => 'border border-[var(--tone)] text-[var(--tone-ink)]',
    };

    $sizeClass = match ($size) {
        'sm' => 'h-5 gap-1 px-1.5 text-[10px]',
        'lg' => 'min-h-9 gap-2 px-3 py-1.5 text-xs',
        default => 'h-6 gap-1.5 px-2 text-[11px]',
    };

    $classes = [
        'tone-'.$tone,
        'inline-flex max-w-full shrink-0 items-center whitespace-nowrap font-semibold uppercase leading-none tracking-label',
        $variantClass,
        $sizeClass,
        'transition-colors hover:bg-[var(--tone-soft)]' => $href && ! $active,
    ];
@endphp

@if ($href)
    <a href="{{ $href }}" wire:navigate @if ($active) aria-current="true" @endif {{ $attributes->class($classes) }}>
@else
    <span {{ $attributes->class($classes) }}>
@endif
    @if ($dot)
        <span @class([
            'inline-block size-1.5 shrink-0 rounded-full',
            'bg-current' => $variant === 'solid' || $active,
            'bg-[var(--tone)]' => $variant !== 'solid' && ! $active,
            'animate-live' => $dot === 'pulse',
        ]) aria-hidden="true"></span>
    @endif
    @if ($icon)
        <flux:icon :icon="$icon" variant="micro" class="{{ $size === 'sm' ? 'size-3' : 'size-3.5' }} shrink-0" />
    @endif
    <span class="truncate">{{ $slot }}</span>
@if ($href)
    </a>
@else
    </span>
@endif
