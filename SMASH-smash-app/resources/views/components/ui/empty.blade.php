@props([
    'title',
    // Heroicon name; defaults to the shuttlecock glyph.
    'icon' => null,
    // center · start
    'align' => 'center',
    // muted (grey band box) · plain (no background, e.g. inside a card) · bordered
    'variant' => 'muted',
])

{{-- Empty state. Default slot = explanation; $actions = buttons. --}}
<div {{ $attributes->class([
    'flex flex-col gap-4 px-6 py-12 sm:px-10',
    'items-center text-center' => $align === 'center',
    'items-start text-start' => $align !== 'center',
    'bg-canvas-muted' => $variant === 'muted',
    'border border-dashed border-line-strong' => $variant === 'bordered',
]) }}>
    <span class="inline-flex size-14 items-center justify-center bg-surface text-navy-800 ring-1 ring-line dark:text-navy-200">
        @if ($icon)
            <flux:icon :icon="$icon" variant="outline" class="size-7" />
        @else
            <x-ui.shuttle class="size-9" />
        @endif
    </span>
    <div class="max-w-md">
        <p class="font-serif text-h4 text-heading">{{ $title }}</p>
        @if (trim((string) $slot) !== '')
            <div class="mt-2 text-[15px] leading-relaxed text-ink-soft">{{ $slot }}</div>
        @endif
    </div>
    @isset($actions)
        <div @class(['mt-2 flex flex-wrap gap-3', 'justify-center' => $align === 'center'])>{{ $actions }}</div>
    @endisset
</div>
