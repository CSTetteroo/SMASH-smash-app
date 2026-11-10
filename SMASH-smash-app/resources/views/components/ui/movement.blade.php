@props([
    // Rank change (+ = moved up). null = not ranked before.
    'value' => null,
    // Show "NEW" when value is null.
    'new' => true,
])

@php
    $value = $value === null ? null : (int) $value;
@endphp

<span {{ $attributes->class('num inline-flex min-w-8 items-center gap-0.5 text-[13px] font-bold leading-none whitespace-nowrap') }}>
    @if ($value === null)
        @if ($new)
            <span class="bg-navy-50 px-1 py-0.5 font-sans text-[9px] font-bold uppercase tracking-label text-navy-600 dark:bg-white/8 dark:text-navy-300">{{ __('New') }}</span>
        @else
            <span class="text-ink-muted" aria-hidden="true">–</span><span class="sr-only">{{ __('No change') }}</span>
        @endif
    @elseif ($value > 0)
        <svg viewBox="0 0 10 10" class="size-2 text-win" aria-hidden="true"><path d="M5 1.5 9.5 8.5h-9z" fill="currentColor" /></svg>
        <span class="text-win" aria-hidden="true">{{ $value }}</span>
        <span class="sr-only">{{ __('Up :n places', ['n' => $value]) }}</span>
    @elseif ($value < 0)
        <svg viewBox="0 0 10 10" class="size-2 text-loss" aria-hidden="true"><path d="M5 8.5.5 1.5h9z" fill="currentColor" /></svg>
        <span class="text-loss" aria-hidden="true">{{ abs($value) }}</span>
        <span class="sr-only">{{ __('Down :n places', ['n' => abs($value)]) }}</span>
    @else
        <span class="text-ink-muted" aria-hidden="true">–</span><span class="sr-only">{{ __('No change') }}</span>
    @endif
</span>
