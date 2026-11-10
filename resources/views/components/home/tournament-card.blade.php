@props([
    // App\Models\Tournament (withCount('entries') keeps the entry count free).
    'tournament',
    // In progress: the next scheduled match of the draw (BadmintonMatch) or null.
    'next' => null,
    // Completed: the winning entry (TournamentEntry) or null.
    'champion' => null,
    // Extra line under the meta (e.g. the viewer's own status) — plain text.
    'note' => null,
    // Link text at the bottom; null = from the status ("Register now", "Follow the draw", …).
    'cta' => null,
])

{{--
    Tournament teaser card (the whole card links to the tournament): status bar + chip, discipline and
    format kicker, serif name, date / venue / registration or next round, and an entries or progress bar.
--}}
@use('App\Enums\TournamentStatus')

@php
    $t = $tournament;
    $status = $t->status;
    $entries = $t->entryCount();
    $unit = $t->isDoubles() ? __('pairs') : __('players');
    $deadline = $t->registrationDeadline();
    $open = $t->isOpenForRegistration();

    $cta ??= match (true) {
        $open => __('Register now'),
        $status === TournamentStatus::InProgress => __('Follow the draw'),
        $status === TournamentStatus::Completed => __('See the results'),
        default => __('View tournament'),
    };
@endphp

<x-ui.card :href="route('tournaments.show', $t)" :highlight="$status->tone()" padding="lg" {{ $attributes->class('h-full') }} data-home-tournament>
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <p class="kicker">{{ $t->discipline->label() }} · {{ $t->format->label() }}</p>
        <x-smash.status-badge :status="$status" size="sm" />
    </div>

    <p class="mt-3 font-serif text-h4 text-pretty text-heading group-hover/card:underline group-hover/card:decoration-1 group-hover/card:underline-offset-4">{{ $t->name }}</p>

    <div class="mt-4 space-y-1.5">
        <x-ui.meta icon="calendar-days">{{ $t->starts_at->format($t->starts_at->isCurrentYear() ? 'l j F · H:i' : 'l j F Y · H:i') }}</x-ui.meta>
        @if ($t->venue)
            <x-ui.meta icon="map-pin">{{ $t->venue }}</x-ui.meta>
        @endif

        @if ($open && $deadline)
            <x-ui.meta icon="clock">{{ __('Registration closes :date', ['date' => $deadline->format('D j M · H:i')]) }}</x-ui.meta>
        @elseif ($status === TournamentStatus::Open)
            <x-ui.meta icon="clock">{{ $t->isFull() ? __('Full — the draw is coming soon') : __('Registration closed — the draw is coming soon') }}</x-ui.meta>
        @elseif ($status === TournamentStatus::InProgress && $next)
            <x-ui.meta icon="play-circle">
                {{ __('Next up: :round', ['round' => $next->round ? $t->roundLabel($next->round, true) : __('next round')]) }}{{ $next->scheduled_at ? ' · '.$next->scheduled_at->format('D j M · H:i') : '' }}
            </x-ui.meta>
        @elseif ($status === TournamentStatus::Completed && $champion)
            <x-ui.meta icon="trophy">{{ __('Champion: :name', ['name' => $champion->name()]) }}</x-ui.meta>
        @endif
    </div>

    @if ($note)
        <p class="mt-4 text-[15px] font-medium text-ink">{{ $note }}</p>
    @endif

    <div class="mt-auto pt-6">
        @if ($status === TournamentStatus::Open && $t->max_entries)
            <x-ui.progress :value="$entries" :max="$t->max_entries" capacity size="sm" :label="__('Entries')" :value-label="$entries.' / '.$t->max_entries.' '.$unit" />
        @elseif ($status === TournamentStatus::Open)
            <p class="label-caps text-ink-muted">{{ $entries }} {{ $unit }} {{ __('registered') }}</p>
        @elseif ($status === TournamentStatus::InProgress)
            @php $played = (int) round($t->progress() * 100); @endphp
            <x-ui.progress :value="$played" :max="100" tone="orange" size="sm" :label="__('Matches played')" :value-label="$played.' %'" />
        @else
            <p class="label-caps text-ink-muted">{{ $entries }} {{ $unit }}</p>
        @endif

        <x-ui.link class="mt-5">{{ $cta }}</x-ui.link>
    </div>
</x-ui.card>
