@props([
    /*
     | x-club-nights.card — an upcoming club night in a grid: kind-coloured top bar, date badge,
     | time and venue, kind and level tags, capacity bar, who's going and the RSVP control.
     | The title links to the night; the card itself is not a link (it holds buttons).
     |
     | night    ClubNight with rsvps.user loaded (oldest RSVP first) — renders without queries.
     | heading  Heading level for the title (h3 under a section, h4 under a week heading).
     */
    'night',
    'heading' => 4,
])

@php
    $going = $night->rsvps->filter(fn ($rsvp) => $rsvp->isGoing())->values();
    $goingCount = $night->goingCount();
    $waiting = $night->waitlistCount();
    $full = $night->isFull();
    $ongoing = $night->isOngoing();
    $today = $night->starts_at->isToday();
    $url = route('club-nights.show', $night);
    $tag = 'h'.max(2, min(6, (int) $heading));
@endphp

<x-ui.card as="article" :highlight="$night->kind->tone()" padding="none" {{ $attributes->class('h-full') }} data-club-night="{{ $night->id }}">
    <div class="flex flex-1 flex-col gap-5 p-5 sm:p-6">
        <div class="flex items-start gap-4">
            <x-ui.date-badge :date="$night->starts_at" weekday />
            <div class="min-w-0 flex-1">
                <{{ $tag }} class="font-serif text-h4 leading-tight text-heading">
                    <a href="{{ $url }}" wire:navigate class="decoration-1 underline-offset-4 hover:underline">{{ $night->title }}</a>
                </{{ $tag }}>
                <div class="mt-2.5 space-y-1">
                    <x-ui.meta icon="clock">{{ $night->starts_at->format('H:i') }} – {{ $night->ends_at->format('H:i') }}</x-ui.meta>
                    <x-ui.meta icon="map-pin">{{ $night->venue }} · {{ trans_choice(':count court|:count courts', $night->courts) }}</x-ui.meta>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($ongoing)
                <x-ui.live variant="solid" :label="__('On now')" />
            @elseif ($today)
                <x-ui.tag tone="navy" variant="solid" size="md">{{ __('Tonight') }}</x-ui.tag>
            @endif
            <x-smash.status-badge :status="$night->kind" />
            <x-club-nights.level :level="$night->level" />
            @if ($full)
                <x-ui.tag tone="smash">{{ __('Full') }}</x-ui.tag>
            @endif
        </div>

        <div class="space-y-4">
            <x-ui.progress
                :value="$goingCount"
                :max="$night->capacity"
                capacity
                size="sm"
                :label="__('Places taken')"
                :value-label="$full ? ($waiting > 0 ? __('Full · :count waiting', ['count' => $waiting]) : __('Full')) : $goingCount.' / '.$night->capacity"
            />
            <x-club-nights.attendees :users="$going" :total="$goingCount" size="xs" :max="7" />
        </div>
    </div>

    <div class="flex min-h-16 flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-3 sm:px-6">
        <x-club-nights.rsvp :night="$night" />
        <x-ui.link :href="$url" class="ms-auto">{{ __('Details') }}<span class="sr-only"> {{ __('of :title', ['title' => $night->title]) }}</span></x-ui.link>
    </div>
</x-ui.card>
