@props([
    // Makes the whole card a link (do not nest other links/buttons inside).
    'href' => null,
    'navigate' => null,
    // 4px coloured top bar (BUas highlighted card): navy · sky · orange · green · smash · grey
    'highlight' => null,
    // default (white + hairline) · shadow (BUas card shadow, no border) · muted (grey band) · navy · outline
    'variant' => 'default',
    // none · sm · md · lg
    'padding' => 'md',
    // Element for non-link cards: div · article · section · li
    'as' => 'div',
])

@php
    $navigate ??= is_string($href) && (str_starts_with($href, '/') || str_starts_with($href, url('/')));

    $variantClass = match ($variant) {
        'shadow' => 'bg-surface shadow-card',
        'muted' => 'bg-canvas-muted',
        'navy' => 'theme-navy',
        'outline' => 'border border-line-strong',
        default => 'border border-line bg-surface',
    };

    $paddingClass = match ($padding) {
        'none' => '',
        'sm' => 'p-4',
        'lg' => 'p-6 sm:p-8',
        default => 'p-5 sm:p-6',
    };

    $classes = [
        'group/card relative flex min-w-0 flex-col text-ink-soft',
        $variantClass,
        $paddingClass,
        'tone-'.$highlight.' border-t-4 border-t-[var(--tone)]' => $highlight,
        'transition-[box-shadow,border-color,transform] duration-200 ease-brand hover:shadow-raised focus-visible:outline-offset-4' => $href,
        'hover:border-line-strong' => $href && $variant === 'default',
    ];
@endphp

@if ($href)
    <a href="{{ $href }}" @if ($navigate) wire:navigate @endif {{ $attributes->class($classes) }} data-ui-card>
        {{ $slot }}
    </a>
@else
    <{{ $as }} {{ $attributes->class($classes) }} data-ui-card>
        {{ $slot }}
    </{{ $as }}>
@endif
