@props([
    // null renders a <span> (use inside an href card, which is already the link).
    'href' => null,
    // caps (BUas "VIEW ALL EVENTS →") · promo (underlined, small arrow) · serif (navy serif link) · back (‹ label)
    'variant' => 'caps',
    'navigate' => null,
    'external' => false,
])

@php
    $navigate ??= is_string($href) && ! $external
        && (str_starts_with($href, '/') || str_starts_with($href, url('/')));

    $tag = $href ? 'a' : 'span';

    $classes = match ($variant) {
        'promo' => 'group/link inline-flex items-center gap-2 border-b border-current/40 pb-1 text-sm font-medium text-link hover:text-link-hover',
        'serif' => 'group/link inline-flex items-baseline gap-2 font-serif text-[19px] leading-snug text-heading hover:underline underline-offset-4 decoration-1',
        'back' => 'group/link inline-flex min-h-11 items-center gap-1 text-[15px] font-medium text-link hover:text-link-hover',
        default => 'group/link inline-flex items-center gap-2.5 text-[13px] font-semibold uppercase tracking-[0.1em] text-link hover:text-link-hover',
    };
@endphp

<{{ $tag }}
    @if ($href)
        href="{{ $href }}"
        @if ($external) target="_blank" rel="noopener noreferrer" @endif
        @if ($navigate) wire:navigate @endif
    @endif
    {{ $attributes->class($classes) }}
>
    @if ($variant === 'back')
        <flux:icon.chevron-left variant="mini" class="-ms-1 size-5 transition-transform duration-200 ease-brand group-hover/link:-translate-x-0.5" />
    @endif
    <span>{{ $slot }}</span>
    @if ($variant === 'caps')
        <svg viewBox="0 0 26 14" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true" class="h-3 w-[22px] shrink-0 transition-transform duration-300 ease-brand group-hover/link:translate-x-1.5 group-hover/card:translate-x-1.5"><path d="M0 7h24.5M18.5 1l6 6-6 6" /></svg>
    @elseif ($variant === 'promo')
        <flux:icon.arrow-right variant="micro" class="size-4 transition-transform duration-200 ease-brand group-hover/link:translate-x-0.5" />
    @elseif ($external)
        <flux:icon.arrow-up-right variant="micro" class="size-3.5 opacity-70" />
    @endif
</{{ $tag }}>
