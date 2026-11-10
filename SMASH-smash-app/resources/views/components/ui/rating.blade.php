@props([
    // Rating value (int or float; rounded for display). null renders an en dash (unrated).
    'value' => null,
    // Fewer than config('smash.rating.provisional_matches') rated matches: orange "P" with a tooltip.
    'provisional' => false,
    // xs 15px · sm 17px · md 22px · lg 32px · xl stat size
    'size' => 'md',
    // Optional caption under the number ("Singles").
    'label' => null,
    // Softer colour, e.g. pre-match ratings next to a name.
    'muted' => false,
])

{{--
    Low-level rating number. Pages use x-smash.rating, which renders this one and can read the
    value and provisional state from a User. Default slot = trailing content in the number row
    (x-smash.rating puts the rating change there).
--}}
@php
    $provisional = $provisional && $value !== null;
    $threshold = (int) config('smash.rating.provisional_matches', 10);

    $numberClass = match ($size) {
        'xs' => 'text-[15px]',
        'sm' => 'text-[17px]',
        'lg' => 'text-[2rem]',
        'xl' => 'text-stat',
        default => 'text-[22px]',
    };

    $markerClass = match ($size) {
        'xs', 'sm' => 'h-3.5 min-w-3.5 text-[8px]',
        'lg', 'xl' => 'h-5 min-w-5 text-[11px]',
        default => 'h-4 min-w-4 text-[9px]',
    };
@endphp

<span {{ $attributes->class('inline-flex flex-col') }} data-ui-rating>
    <span class="inline-flex items-start gap-1">
        <span @class([
            'num leading-none',
            $numberClass,
            $muted ? 'font-semibold text-ink-muted' : 'font-bold text-ink',
        ])>{{ $value === null ? '–' : (int) round((float) $value) }}</span>
        @if ($value === null)
            <span class="sr-only">{{ __('Unrated') }}</span>
        @endif
        @if ($provisional)
            <flux:tooltip :content="__('Provisional: fewer than :n rated matches, so this rating still moves quickly.', ['n' => $threshold])" position="top">
                <span
                    class="{{ $markerClass }} inline-flex cursor-help items-center justify-center border border-orange-500 px-0.5 font-sans font-bold leading-none text-orange-700 dark:border-orange-400 dark:text-orange-300 in-[.theme-navy]:border-orange-400 in-[.theme-navy]:text-orange-300"
                    aria-hidden="true"
                >P</span>
            </flux:tooltip>
            <span class="sr-only">{{ __('(provisional)') }}</span>
        @endif
        {{ $slot }}
    </span>
    @if ($label)
        <span class="mt-1 label-caps text-ink-muted">{{ $label }}</span>
    @endif
</span>
