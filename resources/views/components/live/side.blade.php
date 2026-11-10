@props([
    /*
     | x-live.side — one end of the umpire console: the side's players, games won, the big score
     | and who serves from where. In "rally" mode the whole half is the "point to this side"
     | button; in "serve" mode tapping it says this side serves next; in "start" mode it is a
     | radio for the first server (wire:model firstServer); "decided" and "view" are read-only.
     |
     | match      BadmintonMatch (participants.user loaded)
     | side       Side
     | state      ?App\Support\Live\LiveScore
     | position   'left' · 'right' (where it is drawn; the right half mirrors the left)
     | mode       'rally' · 'serve' · 'start' · 'decided' · 'view'
     */
    'match',
    'side',
    'state' => null,
    'position' => 'left',
    'mode' => 'view',
])

@php
    $end = $position === 'right';
    $players = $match->playersOn($side);
    $name = $match->sideLabel($side);
    $points = $state?->pointsFor($side) ?? 0;
    $won = $state?->gamesWon()[$side->value] ?? 0;
    $needed = $state?->gamesToWin() ?? \App\Support\Badminton\Scoring::gamesToWin($match->best_of);
    $serving = $mode === 'rally' && $state?->serving() === $side;
    $receiving = $mode === 'rally' && ! $serving && $state?->serving() !== null;
    $court = $serving ? $state->serviceCourt() : null;
    $matchPoint = $mode === 'rally' && $state->hasMatchPoint($side);
    $gamePoint = $mode === 'rally' && ! $matchPoint && $state->hasGamePoint($side);
    $winner = $mode === 'decided' ? $state?->winner() === $side : null;
    $key = $end ? 'L' : 'A';
    $arrow = $end ? '→' : '←';

    $tag = match ($mode) {
        'rally', 'serve' => 'button',
        'start' => 'label',
        default => 'div',
    };

    $label = match ($mode) {
        'rally' => __('Point to :name. Score :points, :status.', [
            'name' => $name,
            'points' => $points,
            'status' => $serving ? __('serving from the :court service court', ['court' => $court]) : __('receiving'),
        ]),
        'serve' => __(':name serves the next rally', ['name' => $name]),
        default => null,
    };
@endphp

<{{ $tag }}
    @if ($tag === 'button')
        type="button"
        aria-label="{{ $label }}"
        aria-keyshortcuts="{{ $key }} {{ $end ? 'ArrowRight' : 'ArrowLeft' }}"
        x-bind:disabled="busy"
        x-on:click="press('{{ $mode === 'rally' ? 'point' : 'serve' }}', '{{ $side->value }}')"
    @endif
    data-side="{{ $side->value }}"
    data-position="{{ $position }}"
    {{ $attributes->class([
        'group/side relative flex min-w-0 flex-col p-3 text-start transition-colors duration-150 ease-brand sm:p-6 lg:p-8',
        'items-end text-end' => $end,
        'cursor-pointer hover:bg-white/[0.04] active:bg-white/[0.09] focus-visible:-outline-offset-4 disabled:cursor-wait' => $tag !== 'div',
        'has-checked:bg-white/[0.06]' => $mode === 'start',
    ]) }}
