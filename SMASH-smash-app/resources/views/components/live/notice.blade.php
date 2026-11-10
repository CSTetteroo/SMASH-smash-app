@props([
    /*
     | x-live.notice — an umpire call on the navy console (interval, change of ends, game won,
     | setting at 20–20, golden point). Orange bar + kicker title, white text.
     |
     | title   Kicker line ("Interval")
     | tone    orange (calls) · smash (golden point, match point) · sky (information)
     | icon    Heroicon name
     | Slots:  default (the explanation) · actions (buttons on the right)
     */
    'title' => null,
    'tone' => 'orange',
    'icon' => null,
])

<div
    role="status"
    {{ $attributes->class([
        'flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border-s-4 bg-white/[0.07] px-4 py-3 sm:px-5',
        'border-orange-400' => $tone === 'orange',
        'border-smash-500' => $tone === 'smash',
        'border-navy-400' => $tone === 'sky',
    ]) }}
>
    <div class="flex min-w-0 items-start gap-3">
        @if ($icon)
            <flux:icon :icon="$icon" variant="mini" @class([
                'mt-0.5 size-5 shrink-0',
                'text-orange-400' => $tone === 'orange',
                'text-smash-400' => $tone === 'smash',
                'text-navy-300' => $tone === 'sky',
            ]) />
        @endif
        <div class="min-w-0">
            @if ($title)
                <p @class([
                    'text-[11px] font-semibold uppercase leading-normal tracking-caps',
                    'text-orange-400' => $tone === 'orange',
                    'text-smash-400' => $tone === 'smash',
                    'text-navy-300' => $tone === 'sky',
                ])>{{ $title }}</p>
            @endif
            <p class="text-[15px] leading-snug text-white">{{ $slot }}</p>
        </div>
    </div>

    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
