@props([
    /*
     | x-smash.bracket — a knockout draw: one column per round with connector lines, scrolling
     | sideways on small screens. Feed it App\Services\Tournament\Bracket::for($tournament).
     |
     | rounds       list of ['round' => int, 'label' => string, 'matches' => iterable<BadmintonMatch>],
     |              matches in bracket_position order (round r has half the matches of round r − 1).
     |              Connectors are only drawn where a round halves into the next, so round robin
     |              matchdays render as plain columns (prefer standings + match cards for those).
     | for          A User whose path is highlighted.
     | champion     ?TournamentEntry (e.g. $tournament->champion()): adds a champion column.
     | games        Show per-game points in the boxes.
     | label        Accessible name of the draw ("Halloween Smash draw").
     | empty        Text for an empty state when there is no draw yet (default: render nothing).
     | href         Box links, passed to x-smash.bracket-match: null = the match page (default),
     |              false = none, or a Closure(BadmintonMatch) returning a URL / null / false
     |              per match (e.g. admin scoring links).
     */
    'rounds' => [],
    'for' => null,
    'champion' => null,
    'games' => true,
    'label' => null,
    'empty' => null,
    'href' => null,
])

@php
    $rounds = collect($rounds)->map(fn ($round) => [
        'round' => (int) ($round['round'] ?? 0),
        'label' => (string) ($round['label'] ?? ''),
        'matches' => collect($round['matches'] ?? [])->values(),
    ])->filter(fn ($round) => $round['matches']->isNotEmpty())->values();

    // Does round $r feed into round $r + 1 (two matches per next match)?
    $feeds = fn (int $r): bool => isset($rounds[$r + 1]) && $rounds[$r + 1]['matches']->count() * 2 === $rounds[$r]['matches']->count();
@endphp

@if ($rounds->isNotEmpty())
    <div {{ $attributes->class('relative overflow-x-auto overscroll-x-contain pb-3') }} role="group" aria-label="{{ $label ?? __('Tournament draw') }}" data-smash-bracket>
        <ol class="flex min-w-max items-stretch" role="list">
            @foreach ($rounds as $r => $round)
                @php
                    $count = $round['matches']->count();
                    $isLast = $r === $rounds->count() - 1;
                    $joins = $feeds($r);
                    // Line out to the right: into the next round, or into the champion column.
                    $out = $joins || ($isLast && $champion !== null && $count === 1);
                    // Line in from the left: the previous round feeds this one.
                    $in = $r > 0 && $feeds($r - 1);
                @endphp
                <li class="flex w-60 shrink-0 flex-col sm:w-64">
                    <h3 class="mb-3 flex items-baseline gap-2 px-4">
                        <span class="label-caps text-ink">{{ $round['label'] }}</span>
                        <span class="text-xs text-ink-muted">{{ trans_choice(':n match|:n matches', $count, ['n' => $count]) }}</span>
                    </h3>
                    <ol class="flex flex-1 flex-col" role="list">
                        @foreach ($round['matches'] as $i => $match)
                            <li @class([
                                'relative flex flex-1 items-center px-4 py-2',
                                'after:absolute after:top-1/2 after:right-0 after:h-px after:w-4 after:bg-line-strong' => $out,
                                'before:absolute before:right-0 before:w-px before:bg-line-strong' => $joins,
                                'before:top-1/2 before:h-1/2' => $joins && $i % 2 === 0,
                                'before:top-0 before:h-1/2' => $joins && $i % 2 === 1,
                            ]) wire:key="bracket-{{ $round['round'] }}-{{ $match->getKey() ?? $i }}">
                                <div @class([
                                    'relative w-full',
                                    'before:absolute before:top-1/2 before:-left-4 before:h-px before:w-4 before:bg-line-strong' => $in,
                                ])>
                                    <x-smash.bracket-match :match="$match" :for="$for" :games="$games" :href="$href instanceof \Closure ? $href($match) : $href" />
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </li>
            @endforeach

            @if ($champion)
                <li class="flex w-56 shrink-0 flex-col">
                    <h3 class="mb-3 flex items-baseline gap-2 px-4"><span class="label-caps text-ink">{{ __('Champion') }}</span></h3>
                    <div class="flex flex-1 items-center px-4 py-2">
                        <div class="relative w-full before:absolute before:top-1/2 before:-left-4 before:h-px before:w-4 before:bg-line-strong">
                            <div class="tone-orange flex items-center gap-3 border border-line border-t-4 border-t-[var(--tone)] bg-surface px-4 py-3">
                                <flux:icon.trophy variant="mini" class="size-5 shrink-0 text-[var(--tone)]" />
                                <x-smash.player :users="$champion->members()" :discipline="$champion->discipline()" size="sm" :meta="false" :avatar="false" />
                            </div>
                        </div>
                    </div>
                </li>
            @endif
        </ol>
    </div>
@elseif ($empty)
    <x-ui.empty :title="$empty" variant="bordered" {{ $attributes }} data-smash-bracket />
@endif
