@aware([
    // Inherited from the parent x-ui.tabs variant.
    'variant' => 'underline',
])

@props([
    'href' => null,
    'current' => false,
    // Optional count badge ("12").
    'count' => null,
    'icon' => null,
    'navigate' => true,
])

@php
    $tag = $href ? 'a' : 'button';
    $classes = $variant === 'pills'
        ? [
            'inline-flex h-9 items-center gap-2 px-3.5 text-[13px] font-semibold whitespace-nowrap transition-colors',
            'bg-surface text-navy-800 shadow-[0_1px_2px_rgb(15_23_32/0.12)] dark:text-white' => $current,
            'text-ink-soft hover:text-navy-800 dark:hover:text-white' => ! $current,
        ]
        : [
            'group/tab relative inline-flex h-12 items-center gap-2 text-[13px] font-semibold uppercase tracking-[0.06em] whitespace-nowrap transition-colors',
            'after:absolute after:inset-x-0 after:-bottom-px after:h-[3px] after:transition-colors',
            'text-navy-800 after:bg-navy-800 dark:text-white dark:after:bg-navy-400' => $current,
            'text-ink-soft after:bg-transparent hover:text-navy-800 hover:after:bg-line-strong dark:hover:text-white' => ! $current,
        ];
@endphp

<li class="flex shrink-0">
    <{{ $tag }}
        @if ($href)
            href="{{ $href }}"
            @if ($navigate) wire:navigate @endif
            @if ($current) aria-current="page" @endif
        @else
            type="button"
            aria-pressed="{{ $current ? 'true' : 'false' }}"
        @endif
        {{ $attributes->class($classes) }}
    >
        @if ($icon)
            <flux:icon :icon="$icon" variant="micro" class="size-4" />
        @endif
        <span>{{ $slot }}</span>
        @if ($count !== null)
            <span @class([
                'num inline-flex min-w-5 items-center justify-center px-1 text-[13px] font-bold leading-5 tracking-normal',
                'bg-navy-800 text-white dark:bg-navy-400 dark:text-navy-950' => $current,
                'bg-zinc-200 text-ink-soft dark:bg-white/10' => ! $current,
            ])>{{ $count }}</span>
        @endif
    </{{ $tag }}>
</li>
