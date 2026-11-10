@props([
    // App\Models\ClubNight, ideally loaded withRsvpCounts() so the counts cost no queries.
    'night',
    // The viewer's RSVP (App\Models\ClubNightRsvp) or null.
    'rsvp' => null,
    // 1-based waitlist place when the viewer is on the waitlist.
    'waitlistPosition' => null,
    'kicker' => null,
    // Heading level of the night's title.
    'level' => 3,
])

{{--
    A club night as a BUas event card body ("21 NOV | Open Day"): date badge, serif title, time /
    venue / places with orange meta icons, a capacity bar and the viewer's RSVP state.
    Slots: $actions (buttons under the bar), $footer (hairline + links, like BUas' "View all events").
    Put it inside a surface (the landing hero aside, a card).
--}}
@php
    $start = $night->starts_at;
    $going = $night->goingCount();
    $waitlist = $night->waitlistCount();
    $spots = $night->spotsLeft();
    $ongoing = $night->isOngoing();

    $day = match (true) {
        $ongoing => __('On now'),
        $start->isToday() => __('Tonight'),
        $start->isTomorrow() => __('Tomorrow'),
        default => $start->format('l j F'),
    };

    $places = $spots > 0
        ? trans_choice(':count place left|:count places left', $spots).' '.__('of :capacity', ['capacity' => $night->capacity])
        : ($waitlist > 0 ? __('Full · :count on the waitlist', ['count' => $waitlist]) : __('Full · waitlist open'));

    $heading = 'h'.max(2, min(6, (int) $level));
@endphp

<div {{ $attributes->class('flex min-w-0 flex-col') }} data-home-club-night>
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        @if ($kicker)
            <p class="kicker">{{ $kicker }}</p>
        @endif
        <div class="flex flex-wrap items-center gap-2">
            @if ($ongoing)
                <x-ui.live :label="__('On now')" />
            @endif
            <x-smash.status-badge :status="$night->kind" size="sm" />
        </div>
    </div>

    <div class="mt-4 flex items-start gap-5">
        <x-ui.date-badge :date="$start" weekday />
        <div class="min-w-0 flex-1 border-s border-line ps-5">
            <{{ $heading }} class="font-serif text-h4 font-normal text-pretty text-heading">
                <a href="{{ route('club-nights.show', $night) }}" wire:navigate class="underline-offset-4 decoration-1 hover:underline">{{ $night->title }}</a>
            </{{ $heading }}>
            <div class="mt-3 space-y-1.5">
                <x-ui.meta icon="clock">{{ $day }} · {{ $start->format('H:i') }} – {{ $night->ends_at->format('H:i') }}</x-ui.meta>
                <x-ui.meta icon="map-pin">{{ $night->venue }}</x-ui.meta>
                <x-ui.meta icon="user-group">{{ $places }}</x-ui.meta>
            </div>
        </div>
    </div>

    <x-ui.progress
        class="mt-6"
        :value="$going"
        :max="$night->capacity"
        capacity
        size="sm"
        :label="__('Going')"
        :value-label="$going.' / '.$night->capacity"
    />

    @if ($rsvp)
        <p class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 text-[15px] text-ink-soft">
            @if ($rsvp->isGoing())
                <x-ui.tag tone="green" variant="soft" size="sm" icon="check">{{ __("You're going") }}</x-ui.tag>
                <span>{{ __('See you on court.') }}</span>
            @else
                <x-ui.tag tone="orange" variant="soft" size="sm" icon="clock">
                    {{ $waitlistPosition ? __('Waitlist #:position', ['position' => $waitlistPosition]) : __('On the waitlist') }}
                </x-ui.tag>
                <span>{{ __('You move up automatically when a place opens.') }}</span>
            @endif
        </p>
    @endif

    @isset($actions)
        <div class="mt-6 flex flex-wrap items-center gap-3">{{ $actions }}</div>
    @endisset

    @isset($footer)
        <div class="mt-6 space-y-3 border-t border-line pt-5">{{ $footer }}</div>
    @endisset
</div>
