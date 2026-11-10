@props([
    /*
     | x-matches.game-breakdown — one row per game with point bars (side A navy, side B orange),
     | the winner and the margin. Used on pages::matches.show for finished and live matches.
     | match   App\Models\BadmintonMatch with games and players loaded.
     */
    'match',
])

@php
    $target = (int) $match->points_per_game;
    $live = $match->isLive();
    $games = $match->games->values();
    $scale = max($target, (int) $games->max(fn ($game) => max($game->side_a_points, $game->side_b_points)));

    $name = function (\App\Enums\Side $side) use ($match): string {
        $entry = $match->tournament_id !== null ? $match->entryOn($side) : null;
        $players = $entry !== null ? $entry->members() : $match->playersOn($side);

        return $players->isEmpty()
            ? $match->sideLabel($side)
            : $players->map(fn ($user) => $players->count() > 1 ? \Illuminate\Support\Str::ucfirst($user->lastName()) : $user->firstName())->join(' & ');
    };
    $names = ['a' => $name(\App\Enums\Side::A), 'b' => $name(\App\Enums\Side::B)];
@endphp

<ol {{ $attributes->class('divide-y divide-line') }} role="list" data-test="game-breakdown">
    @foreach ($games as $game)
        @php
            $complete = $game->isComplete($target);
            $inPlay = $live && $loop->last && ! $complete;
            $gameWinner = $complete ? $game->winner() : null;
            $margin = abs($game->side_a_points - $game->side_b_points);
            $extended = max($game->side_a_points, $game->side_b_points) > $target;

            $caption = match (true) {
                $inPlay => __('In play'),
                $gameWinner === null => __('Not finished'),
                default => trans_choice(':name by :count point|:name by :count points', $margin, ['name' => $names[$gameWinner->value], 'count' => $margin]),
            };
        @endphp
        <li class="py-4 first:pt-0 last:pb-0" wire:key="gb-{{ $game->number }}">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <p class="label-caps text-ink">{{ __('Game :n', ['n' => $game->number]) }}</p>
                <p class="flex items-center gap-2 text-sm text-ink-muted">
                    @if ($inPlay)
                        <x-ui.live :label="__('In play')" />
                    @else
                        <span>{{ $caption }}</span>
                    @endif
                    @if ($extended)
                        <x-ui.tag tone="orange" size="sm">{{ __('Extra points') }}</x-ui.tag>
                    @endif
                </p>
            </div>

            <div class="mt-3 space-y-1.5">
                @foreach (['a', 'b'] as $key)
                    @php
                        $points = $key === 'a' ? $game->side_a_points : $game->side_b_points;
                        $won = $gameWinner?->value === $key;
                    @endphp
                    <div class="grid grid-cols-[minmax(0,6.5rem)_minmax(0,1fr)_2.25rem] items-center gap-3 sm:grid-cols-[minmax(0,9rem)_minmax(0,1fr)_2.5rem]">
                        <span @class(['truncate text-sm', 'font-semibold text-ink' => $won, 'text-ink-soft' => ! $won]) title="{{ $names[$key] }}">{{ $names[$key] }}</span>
                        <span class="relative h-3 bg-surface-muted" aria-hidden="true">
                            <span
                                @class([
                                    'absolute inset-y-0 start-0',
                                    'bg-navy-800 dark:bg-navy-300' => $key === 'a',
                                    'bg-orange-500 dark:bg-orange-400' => $key === 'b',
                                    'opacity-45' => $gameWinner !== null && ! $won,
                                ])
                                style="width: {{ $scale > 0 ? round($points / $scale * 100, 1) : 0 }}%"
                            ></span>
                        </span>
                        <span @class(['num text-end text-xl leading-none', 'font-extrabold text-ink' => $won, 'font-semibold text-ink-soft' => ! $won, 'text-live' => $inPlay])>
                            <span class="sr-only">{{ $names[$key] }}:</span>{{ $points }}
                        </span>
                    </div>
                @endforeach
            </div>
        </li>
    @endforeach
</ol>
