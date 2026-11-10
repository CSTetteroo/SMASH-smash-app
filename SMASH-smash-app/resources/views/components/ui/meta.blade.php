@props([
    // Heroicon name (clock, map-pin, calendar-days, user-group …).
    'icon' => null,
    // as: p · li · span
    'as' => 'p',
])

{{-- Meta line with an orange icon, like the time / location lines on BUas event cards. --}}
<{{ $as }} {{ $attributes->class('flex min-w-0 items-start gap-2 text-[15px] leading-snug text-ink-soft') }}>
    @if ($icon)
        <flux:icon :icon="$icon" variant="mini" class="mt-px size-[18px] shrink-0 text-orange-500 dark:text-orange-400" />
    @endif
    <span class="min-w-0">{{ $slot }}</span>
</{{ $as }}>
