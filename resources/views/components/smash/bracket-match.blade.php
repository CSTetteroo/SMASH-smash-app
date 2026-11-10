@props([
    /*
     | x-smash.bracket-match — a knockout match box for a draw: two x-smash.bracket-slot rows.
     | Eager-load like the match lists (BadmintonMatch::withDetails()).
     |
     | match        App\Models\BadmintonMatch (required).
     | for          A User whose slot is highlighted.
     | games        Show per-game points (default) or only games won.
     | meta         Show the small header line (time and court, LIVE, W/O).
     | href         null = matches.show; false = not linked. The whole box is the link.
     */
    'match',
    'for' => null,
    'games' => true,
    'meta' => true,
    'href' => null,
])

@php
    $isLive = $match->isLive();
    $isFinished = $match->isFinished();
    $isBye = $match->isBye();
    $isWalkover = $match->status === \App\Enums\MatchStatus::Walkover;
    $winner = $match->winning_side;
    $target = (int) $match->points_per_game;
    $gameRows = $match->games->values();

    $slot = function (\App\Enums\Side $side) use ($match, $gameRows, $winner, $isFinished, $isLive, $isBye, $isWalkover, $for, $games): array {
        $entry = $match->entryOn($side);
        $own = $side === \App\Enums\Side::A ? 'side_a_points' : 'side_b_points';
        $opp = $side === \App\Enums\Side::A ? 'side_b_points' : 'side_a_points';

        return [
            'entry' => $entry,
            'label' => $entry === null && $isBye ? __('Bye') : __('TBD'),
            'points' => $games && ! $isBye ? $gameRows->pluck($own)->all() : [],
            'against' => $games && ! $isBye ? $gameRows->pluck($opp)->all() : [],
            'gamesWon' => match (true) {
                $isBye => null,
                $isWalkover => $winner === $side ? __('W/O') : null,
                $isFinished || $isLive => $match->gamesWon($side),
                default => null,
            },
            'won' => $winner === null ? null : $winner === $side,
            'current' => $for instanceof \App\Models\User && $entry !== null && $entry->hasPlayer($for),
        ];
    };
    $a = $slot(\App\Enums\Side::A);
    $b = $slot(\App\Enums\Side::B);

    $liveGame = $isLive && $gameRows->isNotEmpty() && ! $gameRows->last()->isComplete($target);

    if ($href === null) {
        $href = $match->getKey() !== null && ! $isBye && Route::has('matches.show') ? route('matches.show', $match) : null;
    }
    $href = $href === false ? null : $href;

    $moment = $isFinished ? ($match->played_at ?? $match->scheduled_at) : $match->scheduled_at;
    $when = collect([
        // A live match only needs its court; the LIVE badge says when.
        $isLive ? null : $moment?->format('D j M · H:i'),
        $match->court ? __('Court :n', ['n' => $match->court]) : null,
    ])->filter()->join(' · ');

    $nameA = $a['entry']?->name() ?? $a['label'];
    $nameB = $b['entry']?->name() ?? $b['label'];
@endphp

<article
    {{ $attributes->class([
        'relative min-w-0 border bg-surface',
        'border-line' => ! $isLive,
        'border-line border-t-2 border-t-smash-500' => $isLive,
        'transition-[box-shadow,border-color] duration-200 ease-brand hover:border-line-strong hover:shadow-card' => $href,
        'opacity-75' => $isBye,
    ]) }}
    data-smash-bracket-match="{{ $match->status->value }}"
>
    @if ($meta)
        <header class="flex h-6 items-center justify-between gap-2 border-b border-line bg-surface-muted px-2 text-[10px] font-semibold uppercase leading-none tracking-label text-ink-muted">
            <span class="truncate">{{ $isBye ? __('Bye') : ($when !== '' ? $when : __('To be scheduled')) }}</span>
            @if ($isLive)
                <x-ui.live />
            @elseif ($isWalkover && ! $isBye)
                <span>{{ __('Walkover') }}</span>
            @endif
        </header>
    @endif

    <x-smash.bracket-slot :entry="$a['entry']" :label="$a['label']" :points="$a['points']" :against="$a['against']" :games-won="$a['gamesWon']" :won="$a['won']" :live="$liveGame" :current="$a['current']" />
    <div class="h-px bg-line" aria-hidden="true"></div>
    <x-smash.bracket-slot :entry="$b['entry']" :label="$b['label']" :points="$b['points']" :against="$b['against']" :games-won="$b['gamesWon']" :won="$b['won']" :live="$liveGame" :current="$b['current']" />

    @if ($href)
        <a href="{{ $href }}" wire:navigate class="absolute inset-0" aria-label="{{ __('Match :a – :b', ['a' => $nameA, 'b' => $nameB]) }}"></a>
    @endif
</article>
