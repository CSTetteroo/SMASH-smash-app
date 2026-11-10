@props([
    /*
     | One line of a player's tournament history (built by the profile page):
     | ['tournament' => Tournament, 'partner' => ?User, 'result' => 'Champion', 'detail' => ?string,
     |  'tone' => navy|sky|orange|green|smash|grey, 'icon' => ?heroicon, 'solid' => bool]
     */
    'row',
])

@php
    $tournament = $row['tournament'];
@endphp

<div {{ $attributes->class('flex items-start gap-4 px-5 py-4 sm:gap-5 sm:px-6') }} data-tournament-row>
    <x-ui.date-badge :date="$tournament->starts_at" size="sm" class="shrink-0" />

    <div class="min-w-0 flex-1">
        <p class="kicker">{{ $tournament->discipline->label() }} · {{ $tournament->format->label() }}</p>
        <a
            href="{{ route('tournaments.show', $tournament) }}"
            wire:navigate
            class="mt-1 inline-block font-serif text-h5 text-heading underline-offset-4 decoration-1 hover:underline"
        >{{ $tournament->name }}</a>

        @if ($row['partner'])
            <div class="mt-2 flex min-w-0 items-center gap-2 text-sm text-ink-soft">
                <span class="shrink-0">{{ __('with') }}</span>
                <x-smash.player :user="$row['partner']" size="xs" :meta="false" />
            </div>
        @endif

        @if ($row['detail'])
            <p class="mt-1.5 text-sm text-ink-muted">{{ $row['detail'] }}</p>
        @endif
    </div>

    <x-ui.tag
        :tone="$row['tone']"
        :variant="$row['solid'] ? 'solid' : 'outline'"
        :icon="$row['icon']"
        class="mt-0.5 shrink-0 max-sm:max-w-32"
    >{{ $row['result'] }}</x-ui.tag>
</div>
