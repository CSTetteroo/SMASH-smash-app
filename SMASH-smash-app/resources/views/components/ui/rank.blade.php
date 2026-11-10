@props([
    // 1-based position; null = unranked ("–").
    'position' => null,
    // sm · md · lg
    'size' => 'md',
])

{{-- Low-level rank box (#1 in SMASH red, #2–3 navy, the rest plain). Pages use x-smash.rank (adds movement and the big "plain" style). --}}
@php
    $position = $position === null ? null : (int) $position;
    $box = match ($size) {
        'sm' => 'size-6 text-[15px]',
        'lg' => 'size-12 text-[28px]',
        default => 'size-8 text-lg',
    };
@endphp

<span {{ $attributes->class([
    'num inline-flex shrink-0 items-center justify-center font-extrabold leading-none',
    $box,
    'bg-smash-500 text-white' => $position === 1,
    'bg-navy-800 text-white dark:bg-navy-600' => in_array($position, [2, 3], true),
    'text-ink' => $position !== null && $position > 3,
    'text-ink-muted' => $position === null,
]) }} data-ui-rank>
    @if ($position === null)
        <span aria-hidden="true">–</span><span class="sr-only">{{ __('Unranked') }}</span>
    @else
        <span class="sr-only">{{ __('Rank') }}</span>{{ $position }}
    @endif
</span>
