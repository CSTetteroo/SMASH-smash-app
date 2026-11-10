@props([
    /*
     | x-matches.scoreboard — the big two-sided scoreboard at the top of pages::matches.show.
     | match   App\Models\BadmintonMatch, loaded withDetails().
     | Finished matches show pre-match ratings (rating_before); upcoming and live matches show the
     | players' current ratings. The loser is dimmed and the winner gets a "Winner" line.
     */
    'match',
])

@php
    $status = $match->status;
    $finished = $match->isFinished();
    $live = $match->isLive();
    $walkover = $status === \App\Enums\MatchStatus::Walkover;
    $cancelled = $status === \App\Enums\MatchStatus::Cancelled;
    $scheduled = $status === \App\Enums\MatchStatus::Scheduled;
    $winner = $match->winning_side;
    $discipline = $match->discipline;
    $doubles = $discipline === \App\Enums\Discipline::Doubles;

    $sides = [];
    foreach (\App\Enums\Side::cases() as $side) {
        $entry = $match->tournament_id !== null ? $match->entryOn($side) : null;
        $players = $entry !== null ? $entry->members() : $match->playersOn($side);

        $sides[$side->value] = [
            'side' => $side,
            'players' => $players->values()->map(function ($user) use ($match, $finished, $cancelled, $discipline) {
                $before = $match->participantFor($user)?->rating_before;
                $upcoming = ! $finished && ! $cancelled;

                return [
                    'user' => $user,
                    'rating' => $before ?? ($upcoming ? $user->ratingExact($discipline) : null),
                    'provisional' => $upcoming && $before === null && $user->isProvisional($discipline),
                ];
            }),
            'label' => $match->sideLabel($side),
            'won' => $winner === null ? null : $winner === $side,
            'games' => $match->gamesWon($side),
        ];
    }

    $moment = $finished ? ($match->played_at ?? $match->scheduled_at) : ($match->scheduled_at ?? $match->played_at);

    $centreCaption = match (true) {
        $live => null,
        $walkover => __('Walkover'),
        $cancelled => __('Cancelled'),
        $finished => $match->is_rated ? __('Final') : __('Final · practice match'),
        $moment !== null => $moment->isToday() ? __('Today') : ($moment->isTomorrow() ? __('Tomorrow') : $moment->format('D j M')),
        default => __('Date to be confirmed'),
    };

    $showGames = $match->games->isNotEmpty() && ($finished || $live);
    $playerSize = $doubles ? 'md' : 'lg';

    // Screen-reader summary.
    $summary = match (true) {
        $finished && $winner !== null && ! $walkover => __(':winner beat :loser :score.', [
            'winner' => $match->sideLabel($winner),
            'loser' => $match->sideLabel($winner->opposite()),
            'score' => $match->scoreline().($match->games->isNotEmpty() ? ' ('.$match->games->map->score()->join(', ').')' : ''),
        ]),
        $walkover && $winner !== null => __(':winner were awarded a walkover against :loser.', ['winner' => $match->sideLabel($winner), 'loser' => $match->sideLabel($winner->opposite())]),
        $live => __('Live: :a :ga, :b :gb in games.', ['a' => $sides['a']['label'], 'ga' => $sides['a']['games'], 'b' => $sides['b']['label'], 'gb' => $sides['b']['games']]),
        default => $match->matchup().'.',
    };
@endphp

<div {{ $attributes->class('min-w-0') }} data-test="scoreboard">
    <p class="sr-only">{{ $summary }}</p>

    {{-- ============================================================ phones: two rows --}}
    <div class="space-y-3 sm:hidden">
        @foreach ($sides as $key => $side)
            <div @class(['flex items-center justify-between gap-4', 'opacity-55' => $side['won'] === false])>
                <div class="min-w-0 space-y-2">
                    @forelse ($side['players'] as $line)
                        <x-smash.player
                            :user="$line['user']"
                            size="sm"
                            :rating="$line['rating'] ?? false"
                            :provisional="$line['provisional']"
                            :meta="false"
                            :winner="$side['won'] ? true : null"
                        />
                    @empty
                        <x-smash.player :match="$match" :side="$key" size="sm" />
                    @endforelse
                </div>
                @if ($finished && ! $walkover && ! $cancelled || $live)
                    <span @class([
                        'num inline-flex size-11 shrink-0 items-center justify-center text-2xl font-extrabold',
                        'bg-navy-800 text-white dark:bg-navy-400 dark:text-navy-950' => $side['won'],
                        'bg-surface-muted text-ink' => ! $side['won'],
                    ])>{{ $side['games'] }}</span>
                @elseif ($walkover && $side['won'])
                    <span class="num inline-flex h-11 shrink-0 items-center justify-center bg-navy-800 px-2.5 text-xl font-extrabold text-white dark:bg-navy-400 dark:text-navy-950">W/O</span>
                @endif
            </div>
            @if ($loop->first)
                <div class="flex items-center gap-3">
                    <span class="h-px flex-1 bg-line"></span>
                    <span class="label-caps text-ink-muted">
                        @if ($live)
                            <x-ui.live />
                        @elseif ($scheduled && $moment)
                            {{ $centreCaption }} · {{ $moment->format('H:i') }}
                        @else
                            {{ $centreCaption }}
                        @endif
                    </span>
                    <span class="h-px flex-1 bg-line"></span>
                </div>
            @endif
        @endforeach
    </div>

    {{-- ============================================================ sm and up: versus row --}}
    <x-ui.versus :winner="$winner" class="max-sm:hidden">
        <x-slot:a>
            <x-matches.scoreboard-side :match="$match" :side="$sides['a']" key-name="a" align="end" :size="$playerSize" :walkover="$walkover" />
        </x-slot:a>
        <x-slot:b>
            <x-matches.scoreboard-side :match="$match" :side="$sides['b']" key-name="b" align="start" :size="$playerSize" :walkover="$walkover" />
        </x-slot:b>

        <x-slot:center>
            @if ($live)
                <x-ui.live variant="solid" class="mb-3" />
            @endif

            @if (($finished && ! $walkover) || $live)
                <span class="num text-6xl font-extrabold leading-none text-ink lg:text-7xl">{{ $sides['a']['games'] }}<span class="px-1 text-ink-muted">–</span>{{ $sides['b']['games'] }}</span>
            @elseif ($walkover)
                <span class="num text-5xl font-extrabold leading-none text-ink lg:text-6xl">W/O</span>
            @elseif ($scheduled && $moment)
                <span class="num text-5xl font-extrabold leading-none text-ink lg:text-6xl">{{ $moment->format('H:i') }}</span>
            @else
                <span class="num text-4xl font-bold uppercase leading-none text-ink-muted">{{ __('vs') }}</span>
            @endif

            @if ($centreCaption)
                <span class="mt-3 label-caps text-ink-muted">{{ $centreCaption }}</span>
            @endif
        </x-slot:center>
    </x-ui.versus>

    @if ($showGames)
        <div class="mt-6 flex justify-center border-t border-line pt-6 sm:mt-8 sm:pt-8">
            <x-smash.games :match="$match" layout="boxes" size="lg" class="justify-center" />
        </div>
    @endif
</div>
