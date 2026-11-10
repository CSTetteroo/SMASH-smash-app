@props([
    /*
     | x-smash.games — game-by-game scores, each game's winner in bold. The canonical game-score
     | component (x-ui.game-scores renders this one).
     |
     | match        BadmintonMatch: games, target points and live state come from it.
     | games        Or: MatchGame models, [[21, 17], …] or [['a' => 21, 'b' => 17], …] (side A first).
     | side         Read from this side's perspective (own score first): Side enum, 'a' or 'b'.
     | live         The last game is still being played (automatic for an in-progress match).
     | target       Points per game (default: the match's, else 21) to tell a finished game.
     | layout       inline ("21–17 · 19–21", default) · boxes (one box per game, own score on top)
     | size         sm 15px · md 19px · lg 26px
     | separator    Separator between inline games (default "·"; '' for none).
     | empty        Text when there are no games yet (default: nothing is rendered).
     */
    'match' => null,
    'games' => null,
    'side' => null,
    'live' => null,
    'target' => null,
    'layout' => 'inline',
    'size' => 'md',
    'separator' => '·',
    'empty' => null,
])

@php
    $isMatch = $match instanceof \App\Models\BadmintonMatch;
    $games ??= $isMatch ? $match->games : [];
    $target = (int) ($target ?? ($isMatch ? $match->points_per_game : 21));
    $live ??= $isMatch && $match->isLive();

    $side = $side instanceof \App\Enums\Side ? $side : (\App\Enums\Side::tryFrom((string) $side) ?? \App\Enums\Side::A);

    $rows = collect($games)->values()->map(function ($game) use ($side, $target): array {
        if ($game instanceof \App\Models\MatchGame) {
            $a = $game->side_a_points;
            $b = $game->side_b_points;
        } elseif (is_array($game)) {
            $a = (int) ($game['a'] ?? $game['side_a_points'] ?? $game[0] ?? 0);
            $b = (int) ($game['b'] ?? $game['side_b_points'] ?? $game[1] ?? 0);
        } else {
            $a = (int) ($game->side_a_points ?? $game->a ?? 0);
            $b = (int) ($game->side_b_points ?? $game->b ?? 0);
        }

        // Same rule as Scoring::isCompleteGame (target, two clear points, golden point at the cap).
        $complete = \App\Support\Badminton\Scoring::isCompleteGame($a, $b, $target);

        return $side === \App\Enums\Side::B
            ? ['own' => $b, 'opp' => $a, 'complete' => $complete]
            : ['own' => $a, 'opp' => $b, 'complete' => $complete];
    });

    $lastIndex = $rows->count() - 1;

    // Per game: who is bold, and whether it is the game being played now.
    $rows = $rows->map(function (array $game, int $i) use ($live, $lastIndex): array {
        $inProgress = $live && $i === $lastIndex && ! $game['complete'];

        return $game + [
            'inProgress' => $inProgress,
            'ownWon' => ! $inProgress && $game['own'] > $game['opp'],
            'oppWon' => ! $inProgress && $game['opp'] > $game['own'],
        ];
    });

    $text = match ($size) {
        'sm' => 'text-[15px]',
        'lg' => 'text-[26px]',
        default => 'text-[19px]',
    };

    $tone = fn (array $game, bool $own): string => match (true) {
        $game['inProgress'] => 'font-bold text-live',
        $own ? $game['ownWon'] : $game['oppWon'] => 'font-extrabold text-ink',
        $own ? $game['oppWon'] : $game['ownWon'] => 'font-medium text-ink-muted',
        default => 'font-semibold text-ink',
    };
@endphp

@if ($rows->isNotEmpty() && $layout === 'boxes')
    <ol {{ $attributes->class('flex items-stretch gap-1') }} role="list" aria-label="{{ __('Game scores') }}" data-smash-games="boxes">
        @foreach ($rows as $i => $game)
            <li @class([
                'flex min-w-9 flex-col items-center border bg-surface px-1.5 py-1 leading-none',
                'border-line' => ! $game['inProgress'],
                'border-line border-t-2 border-t-smash-500' => $game['inProgress'],
            ])>
                <span class="sr-only">{{ __('Game :n:', ['n' => $i + 1]) }} </span>
                <span class="num {{ $text }} {{ $tone($game, true) }}">{{ $game['own'] }}</span>
                <span class="sr-only">–</span>
                <span class="num mt-1 {{ $text }} {{ $tone($game, false) }}">{{ $game['opp'] }}</span>
                @if ($game['inProgress'])
                    <span class="sr-only">{{ __('(in progress)') }}</span>
                @endif
            </li>
        @endforeach
    </ol>
@elseif ($rows->isNotEmpty())
    <ol {{ $attributes->class(['num inline-flex flex-wrap items-baseline gap-x-2 gap-y-1 leading-none', $text]) }} role="list" aria-label="{{ __('Game scores') }}" data-smash-games>
        @foreach ($rows as $i => $game)
            <li class="inline-flex items-baseline whitespace-nowrap">
                @if ($separator !== '' && $i > 0)
                    <span class="me-2 font-medium text-line-strong" aria-hidden="true">{{ $separator }}</span>
                @endif
                <span class="sr-only">{{ __('Game :n:', ['n' => $i + 1]) }} </span>
                @if ($game['inProgress'])
                    <span class="me-1 inline-block size-1.5 -translate-y-[0.3em] rounded-full bg-smash-500 animate-live" aria-hidden="true"></span>
                @endif
                <span class="{{ $tone($game, true) }}">{{ $game['own'] }}</span><span class="px-px font-medium text-ink-muted">–</span><span class="{{ $tone($game, false) }}">{{ $game['opp'] }}</span>
                @if ($game['inProgress'])
                    <span class="sr-only">{{ __('(in progress)') }}</span>
                @endif
            </li>
        @endforeach
    </ol>
@elseif ($empty)
    <span {{ $attributes->class('text-sm text-ink-muted') }} data-smash-games>{{ $empty }}</span>
@endif
