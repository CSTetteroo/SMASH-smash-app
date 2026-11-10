@props([
    /*
     | x-predictions.pick-button — one pick'em choice, drawn like a scoreboard cell: "2–0", "2–1"
     | (or "Win" for single-game matches). Side A picks fill navy, side B picks fill orange, like the
     | win-probability bar. Renders a link when href is set (guests: to the login page).
     |
     | label      Visible text ("2–1").
     | side       a · b (colour of the selected state).
     | selected   This is the member's current pick (aria-pressed).
     | href       Link instead of a button (guests).
     | disabled   Locked (match started, playing in it).
     | sr         Screen-reader label ("Wei Chen wins 2–1").
     | action     Livewire action for wire:click ("pick(234, 'a', 1)"); none when locked.
     */
    'label',
    'side' => 'a',
    'selected' => false,
    'href' => null,
    'disabled' => false,
    'sr' => null,
    'action' => null,
])

@php
    $classes = [
        'relative inline-flex min-h-11 w-14 shrink-0 select-none items-center justify-center border px-2',
        'num text-[19px] font-bold leading-none',
        'transition-[background-color,border-color,color,opacity] duration-150 ease-brand',
        'disabled:cursor-not-allowed disabled:opacity-40 data-loading:opacity-60',
        'border-navy-800 bg-navy-800 text-white dark:border-navy-400 dark:bg-navy-400 dark:text-navy-950' => $selected && $side === 'a',
        'border-orange-400 bg-orange-400 text-navy-950' => $selected && $side === 'b',
        'border-line-strong bg-surface text-ink' => ! $selected,
        'hover:border-navy-800 hover:bg-navy-50 dark:hover:border-navy-300 dark:hover:bg-white/6' => ! $selected && ! $disabled && $side === 'a',
        'hover:border-orange-500 hover:bg-orange-50 dark:hover:border-orange-400 dark:hover:bg-white/6' => ! $selected && ! $disabled && $side === 'b',
    ];
@endphp

@if ($href && ! $disabled)
    <a href="{{ $href }}" {{ $attributes->class($classes) }} @if ($sr) aria-label="{{ $sr }}" @endif data-pick>
        {{ $label }}
    </a>
@else
    <button
        type="button"
        @disabled($disabled)
        aria-pressed="{{ $selected ? 'true' : 'false' }}"
        @if ($sr) aria-label="{{ $sr }}" @endif
        @if ($action && ! $disabled) wire:click="{{ $action }}" @endif
        {{ $attributes->class($classes) }}
        data-pick
        @if ($selected) data-pick-selected @endif
    >
        @if ($selected)
            <flux:icon.check variant="micro" class="absolute top-0.5 right-0.5 size-3" aria-hidden="true" />
        @endif
        {{ $label }}
    </button>
@endif
