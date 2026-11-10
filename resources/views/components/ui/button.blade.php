@props([
    // cta · primary · outline · ghost · danger · danger-outline · pill · pill-navy · pill-green · pill-orange · pill-outline · link
    'variant' => 'primary',
    // sm · md · lg
    'size' => 'md',
    // Renders an <a> when set, otherwise a <button>.
    'href' => null,
    'type' => 'button',
    // Heroicon names (same names as flux:icon).
    'icon' => null,
    'iconTrailing' => null,
    // The BUas long arrow that slides on hover. Defaults to on for "cta" and "link".
    'arrow' => null,
    'disabled' => false,
    // Opens in a new tab, never wire:navigate.
    'external' => false,
    // wire:navigate on internal links. null = automatic (internal hrefs only).
    'navigate' => null,
    // Full width.
    'block' => false,
    // Spinner while Livewire runs this button's action. null = automatic (wire:click or submit).
    'loading' => null,
    // Icon-only square/circle button (pass an aria-label!).
    'square' => false,
])

@php
    $isPill = str_starts_with($variant, 'pill');
    $isLink = $variant === 'link';
    $arrow ??= in_array($variant, ['cta', 'link'], true) && ! $square;

    $navigate ??= is_string($href) && ! $external
        && (str_starts_with($href, '/') || str_starts_with($href, url('/')));

    $loading ??= ! $href && (
        ($type === 'submit' && ! $disabled)
        || $attributes->whereStartsWith('wire:click')->isNotEmpty()
    );

    $sizeClasses = match (true) {
        $isLink => 'gap-2.5 text-[13px]',
        $isPill => match ($size) {
            'sm' => $square ? 'size-9' : 'h-9 gap-2 px-4 text-sm',
            'lg' => $square ? 'size-13' : 'h-13 gap-2.5 px-6 text-[17px]',
            default => $square ? 'size-11' : 'h-11 gap-2.5 px-5 text-[15px]',
        },
        default => match ($size) {
            // min-h + padding (not a fixed height): long labels may wrap on phones, like on buas.nl.
            'sm' => $square ? 'size-9' : 'min-h-9 gap-2 px-4 py-2 text-[11px] leading-tight',
            'lg' => $square ? 'size-14' : 'min-h-14 gap-3.5 px-7 py-3.5 text-sm leading-tight',
            default => $square ? 'size-12' : 'min-h-12 gap-3 px-6 py-3 text-[13px] leading-tight',
        },
    };

    $shapeClasses = match (true) {
        $isLink => 'font-semibold uppercase tracking-[0.1em]',
        $isPill => 'rounded-full font-medium',
        default => 'rounded-none font-semibold uppercase tracking-caps',
    };

    $variantClasses = match ($variant) {
        'cta' => 'bg-cta text-white hover:bg-cta-hover',
        'outline' => 'border border-navy-800 text-navy-800 hover:bg-navy-50 dark:border-navy-300 dark:text-navy-100 dark:hover:bg-white/6',
        'ghost' => 'text-navy-800 hover:bg-zinc-100 dark:text-navy-100 dark:hover:bg-white/8',
        'danger' => 'bg-smash-600 text-white hover:bg-smash-700',
        'danger-outline' => 'border border-smash-600 text-smash-600 hover:bg-smash-50 dark:border-smash-400 dark:text-smash-300 dark:hover:bg-smash-400/10',
        'pill' => 'bg-navy-100 text-navy-800 hover:bg-[color-mix(in_srgb,var(--color-navy-100),var(--color-navy-800)_10%)] dark:bg-navy-900 dark:text-navy-100 dark:hover:bg-navy-800',
        'pill-navy' => 'bg-navy-800 text-white hover:bg-[color-mix(in_srgb,var(--color-navy-800),black_12%)] dark:bg-navy-600 dark:hover:bg-navy-700',
        'pill-green' => 'bg-cta text-white hover:bg-cta-hover',
        'pill-orange' => 'bg-orange-400 text-navy-900 hover:bg-white hover:text-navy-900',
        'pill-outline' => 'border border-line-strong text-navy-800 hover:border-navy-800 dark:text-navy-100 dark:hover:border-navy-300',
        'link' => 'text-link hover:text-link-hover',
        default => 'bg-navy-800 text-white hover:bg-navy-900 dark:bg-navy-600 dark:hover:bg-navy-700',
    };

    $iconSize = ($isPill && $size === 'lg') || ($square && $size !== 'sm') ? 'size-5' : 'size-4';
    $iconVariant = $iconSize === 'size-5' ? 'mini' : 'micro';

    $classes = [
        'group/btn relative inline-flex select-none items-center justify-center',
        $isPill || $isLink || $square ? 'shrink-0 whitespace-nowrap' : 'max-w-full text-start',
        'transition-[background-color,border-color,color,opacity] duration-150 ease-brand',
        'disabled:cursor-not-allowed disabled:opacity-45 aria-disabled:cursor-not-allowed aria-disabled:opacity-45',
        $sizeClasses,
        $shapeClasses,
        $variantClasses,
        'w-full' => $block,
        'ui-btn-loadable' => $loading,
        'p-0' => $isLink,
    ];

    $tag = $href && ! $disabled ? 'a' : ($href ? 'span' : 'button');
@endphp

<{{ $tag }}
    @if ($tag === 'a')
        href="{{ $href }}"
        @if ($external) target="_blank" rel="noopener noreferrer" @endif
        @if ($navigate) wire:navigate @endif
    @elseif ($tag === 'span')
        role="link" aria-disabled="true"
    @else
        type="{{ $type }}"
        @disabled($disabled)
    @endif
    {{ $attributes->class($classes) }}
    data-ui-button
>
    @if ($loading)
        <span class="ui-btn-spinner pointer-events-none absolute inset-0 flex items-center justify-center opacity-0 transition-opacity" aria-hidden="true">
            <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" /><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
        </span>
    @endif

    <span @class(['ui-btn-content inline-flex items-center transition-opacity', 'gap-[inherit]'])>
        @if ($icon)
            <flux:icon :icon="$icon" :variant="$iconVariant" class="{{ $iconSize }} shrink-0" />
        @endif

        @unless ($square)
            <span>{{ $slot }}</span>
        @else
            {{ $slot }}
        @endunless

        @if ($iconTrailing)
            <flux:icon :icon="$iconTrailing" :variant="$iconVariant" class="{{ $iconSize }} shrink-0 transition-transform duration-200 ease-brand group-hover/btn:translate-x-0.5" />
        @endif

        @if ($arrow)
            <svg
                viewBox="0 0 26 14"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
                aria-hidden="true"
                @class([
                    'shrink-0 transition-transform duration-300 ease-brand group-hover/btn:translate-x-1.5',
                    'h-3 w-5' => $size === 'sm' && ! $isLink,
                    'h-3.5 w-[26px]' => $size !== 'sm' || $isLink,
                ])
            ><path d="M0 7h24.5M18.5 1l6 6-6 6" /></svg>
        @endif
    </span>
</{{ $tag }}>
