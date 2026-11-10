@props([
    // Link target. Pass null to render a plain (non-link) lockup.
    'href' => null,
    // auto: dark wordmark, white in dark mode · light: always dark wordmark · inverse: white wordmark (navy/dark backgrounds)
    'variant' => 'auto',
    // sm (mobile header) · md (desktop header) · lg (auth panel, footer) · xl (hero moments)
    'size' => 'md',
    // Show the red "BUAS BADMINTON" tag above the wordmark.
    'tag' => true,
])

@php
    // Sizes are explicit pixels so the tag text never drops below a legible size.
    $sizes = [
        'sm' => ['mark' => 'h-[42px]', 'gap' => 'gap-2', 'stack' => 'gap-[3px]', 'tag' => 'text-[9.5px] px-[6px] py-[2px]', 'word' => 'text-[30px]', 'bar' => 'h-[3px]'],
        'md' => ['mark' => 'h-[58px]', 'gap' => 'gap-2.5', 'stack' => 'gap-[4px]', 'tag' => 'text-[11px] px-[7px] py-[2.5px]', 'word' => 'text-[42px]', 'bar' => 'h-[4px]'],
        'lg' => ['mark' => 'h-[84px]', 'gap' => 'gap-3.5', 'stack' => 'gap-[6px]', 'tag' => 'text-[15px] px-[10px] py-[3px]', 'word' => 'text-[62px]', 'bar' => 'h-[6px]'],
        'xl' => ['mark' => 'h-[88px] sm:h-[124px]', 'gap' => 'gap-3.5 sm:gap-5', 'stack' => 'gap-[6px] sm:gap-[8px]', 'tag' => 'text-[15px] px-[10px] py-[3px] sm:text-[21px] sm:px-[14px] sm:py-[4px]', 'word' => 'text-[66px] sm:text-[94px]', 'bar' => 'h-[6px] sm:h-[9px]'],
    ];
    $s = $sizes[$size] ?? $sizes['md'];

    $word = match ($variant) {
        'inverse' => 'text-white',
        'light' => 'text-smash-black',
        default => 'text-smash-black dark:text-white',
    };

    $label = __('SMASH — BUas Badminton Club');
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        wire:navigate
        aria-label="{{ __(':name, home', ['name' => $label]) }}"
        {{ $attributes->class(['group/logo inline-flex shrink-0 items-center', $s['gap']]) }}
    >
@else
    <span role="img" aria-label="{{ $label }}" {{ $attributes->class(['inline-flex shrink-0 items-center', $s['gap']]) }}>
@endif
        <x-app-logo-icon :simple="$size === 'sm'" class="{{ $s['mark'] }} w-auto" />

        {{-- The stack carries the wordmark size so the bar's em offsets scale with it. --}}
        <span class="flex flex-col items-start {{ $s['stack'] }} {{ $s['word'] }}" aria-hidden="true">
            @if ($tag)
                <span class="relative isolate inline-flex font-display font-bold uppercase leading-none tracking-[0.035em] text-white {{ $s['tag'] }}">
                    <span class="absolute inset-0 -z-10 -skew-x-[18deg] bg-smash-500"></span>
                    BUas Badminton
                </span>
            @endif
            <span class="block font-display font-black uppercase leading-[0.74] tracking-[0.01em] [text-box:trim-both_cap_alphabetic] {{ $word }}">SMASH</span>
            <span class="block w-[calc(100%+0.2em)] -translate-x-[0.08em] -skew-x-[18deg] bg-smash-500 {{ $s['bar'] }}"></span>
        </span>
@if ($href)
    </a>
@else
    </span>
@endif
