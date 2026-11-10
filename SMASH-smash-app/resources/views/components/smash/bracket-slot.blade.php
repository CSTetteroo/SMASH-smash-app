@props([
    /*
     | x-smash.bracket-slot — one side of a bracket match: seed, short name, game scores, games won.
     | Usually rendered by x-smash.bracket-match; usable on its own for custom draws.
     |
     | entry        ?TournamentEntry (null = an open slot).
     | label        Text for an open slot: 'TBD' (default) or 'Bye'.
     | seed         Override the seed (default: the entry's).
     | points       This side's points per game, e.g. [21, 19, 21].
     | against      The other side's points per game (to bold the games this side won).
     | gamesWon     Games won (int) or a marker such as 'W/O'. null = nothing.
     | won          true = winner (bold, navy games box) · false = loser (dimmed) · null = undecided.
     | live         The last game is being played now.
     | current      Highlight ("you").
     | short        Family names ("Jansen / de Vries"); false = full names.
     */
    'entry' => null,
    'label' => null,
    'seed' => null,
    'points' => [],
    'against' => [],
    'gamesWon' => null,
    'won' => null,
    'live' => false,
    'current' => false,
    'short' => true,
])

@php
    $seed ??= $entry?->seed;
    $name = $entry ? ($short ? $entry->shortName() : $entry->name()) : ($label ?? __('TBD'));
    $fullName = $entry?->name() ?? $name;
    $points = array_values((array) $points);
    $against = array_values((array) $against);
    $last = count($points) - 1;
@endphp

<div
    {{ $attributes->class([
        'flex h-9 min-w-0 items-center gap-2 ps-2 pe-1.5',
        'bg-navy-50 shadow-[inset_3px_0_0_var(--color-navy-800)] dark:bg-navy-400/8 dark:shadow-[inset_3px_0_0_var(--color-navy-400)]' => $current,
        'shadow-[inset_3px_0_0_var(--color-heading)]' => $won === true && ! $current,
    ]) }}
    data-smash-bracket-slot
    @if ($won === true) data-winner @endif
>
    <span class="num w-4 shrink-0 text-end text-xs font-semibold text-ink-muted" @if ($seed === null) aria-hidden="true" @endif>
        @if ($seed !== null)<span class="sr-only">{{ __('Seed') }} </span>{{ $seed }}@endif
    </span>

    <span @class([
        'min-w-0 flex-1 truncate text-[13px] leading-tight',
        'italic text-ink-muted' => $entry === null,
        'font-bold text-ink' => $entry !== null && $won === true,
        'text-ink-soft' => $entry !== null && $won === false,
        'font-medium text-ink' => $entry !== null && $won === null,
    ]) title="{{ $fullName }}">{{ $name }}</span>

    @foreach ($points as $i => $p)
        @php
            $other = $against[$i] ?? null;
            $isLive = $live && $i === $last;
            $wonGame = ! $isLive && $other !== null && $p > $other;
        @endphp
        <span @class([
            'num w-5 shrink-0 text-center text-[13px] leading-none',
            'font-bold text-live' => $isLive,
            'font-extrabold text-ink' => $wonGame,
            'font-medium text-ink-muted' => ! $wonGame && ! $isLive,
        ]) aria-hidden="true">{{ $p }}</span>
    @endforeach

    @if ($gamesWon !== null)
        <span @class([
            'num inline-flex h-6 min-w-6 shrink-0 items-center justify-center px-1 text-[15px] font-extrabold leading-none',
            'bg-heading text-canvas' => $won === true,
            'text-ink-muted' => $won !== true,
        ])>{{ $gamesWon }}</span>
    @endif
</div>
