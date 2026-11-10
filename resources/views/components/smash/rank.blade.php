@props([
    /*
     | x-smash.rank — a ranking position, with optional movement (renders x-ui.rank and
     | x-ui.movement).
     |
     | position     1-based position; null = unranked ("–").
     | movement     Places moved since last time: int (+ = up) or null (NEW, not ranked before).
     |              Leave it out (false) to show no movement. LeaderboardRow::movement fits.
     | variant      box (default; x-ui.rank: #1 SMASH red, #2–3 navy) · plain (big number, "#3")
     | size         sm · md · lg (box) / sm · md · lg · xl (plain)
     | of           Number of ranked players, shown as "of 58" (plain variant).
     | label        Caption under a plain rank ("Singles rank").
     */
    'position' => null,
    'movement' => false,
    'variant' => 'box',
    'size' => 'md',
    'of' => null,
    'label' => null,
])

@php
    $position = $position === null ? null : (int) $position;

    $plainSize = match ($size) {
        'sm' => 'text-2xl',
        'lg' => 'text-[clamp(2.75rem,2.2rem+2vw,3.75rem)]',
        'xl' => 'text-[clamp(3.5rem,2.6rem+3.5vw,5.5rem)]',
        default => 'text-[2.25rem]',
    };

    $plainTone = match (true) {
        $position === null => 'text-ink-muted',
        $position === 1 => 'text-smash-500',
        $position <= 3 => 'text-heading',
        default => 'text-ink',
    };
@endphp

@if ($variant === 'plain')
    <span {{ $attributes->class('inline-flex flex-col') }} data-smash-rank>
        <span class="inline-flex items-end gap-2">
            <span class="num font-extrabold leading-[0.85] {{ $plainSize }} {{ $plainTone }}">
                @if ($position === null)
                    <span aria-hidden="true">–</span><span class="sr-only">{{ __('Unranked') }}</span>
                @else
                    <span class="sr-only">{{ __('Rank') }}</span><span class="me-px align-top text-[0.5em] font-bold" aria-hidden="true">#</span>{{ $position }}
                @endif
            </span>
            @if ($of && $position !== null)
                <span class="num mb-0.5 text-[15px] font-semibold leading-none text-ink-muted">{{ __('of :n', ['n' => $of]) }}</span>
            @endif
            @if ($movement !== false)
                <x-ui.movement :value="$movement" class="mb-0.5" />
            @endif
        </span>
        @if ($label)
            <span class="mt-2 label-caps text-ink-muted">{{ $label }}</span>
        @endif
    </span>
@else
    <span {{ $attributes->class('inline-flex items-center gap-2') }} data-smash-rank>
        <x-ui.rank :position="$position" :size="$size" />
        @if ($movement !== false)
            <x-ui.movement :value="$movement" />
        @endif
    </span>
@endif
