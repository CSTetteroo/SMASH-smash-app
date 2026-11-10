@props([
    /*
     | x-smash.match-card — the canonical match list item (singles and doubles, every status).
     | Eager-load with BadmintonMatch::withDetails() (participants.user, games, tournament, entries).
     |
     | match        App\Models\BadmintonMatch (required).
     | variant      default (scoreboard card with footer) · compact (dense, no avatars or footer)
     | for          A User whose side is highlighted, with a Won / Lost chip once finished.
     | probability  Side A's win chance (0–1) or a MatchPrediction, shown on scheduled matches
     |              whose sides are both known (instead of the start time).
     | ratings      Show ratings next to names (pre-match once rated, current while upcoming)
     |              and rating changes. Default true.
     | href         null = matches.show; false = not linked; or a URL. The whole card is clickable,
     |              while player and tournament links inside stay separately clickable.
     | avatars      Show avatars (default: on for default, off for compact).
     | footer       Show the footer row (default variant).
     | highlight    Tone for the 4px top bar (live matches get SMASH red automatically).
     */
    'match',
    'variant' => 'default',
    'for' => null,
    'probability' => null,
    'ratings' => true,
    'href' => null,
    'avatars' => null,
    'footer' => true,
    'highlight' => null,
])

@php
    $compact = $variant === 'compact';
    $avatars ??= ! $compact;

    $status = $match->status;
    $isLive = $status === \App\Enums\MatchStatus::InProgress;
    $isCompleted = $status === \App\Enums\MatchStatus::Completed;
    $isWalkover = $status === \App\Enums\MatchStatus::Walkover;
    $isCancelled = $status === \App\Enums\MatchStatus::Cancelled;
    $isScheduled = $status === \App\Enums\MatchStatus::Scheduled;
    $isFinished = $match->isFinished();
    $isBye = $match->isBye();
    $discipline = $match->discipline;
    $winner = $match->winning_side;
    $target = (int) $match->points_per_game;

    // ---- Games (side A first) -----------------------------------------------------------
    $games = $match->games->values();
    $lastGame = $games->count() - 1;
    $cells = $games->map(fn ($game, $i) => [
        'a' => (int) $game->side_a_points,
        'b' => (int) $game->side_b_points,
        'live' => $isLive && $i === $lastGame && ! $game->isComplete($target),
    ])->all();

    // ---- Sides --------------------------------------------------------------------------
    $sides = [];
    foreach (\App\Enums\Side::cases() as $side) {
        $entry = $match->tournament_id !== null ? $match->entryOn($side) : null;
        $players = $entry !== null ? $entry->members() : $match->playersOn($side);

        $lines = $players->map(function ($user) use ($match, $isFinished, $isCompleted, $isCancelled, $discipline, $ratings): array {
            $participant = $match->participantFor($user);
            $before = $participant?->rating_before;
            $upcoming = ! $isFinished && ! $isCancelled;

            return [
                'user' => $user,
                'before' => $before,
                // Pre-match rating once rated; the current rating while the match is still to come.
                'rating' => $ratings ? ($before ?? ($upcoming ? $user->ratingExact($discipline) : null)) : null,
                'provisional' => $upcoming && $before === null && $user->isProvisional($discipline),
                'delta' => $ratings && $isCompleted ? $participant?->ratingChange() : null,
            ];
        })->values();

        // "Sam de Vries & Lotte Jansen", the entry's name, "Bye" or "TBD" (BadmintonMatch::sideLabel).
        $label = $match->sideLabel($side);

        $sides[$side->value] = [
            'side' => $side,
            'lines' => $lines,
            'placeholder' => $lines->isEmpty() ? $label : null,
            'team' => $lines->isNotEmpty() && $lines->every(fn ($line) => $line['before'] !== null)
                ? (float) $lines->avg('before')
                : null,
            'won' => $winner === null ? null : $winner === $side,
            'games' => $isFinished || $isLive ? $match->gamesWon($side) : null,
            'you' => $for instanceof \App\Models\User && $players->contains(fn ($user) => $user->is($for)),
            'label' => $label,
        ];
    }

    // ---- Upset: the winner was the underdog by at least smash.upset_threshold ---------------
    $upsetBy = null;
    if ($isCompleted && $winner !== null) {
        $winnerTeam = $sides[$winner->value]['team'];
        $loserTeam = $sides[$winner->opposite()->value]['team'];
        if ($winnerTeam !== null && $loserTeam !== null && $loserTeam - $winnerTeam >= (float) config('smash.upset_threshold', 100)) {
            $upsetBy = (int) round($loserTeam - $winnerTeam);
        }
    }

    // ---- Perspective ("for") -------------------------------------------------------------
    $forSide = $for instanceof \App\Models\User ? $match->sideOf($for) : null;
    $forResult = $forSide !== null && $isFinished && $winner !== null && ! $isBye ? $winner === $forSide : null;

    // ---- Win probability (scheduled matches between known sides only) ---------------------
    $probabilityA = match (true) {
        $sides['a']['placeholder'] !== null || $sides['b']['placeholder'] !== null => null,
        is_object($probability) && isset($probability->probabilityA) => (float) $probability->probabilityA,
        is_numeric($probability) => (float) $probability,
        default => null,
    };
    if ($probabilityA !== null) {
        $percentA = max(1, min(99, (int) round($probabilityA * 100)));
        $percent = ['a' => $percentA, 'b' => 100 - $percentA];
    }

    // ---- When and where ------------------------------------------------------------------
    $moment = $isFinished
        ? ($match->played_at ?? $match->scheduled_at)
        : ($match->scheduled_at ?? $match->played_at);

    $dayLabel = match (true) {
        $moment === null => __('Date to be confirmed'),
        $moment->isToday() => __('Today'),
        $moment->isTomorrow() => __('Tomorrow'),
        $moment->isYesterday() => __('Yesterday'),
        default => $moment->format($moment->isCurrentYear() ? 'D j M' : 'D j M Y'),
    };

    // Right-hand column: scores · walkover · probability · time (fixture) · none.
    $mode = match (true) {
        $isCancelled || $isBye => 'none',
        $isWalkover => 'walkover',
        $isScheduled && $probabilityA !== null => 'probability',
        $isScheduled => 'time',
        default => 'scores',
    };

    // In fixture ("time") mode the time and court sit in the right-hand column instead.
    $when = collect([
        $mode === 'time' && $moment !== null && ! $moment->isToday() && ! $moment->isTomorrow()
            ? $moment->format($moment->isCurrentYear() ? 'l j F' : 'l j F Y')
            : $dayLabel,
        $mode === 'time' || $isLive ? null : $moment?->format('H:i'),
        $match->court && $mode !== 'time' ? __('Court :n', ['n' => $match->court]) : null,
    ])->filter()->join(' · ');

    $tournament = $match->tournament_id !== null ? $match->tournament : null;
    $tournamentHref = $tournament?->slug && Route::has('tournaments.show') ? route('tournaments.show', $tournament) : null;
    $round = $match->roundLabel();

    $notes = collect([
        $match->venue,
        $match->best_of !== 3 || $target !== 21 ? __('Best of :n · to :p', ['n' => $match->best_of, 'p' => $target]) : null,
    ])->filter()->join(' · ');

    // ---- Link (byes are not real matches, so they are not linked unless asked) -----------
    if ($href === null) {
        $href = $match->getKey() !== null && ! $isBye && Route::has('matches.show') ? route('matches.show', $match) : null;
    }
    $href = $href === false ? null : $href;

    $linkText = match (true) {
        $isLive => __('Follow live'),
        $isFinished => __('Match report'),
        $isCancelled => __('Details'),
        default => __('Preview'),
    };

    // ---- Spoken summary (the visual scoreboard is aria-hidden) ---------------------------
    $gameList = function (?\App\Enums\Side $first) use ($cells): string {
        return collect($cells)->map(fn ($c) => $first === \App\Enums\Side::B ? $c['b'].'–'.$c['a'] : $c['a'].'–'.$c['b'])->join(', ');
    };
    $nameA = $sides['a']['label'];
    $nameB = $sides['b']['label'];
    $summary = match (true) {
        $isBye => __(':name advances with a bye.', ['name' => $winner === \App\Enums\Side::B ? $nameB : $nameA]),
        $isWalkover && $winner !== null => __(':w won by walkover against :l.', ['w' => $sides[$winner->value]['label'], 'l' => $sides[$winner->opposite()->value]['label']]),
        $isCompleted && $winner !== null => __(':w beat :l :score (:games).', [
            'w' => $sides[$winner->value]['label'],
            'l' => $sides[$winner->opposite()->value]['label'],
            'score' => $sides[$winner->value]['games'].'–'.$sides[$winner->opposite()->value]['games'],
            'games' => $gameList($winner),
        ]),
        $isLive => __('Live: :a against :b, :ga–:gb in games', ['a' => $nameA, 'b' => $nameB, 'ga' => $sides['a']['games'], 'gb' => $sides['b']['games']])
            .($cells ? ' ('.$gameList(\App\Enums\Side::A).').' : '.'),
        $isCancelled => __(':a against :b, cancelled.', ['a' => $nameA, 'b' => $nameB]),
        default => __(':a against :b, :when.', ['a' => $nameA, 'b' => $nameB, 'when' => $moment ? $moment->format('l j F, H:i') : __('date to be confirmed')]),
    };

    $padX = $compact ? 'px-4' : 'px-5';
    $nameSize = $compact ? 'text-sm' : 'text-[15px]';
    $cellClass = $compact ? 'w-5 text-[15px]' : 'w-6 text-[17px]';
    $boxClass = $compact ? 'size-6 text-[15px]' : 'size-7 text-[17px]';
