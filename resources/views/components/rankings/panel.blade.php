@props([
    /*
     | x-rankings.panel — a small side card for the rankings pages (biggest movers, streaks …):
     | kicker + serif title in a header row, then a flush list in the default slot.
     |
     | title        Card title (rendered as an h3).
     | kicker       Small orange line above the title ("Last 30 days").
     | icon         Optional Heroicon name shown on the right of the header.
     | empty        Text shown instead of the slot when the slot is empty.
     */
    'title',
    'kicker' => null,
    'icon' => null,
    'empty' => null,
])

<x-ui.card padding="none" {{ $attributes }}>
    <div class="flex items-start justify-between gap-3 border-b border-line px-5 pt-4 pb-3.5">
        <div class="min-w-0">
            @if ($kicker)
                <p class="kicker mb-1">{{ $kicker }}</p>
            @endif
            <h3 class="font-serif text-h5 text-heading">{{ $title }}</h3>
        </div>
        @if ($icon)
            <flux:icon :icon="$icon" variant="outline" class="mt-0.5 size-6 shrink-0 text-orange-500 dark:text-orange-400" />
        @endif
    </div>

    @if (trim((string) $slot) !== '')
        {{ $slot }}
    @elseif ($empty)
        <p class="px-5 py-5 text-sm leading-relaxed text-ink-soft">{{ $empty }}</p>
    @endif
</x-ui.card>
