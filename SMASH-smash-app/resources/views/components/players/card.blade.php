@props([
    // App\Models\User (id, name, role, programme, level, handedness, is_active, ratings, played, created_at).
    'player',
    // Rating column to emphasise when the list is sorted by it: 'singles' · 'doubles' · null.
    'emphasis' => null,
])

{{--
    Player directory card: avatar, name, programme chip, level, ratings in both disciplines and rated
    matches played. The whole card links to the profile (nothing clickable inside).
--}}
@php
    $isNew = $player->created_at !== null && $player->created_at->greaterThan(now()->subDays(30));
    $played = $player->played(\App\Enums\Discipline::Singles) + $player->played(\App\Enums\Discipline::Doubles);
    $facts = array_filter([$player->level?->label(), $player->handedness?->label()]);

    $columns = [
        ['discipline' => \App\Enums\Discipline::Singles, 'label' => __('Singles')],
        ['discipline' => \App\Enums\Discipline::Doubles, 'label' => __('Doubles')],
    ];
@endphp

<x-ui.card
    :href="route('players.show', $player)"
    padding="none"
    {{ $attributes->class(['h-full', 'opacity-75' => ! $player->is_active]) }}
    data-player-card
>
    <div class="flex items-start gap-4 p-5">
        <x-smash.avatar :user="$player" size="lg" ring="auto" />
        <div class="min-w-0 flex-1">
            <p class="truncate text-[17px] font-semibold leading-snug text-ink underline-offset-[3px] decoration-1 group-hover/card:underline">{{ $player->name }}</p>
            @if ($facts !== [])
                <p class="mt-0.5 truncate text-sm text-ink-muted">{{ implode(' · ', $facts) }}</p>
            @endif
            <div class="mt-3 flex flex-wrap gap-1.5">
                @if ($player->programme)
                    <x-ui.tag tone="navy" size="sm">{{ $player->programme->label() }}</x-ui.tag>
                @endif
                @if ($player->isAdmin())
                    <x-ui.tag tone="navy" variant="solid" size="sm">{{ __('Committee') }}</x-ui.tag>
                @endif
                @if ($isNew)
                    <x-ui.tag tone="green" variant="soft" size="sm" data-test="new-marker">{{ __('New') }}</x-ui.tag>
                @endif
                @unless ($player->is_active)
                    <x-ui.tag tone="grey" size="sm">{{ __('Inactive') }}</x-ui.tag>
                @endunless
            </div>
        </div>
    </div>

    <dl class="mt-auto grid grid-cols-3 border-t border-line">
        @foreach ($columns as $column)
            @php($hasPlayed = $player->played($column['discipline']) > 0)
            <div @class(['min-w-0 px-5 py-3.5', 'border-s border-line' => ! $loop->first, 'bg-surface-muted' => $emphasis === $column['discipline']->value])>
                <dt class="label-caps text-ink-muted">{{ $column['label'] }}</dt>
                <dd class="mt-1.5">
                    <x-smash.rating
                        :value="$hasPlayed ? $player->ratingExact($column['discipline']) : null"
                        :provisional="$hasPlayed && $player->isProvisional($column['discipline'])"
                        size="sm"
                    />
                </dd>
            </div>
        @endforeach
        <div class="min-w-0 border-s border-line px-5 py-3.5">
            <dt class="label-caps text-ink-muted">{{ __('Matches') }}</dt>
            <dd class="num mt-1.5 text-[17px] font-bold leading-none text-ink">{{ $played }}</dd>
        </div>
    </dl>
</x-ui.card>
