@props([
    'href',
    'current' => false,
])

{{-- Primary navigation item: navy label, 3px navy bar on the header divider when current. --}}
<a
    href="{{ $href }}"
    wire:navigate
    @if ($current) aria-current="page" @endif
    {{ $attributes->class([
        'group/nav relative inline-flex h-12 shrink-0 items-center whitespace-nowrap text-[15px] transition-colors lg:h-14 lg:text-base',
        'after:pointer-events-none after:absolute after:inset-x-0 after:-bottom-px after:h-[3px] after:origin-left after:transition-transform after:duration-200 after:ease-brand',
        'font-semibold text-navy-800 after:scale-x-100 after:bg-navy-800 dark:text-white dark:after:bg-navy-400' => $current,
        'font-medium text-navy-800/90 after:scale-x-0 after:bg-line-strong hover:text-navy-800 hover:after:scale-x-100 dark:text-navy-100 dark:hover:text-white' => ! $current,
    ]) }}
>
    {{ $slot }}
</a>
