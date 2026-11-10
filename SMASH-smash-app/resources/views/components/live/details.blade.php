@props([
    /*
     | x-live.details — under the console: the scoresheet (every game, side A first), the rallies
     | scored on this console (newest first) and a short "how umpire mode works".
     |
     | match   BadmintonMatch (participants.user loaded)
     | state   ?App\Support\Live\LiveScore
     | phase   see x-live.console
     */
    'match',
    'state' => null,
    'phase' => 'rally',
])

@php
    use App\Enums\Side;
    use App\Support\Badminton\Scoring;
    use App\Support\Live\LiveScore;
    use Illuminate\Support\Str;

    $short = function (Side $side) use ($match): string {
        $players = $match->playersOn($side);

        return $players->isEmpty()
            ? $match->sideLabel($side)
            : $players->map(fn ($player) => Str::ucfirst($player->lastName()))->join(' / ');
    };

    $target = $match->points_per_game;
    $games = $state?->games() ?? [];
    $decided = $state?->isDecided() ?? false;
    $history = $state !== null ? array_reverse($state->history(), true) : [];
    $shown = array_slice($history, 0, 15, true);
    $base = $state?->base ?? [];
    $resumedAt = $base !== [] && $base !== [['a' => 0, 'b' => 0]] ? $base : null;

    $eventLabel = [
        LiveScore::GAME => __('Game'),
        LiveScore::INTERVAL => __('Interval'),
        LiveScore::CHANGE_ENDS => __('Change ends'),
        LiveScore::MATCH => __('Match'),
    ];
@endphp