@endphp

<x-ui.card
    as="article"
    padding="none"
    :highlight="$highlight ?? ($isLive ? 'smash' : null)"
    {{ $attributes->class([
        '[&_[data-smash-player-link]]:relative [&_[data-smash-player-link]]:z-10 [&_[data-smash-raise]]:relative [&_[data-smash-raise]]:z-10',
        'transition-[box-shadow,border-color] duration-200 ease-brand hover:border-line-strong hover:shadow-raised' => $href,
    ]) }}
    data-smash-match-card="{{ $status->value }}"
>
    <p class="sr-only">{{ $summary }}</p>

    {{-- Header: context, when / where, badges --}}
    <header @class([
        'flex items-start justify-between gap-x-4 gap-y-2',
        $compact ? 'px-4 pt-3' : 'flex-wrap px-5 pt-4',
    ])>
        <div class="min-w-0">
            @if ($compact)
                <p class="truncate label-caps text-[11px] text-ink-muted">
                    {{ $dayLabel }}<span aria-hidden="true"> · </span>{{ $tournament ? $tournament->name : __('Friendly') }}@if ($round)<span aria-hidden="true"> · </span>{{ $round }}@endif
                </p>
            @else
                <p class="kicker truncate">
                    @if ($tournamentHref)
                        <a href="{{ $tournamentHref }}" wire:navigate class="hover:underline" data-smash-raise>{{ $tournament->name }}</a>
                    @else
                        {{ $tournament ? $tournament->name : __('Friendly') }}
                    @endif
                    @if ($round)
                        <span aria-hidden="true">·</span> {{ $round }}
                    @endif
                </p>
                @if ($when !== '')
                    <p class="mt-1 text-sm text-ink-muted">{{ $when }}</p>
                @endif
            @endif
        </div>

        <div class="flex shrink-0 flex-wrap items-center justify-end gap-1.5">
            @if ($forResult !== null)
                <x-ui.tag :tone="$forResult ? 'green' : 'smash'" :variant="$forResult ? 'solid' : 'soft'" size="sm">{{ $forResult ? __('Won') : __('Lost') }}</x-ui.tag>
            @endif
            @if ($upsetBy !== null)
                <x-ui.tag tone="smash" variant="solid" size="sm" icon="bolt" :title="__('Won as the underdog by :n rating points', ['n' => $upsetBy])">{{ __('Upset') }}</x-ui.tag>
            @endif
            @if ($isLive)
                <x-ui.live :variant="$compact ? 'text' : 'solid'" />
            @elseif ($isWalkover || $isCancelled)
                <x-smash.status-badge :match="$match" size="sm" />
            @endif
            @if (! $match->is_rated && ! $isCancelled && ! $isWalkover)
                <x-ui.tag tone="grey" size="sm" :title="__('Does not count towards the rankings')">{{ __('Unrated') }}</x-ui.tag>
            @endif
            <x-smash.discipline-badge :discipline="$discipline" />
        </div>
    </header>

    {{-- Scoreboard --}}
    @php
        $renderSides = $mode === 'time' ? 'fixture' : 'rows';
    @endphp
    <div @class([
        'flex items-stretch',
        $compact ? 'pt-2 pb-3' : 'pt-3 pb-4',
        'opacity-60' => $isCancelled,
    ])>
        <div class="min-w-0 flex-1 space-y-0.5">
            @foreach (['a', 'b'] as $key)
                @php
                    $s = $sides[$key];
                    $other = $key === 'a' ? 'b' : 'a';
                @endphp
                <div @class([
                    'flex items-center gap-4 py-1.5',
                    $padX,
                    'bg-navy-50 shadow-[inset_3px_0_0_var(--color-navy-800)] dark:bg-navy-400/8 dark:shadow-[inset_3px_0_0_var(--color-navy-400)]' => $s['you'],
                ]) data-smash-side="{{ $key }}" @if ($s['won'] === true) data-winner @endif>
                    {{-- Players --}}
                    <div class="min-w-0 flex-1 space-y-1.5">
                        @if ($s['placeholder'])
                            <div class="flex min-w-0 items-center gap-2.5">
                                @if ($avatars)
                                    <x-smash.avatar placeholder size="sm" class="max-sm:hidden" />
                                @endif
                                <span class="{{ $nameSize }} font-medium italic text-ink-muted">{{ $s['placeholder'] }}</span>
                            </div>
                        @else
                            @foreach ($s['lines'] as $line)
                                @php
                                    $user = $line['user'];
                                    $userHref = $user->getKey() !== null && Route::has('players.show') ? route('players.show', $user) : null;
                                @endphp
                                <div class="flex min-w-0 items-center gap-2.5">
                                    @if ($avatars)
                                        <x-smash.avatar :user="$user" size="sm" class="max-sm:hidden" />
                                    @endif
                                    <div class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                        @if ($userHref)
                                            <a href="{{ $userHref }}" wire:navigate @class([
                                                'min-w-0 max-w-full truncate underline-offset-[3px] decoration-1 hover:text-link hover:underline',
                                                $nameSize,
                                                $s['won'] === false ? 'font-medium text-ink-soft' : ($s['won'] === true ? 'font-bold text-ink' : 'font-semibold text-ink'),
                                            ]) data-smash-player-link>{{ $user->name }}</a>
                                        @else
                                            <span @class(['min-w-0 max-w-full truncate', $nameSize, $s['won'] === false ? 'font-medium text-ink-soft' : 'font-semibold text-ink'])>{{ $user->name }}</span>
                                        @endif
                                        @if ($line['rating'] !== null)
                                            <x-smash.rating :value="$line['rating']" :provisional="$line['provisional']" size="xs" muted />
                                        @endif
                                        @if ($line['delta'] !== null)
                                            <x-smash.rating-delta :value="$line['delta']" size="sm" />
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        @endif
                    </div>

                    {{-- Right-hand column for this side --}}
                    @if ($mode === 'scores')
                        <div class="flex shrink-0 items-center" aria-hidden="true">
                            @foreach ($cells as $cell)
                                @php $won = ! $cell['live'] && $cell[$key] > $cell[$other]; @endphp
                                <span @class([
                                    'num text-center leading-none',
                                    $cellClass,
                                    'font-bold text-live' => $cell['live'],
                                    'font-extrabold text-ink' => $won,
                                    'font-medium text-ink-muted' => ! $won && ! $cell['live'],
                                ])>{{ $cell[$key] }}</span>
                            @endforeach
                            <span @class([
                                'num ms-1.5 inline-flex items-center justify-center font-extrabold leading-none',
                                $boxClass,
                                'bg-heading text-canvas' => $s['won'] === true,
                                'text-ink-muted' => $s['won'] === false,
                                'border border-line-strong text-ink' => $s['won'] === null,
                            ])>{{ $s['games'] }}</span>
                        </div>
                    @elseif ($mode === 'walkover')
                        <div class="flex shrink-0 items-center" aria-hidden="true">
                            @if ($s['won'])
                                <span class="num inline-flex h-7 items-center bg-heading px-2 text-[15px] font-extrabold leading-none text-canvas">{{ __('W/O') }}</span>
                            @else
                                <span class="num w-7 text-center text-[17px] font-medium text-ink-muted">–</span>
                            @endif
                        </div>
                    @elseif ($mode === 'probability')
                        @php $favourite = $percent[$key] > $percent[$other]; @endphp
                        <div class="shrink-0 text-end" aria-hidden="true">
                            <span @class([
                                'num text-[19px] leading-none',
                                'font-extrabold' => $favourite,
                                'font-semibold text-ink-muted' => ! $favourite,
                                'text-heading' => $favourite && $key === 'a',
                                'text-orange-700 dark:text-orange-300' => $favourite && $key === 'b',
                            ])>{{ $percent[$key] }}<span class="text-[0.6em]">%</span></span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($mode === 'time')
            <div @class([
                'flex shrink-0 flex-col items-end justify-center border-s border-line ps-4 text-end',
                $compact ? 'pe-4' : 'pe-5',
            ]) aria-hidden="true">
                @if ($moment)
                    <span @class(['num font-extrabold leading-none text-heading', $compact ? 'text-[22px]' : 'text-[28px]'])>{{ $moment->format('H:i') }}</span>
                    <span class="mt-1.5 label-caps text-[11px] text-ink-muted">{{ $match->court ? __('Court :n', ['n' => $match->court]) : $dayLabel }}</span>
                @else
                    <span class="num text-[22px] font-extrabold leading-none text-ink-muted">{{ __('TBC') }}</span>
                @endif
            </div>
        @endif
    </div>

    {{-- Footer (default) or an overlay link (compact) --}}
    @if (! $compact && $footer && ($href || $notes !== ''))
        <footer class="mt-auto flex items-center justify-between gap-4 border-t border-line px-5 py-3">
            <span class="min-w-0 truncate text-sm text-ink-muted">{{ $notes }}</span>
            @if ($href)
                <x-ui.link
                    :href="$href"
                    class="shrink-0 after:absolute after:inset-0"
                    :aria-label="$linkText.': '.$nameA.' – '.$nameB"
                >{{ $linkText }}</x-ui.link>
            @endif
        </footer>
    @elseif ($href)
        <a href="{{ $href }}" wire:navigate class="absolute inset-0" aria-label="{{ $linkText.': '.$nameA.' – '.$nameB }}"></a>
    @endif
</x-ui.card>