>
    @if ($mode === 'start')
        <input type="radio" name="first-server" value="{{ $side->value }}" wire:model="firstServer" class="peer sr-only" />
    @endif

    {{-- Serving end: the BUas 4px highlight bar, in orange. --}}
    @if ($serving)
        <span class="absolute inset-x-0 top-0 h-1 bg-orange-400" aria-hidden="true"></span>
    @elseif ($mode === 'start')
        <span class="absolute inset-x-0 top-0 hidden h-1 bg-orange-400 group-has-checked/side:block" aria-hidden="true"></span>
    @endif

    {{-- Players --}}
    <div @class(['flex w-full min-w-0 items-center gap-3', 'flex-row-reverse' => $end])>
        <span @class(['flex shrink-0 max-sm:hidden', 'flex-row-reverse' => $end])>
            @foreach ($players as $player)
                <span @class(['rounded-full ring-2 ring-navy-900', '-ms-2 first:ms-0' => ! $end, '-me-2 first:me-0' => $end]) wire:key="av-{{ $side->value }}-{{ $player->id }}">
                    <x-smash.avatar :user="$player" size="sm" />
                </span>
            @endforeach
        </span>
        <span class="min-w-0 flex-1">
            @forelse ($players as $player)
                <span class="block truncate text-[15px] font-semibold leading-snug text-white sm:text-lg" wire:key="nm-{{ $side->value }}-{{ $player->id }}">{{ $player->name }}</span>
            @empty
                <span class="block truncate text-[15px] font-semibold italic text-ink-muted sm:text-lg">{{ $name }}</span>
            @endforelse
        </span>
    </div>

    {{-- Games won --}}
    <div @class(['mt-3 flex items-center gap-2', 'flex-row-reverse' => $end])>
        <span class="label-caps text-[11px] text-ink-muted">{{ __('Games') }}</span>
        <span @class(['flex gap-1', 'flex-row-reverse' => $end])>
            @for ($i = 1; $i <= $needed; $i++)
                <span @class([
                    'size-3 sm:size-3.5',
                    'bg-orange-400' => $i <= $won,
                    'border border-white/40' => $i > $won,
                ])></span>
            @endfor
        </span>
        <span class="sr-only">{{ trans_choice(':count game won|:count games won', $won) }}</span>
    </div>

    {{-- The score --}}
    <span class="flex w-full flex-1 items-center justify-center py-4">
        <span @class([
            'num font-extrabold leading-[0.8] tracking-[-0.02em] tabular-nums',
            'text-[6.75rem] sm:text-[9.5rem] lg:text-[12.5rem]',
            'text-white' => $winner !== false,
            'text-white/40' => $winner === false,
        ]) data-score>{{ $points }}</span>
    </span>

    {{-- Status line: serving / receiving, game point, the tap hint --}}
    <span @class(['flex min-h-[5.5rem] w-full flex-col justify-end gap-2', 'items-end' => $end, 'items-start' => ! $end])>
        @if ($matchPoint)
            <span class="inline-flex h-7 items-center bg-smash-600 px-2.5 text-[11px] font-semibold uppercase leading-none tracking-label text-white">{{ __('Match point') }}</span>
        @elseif ($gamePoint)
            <span class="inline-flex h-7 items-center border border-orange-400 px-2.5 text-[11px] font-semibold uppercase leading-none tracking-label text-orange-400">{{ __('Game point') }}</span>
        @endif

        @if ($serving)
            <span @class(['inline-flex h-7 items-center gap-1.5 whitespace-nowrap bg-orange-400 px-2.5 text-[11px] font-semibold uppercase leading-none tracking-label text-navy-950', 'flex-row-reverse' => $end])>
                <x-ui.shuttle class="size-4 text-navy-950" cork="#D92E1A" />
                <span>{{ __('Serving') }}<span class="max-[379px]:hidden"> · {{ $court === 'right' ? __('Right') : __('Left') }}</span><span class="max-sm:hidden"> {{ __('court') }}</span></span>
            </span>
        @elseif ($receiving)
            <span class="inline-flex h-7 items-center text-[11px] font-semibold uppercase leading-none tracking-label text-ink-muted">{{ __('Receiving') }}</span>
        @elseif ($winner === true)
            <span class="inline-flex h-7 items-center bg-orange-400 px-2.5 text-[11px] font-semibold uppercase leading-none tracking-label text-navy-950">{{ __('Winner') }}</span>
        @endif

        @if ($mode === 'rally')
            <span @class(['-mx-1.5 flex items-center gap-2 bg-surface px-1.5 text-[11px] font-semibold uppercase tracking-label text-white/55 transition-colors group-hover/side:text-white', 'flex-row-reverse' => $end])>
                <flux:icon.plus variant="micro" class="size-3.5" />
                {{ __('Point') }}
                <span class="max-lg:hidden">· <kbd class="font-sans">{{ $key }}</kbd> {{ __('or') }} <kbd class="font-sans">{{ $arrow }}</kbd></span>
            </span>
        @elseif ($mode === 'serve')
            <span class="inline-flex h-7 items-center gap-1.5 border border-white/40 px-2.5 text-[11px] font-semibold uppercase leading-none tracking-label text-white group-hover/side:border-orange-400 group-hover/side:text-orange-400">
                {{ __('Serves next') }}
            </span>
        @elseif ($mode === 'start')
            <span class="inline-flex h-7 items-center gap-1.5 border border-white/40 px-2.5 text-[11px] font-semibold uppercase leading-none tracking-label text-white group-hover/side:border-orange-400 group-has-checked/side:border-orange-400 group-has-checked/side:bg-orange-400 group-has-checked/side:text-navy-950 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-orange-400">
                <span class="group-has-checked/side:hidden">{{ __('Serves first?') }}</span>
                <span class="hidden group-has-checked/side:inline">{{ __('Serves first') }}</span>
            </span>
        @endif
    </span>
</{{ $tag }}>