<x-ui.section {{ $attributes }} spacing="md">
    <div @class(['grid gap-6', 'lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,22rem)]' => $state !== null, 'lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]' => $state === null])>
        @if ($state !== null)
            {{-- Scoresheet --}}
            <div>
                <x-ui.heading :level="2" size="h4" :ornament="false">{{ __('Scoresheet') }}</x-ui.heading>
                <x-ui.table class="mt-4" dense :hover="false">
                    <table>
                        <thead>
                            <tr>
                                <th>{{ __('Game') }}</th>
                                <th class="text-end">{{ $short(Side::A) }}</th>
                                <th class="text-end">{{ $short(Side::B) }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($games as $index => $game)
                                @php
                                    $complete = Scoring::isCompleteGame($game['a'], $game['b'], $target);
                                    $gameWinner = $complete ? Scoring::gameWinner($game['a'], $game['b']) : null;
                                @endphp
                                <tr wire:key="sheet-{{ $index }}">
                                    <td>
                                        <span class="font-semibold text-ink">{{ __('Game :n', ['n' => $index + 1]) }}</span>
                                        @unless ($complete)
                                            <x-ui.live class="ms-2" :label="__('In play')" />
                                        @endunless
                                    </td>
                                    @foreach ([Side::A, Side::B] as $side)
                                        <td @class([
                                            'num text-end text-[19px]',
                                            'font-extrabold text-ink' => $gameWinner === $side,
                                            'font-semibold text-ink-soft' => $gameWinner !== $side && $complete,
                                            'font-bold text-live' => ! $complete,
                                        ])>{{ $game[$side->value] }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-ui.table>
                <p class="mt-3 text-sm text-ink-muted">
                    @if ($decided)
                        {{ __('Decided — confirm the result to save it.') }}
                    @else
                        {{ __('Saved to the match page after every rally.') }}
                    @endif
                </p>
            </div>

            {{-- Rally by rally --}}
            <div>
                <x-ui.heading :level="2" size="h4" :ornament="false">{{ __('Rally by rally') }}</x-ui.heading>
                @if ($shown === [])
                    <x-ui.empty class="mt-4" align="start" :title="__('No rallies yet')">
                        @if ($resumedAt !== null)
                            {{ __('Picked up from the match record. Rallies you score on this console appear here, newest first.') }}
                        @else
                            {{ __('Rallies you score appear here, newest first, so you can check the last few before undoing.') }}
                        @endif
                    </x-ui.empty>
                @else
                    <ol class="mt-4 divide-y divide-line border-y border-line" aria-label="{{ __('Rallies, newest first') }}">
                        @foreach ($shown as $index => $rally)
                            <li class="flex items-center justify-between gap-4 py-2.5" wire:key="rally-{{ $index }}">
                                <span class="flex min-w-0 items-center gap-3">
                                    <span class="num w-8 shrink-0 text-[15px] font-semibold text-ink-muted">{{ $index + 1 }}</span>
                                    <span class="truncate text-[15px] text-ink">{{ __('Point :name', ['name' => $short($rally['winner'])]) }}</span>
                                    @if ($rally['event'] !== null)
                                        <x-ui.tag size="sm" :tone="in_array($rally['event'], [LiveScore::GAME, LiveScore::MATCH], true) ? 'navy' : 'orange'">{{ $eventLabel[$rally['event']] }}</x-ui.tag>
                                    @endif
                                </span>
                                <span class="shrink-0 text-end">
                                    <span class="label-caps me-1.5 text-ink-muted">{{ __('G:n', ['n' => $rally['game']]) }}</span>
                                    <span class="num text-[17px] font-bold text-ink">{{ Scoring::format($rally['a'], $rally['b']) }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                    @if (count($history) > count($shown) || $resumedAt !== null)
                        <p class="mt-3 text-sm text-ink-muted">
                            @if ($resumedAt !== null)
                                {{ __('Picked up from the match record at :score; earlier rallies were scored on another device.', ['score' => collect($resumedAt)->map(fn ($g) => Scoring::format($g['a'], $g['b']))->join(', ')]) }}
                            @else
                                {{ __('Showing the last :n of :total rallies.', ['n' => count($shown), 'total' => count($history)]) }}
                            @endif
                        </p>
                    @endif
                @endif
            </div>
        @else
            {{-- Before the first rally: who is playing and the match details --}}
            <div>
                <x-ui.heading :level="2" size="h4" :ornament="false">{{ __('The match') }}</x-ui.heading>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach ([Side::A, Side::B] as $side)
                        <x-ui.card padding="sm" wire:key="lineup-{{ $side->value }}">
                            <p class="label-caps text-ink-muted">{{ $side === Side::A ? __('Side A') : __('Side B') }}</p>
                            <x-smash.player class="mt-3" :match="$match" :side="$side" rating size="sm" />
                        </x-ui.card>
                    @endforeach
                </div>
                <x-ui.facts class="mt-6 max-sm:grid-cols-2" :columns="3" :items="array_filter([
                    __('Scheduled') => $match->scheduled_at?->format('D j M · H:i') ?? __('To be confirmed'),
                    __('Venue') => $match->venue ?? config('smash.club.venue'),
                    __('Court') => $match->court !== null ? (string) $match->court : __('Any free court'),
                    __('Format') => __('Best of :n · to :points', ['n' => $match->best_of, 'points' => $target]),
                    __('Ratings') => $match->is_rated ? __('Rated match') : __('Unrated'),
                    __('Type') => $match->tournament?->name ?? __('Friendly'),
                ])" />
            </div>
        @endif

        {{-- How it works --}}
        <x-ui.card variant="muted" padding="md" class="self-start">
            <p class="kicker">{{ __('Umpire mode') }}</p>
            <p class="mt-2 font-serif text-h5 text-heading">{{ __('How scoring works') }}</p>
            <ul class="mt-4 space-y-3 text-[15px] leading-relaxed text-ink-soft">
                <li>{{ __('Tap the end that won the rally. Every rally scores, and its winner serves the next one.') }}</li>
                <li>{{ __('The server serves from the right service court on an even score and from the left on an odd one.') }}</li>
                <li>{{ __('Interval when the leader reaches :n; in the deciding game the players also change ends.', ['n' => intdiv($target + 1, 2)]) }}</li>
                <li>{{ __('From :n-all a side needs two clear points; at :cap-all the next rally wins.', ['n' => $target - 1, 'cap' => Scoring::cap($target) - 1]) }}</li>
                <li>{{ __('Undo still works after a reload. When the match is decided, confirm the result: ratings are recalculated and tournament winners move on in the draw.') }}</li>
            </ul>
        </x-ui.card>
    </div>
</x-ui.section>
