@props([
    /*
     | x-live.console — the umpire console on a navy band: game number, games so far, umpire
     | calls, the court with one big tap target per end, and the controls. Talks to the
     | Livewire page through $wire (point, serve, undo, swap, start) and handles the keyboard
     | shortcuts (A / ← left end, L / → right end, U / Backspace undo).
     |
     | match        BadmintonMatch (participants.user, games, tournament and entries loaded)
     | state        ?App\Support\Live\LiveScore
     | phase        start · lineup · serve · rally · decided · finished · cancelled · unreadable
     | swapped      Side A is drawn on the right (the players changed ends)
     | interactive  false = a static rendering (dev preview): taps and keys do nothing
     | canManage    The viewer is an admin (shows "Edit result" links)
     */
    'match',
    'state' => null,
    'phase' => 'rally',
    'swapped' => false,
    'interactive' => true,
    'canManage' => false,
])

@php
    use App\Enums\Discipline;
    use App\Enums\Side;
    use App\Support\Badminton\Scoring;
    use App\Support\Live\LiveScore;
    use Illuminate\Support\Str;

    $left = $swapped ? Side::B : Side::A;
    $right = $left->opposite();
    $doubles = $match->discipline === Discipline::Doubles;
    $onCourt = in_array($phase, ['rally', 'serve', 'decided', 'start'], true) && ($state !== null || $phase === 'start');

    // "Janssen", "Van Dijk / De Boer": how an umpire calls a side.
    $short = function (Side $side) use ($match): string {
        $players = $match->playersOn($side);

        return $players->isEmpty()
            ? $match->sideLabel($side)
            : $players->map(fn ($player) => Str::ucfirst($player->lastName()))->join(' / ');
    };

    $score = fn (array $game, Side $first): string => Scoring::format($game[$first->value], $game[$first->opposite()->value]);

    $serving = $phase === 'rally' ? $state?->serving() : null;
    $event = $phase === 'rally' ? $state?->event() : null;
    $current = $state?->current();
    $completed = $state?->completedGames() ?? [];
    $gameNumber = $state?->gameNumber() ?? 1;
    $deciding = $state?->isDecidingGame() ?? false;
    $winner = $state?->winner();

    $endOf = fn (?Side $side): ?string => $side === null ? null : ($side === $left ? 'left' : 'right');
    $sideMode = match ($phase) {
        'rally' => 'rally',
        'serve' => 'serve',
        'start' => 'start',
        'decided' => 'decided',
        default => 'view',
    };
@endphp

<section
    aria-label="{{ __('Umpire console') }}"
    data-phase="{{ $phase }}"
    data-left="{{ $left->value }}"
    @if ($interactive && in_array($phase, ['rally', 'serve'], true)) wire:poll.20s.visible @endif
    x-data="{
        busy: false,
        interactive: @js($interactive),
        init() {
            {{-- Courtside the console is the page: bring it to the top of the screen once. --}}
            if (@js($onCourt) && ! window.location.hash) {
                this.$nextTick(() => this.$root.scrollIntoView({ block: 'start' }));
            }
        },
        async press(method, ...args) {
            if (! this.interactive || this.busy) return;
            this.busy = true;
            try { await this.$wire[method](...args); } finally { this.busy = false; }
        },
        key(event) {
            if (event.repeat || event.ctrlKey || event.metaKey || event.altKey) return;
            if (event.target.closest('input:not([type=radio]), textarea, select, [contenteditable]')) return;
            if (document.querySelector('dialog[open]')) return;

            const phase = this.$root.dataset.phase;
            const left = this.$root.dataset.left;
            const right = left === 'a' ? 'b' : 'a';
            const key = event.key.toLowerCase();
            const action = phase === 'rally' ? 'point' : (phase === 'serve' ? 'serve' : null);

            if (action && (key === 'a' || key === 'arrowleft')) { event.preventDefault(); this.press(action, left); }
            else if (action && (key === 'l' || key === 'arrowright')) { event.preventDefault(); this.press(action, right); }
            else if ((phase === 'rally' || phase === 'decided') && (key === 'u' || key === 'backspace')) { event.preventDefault(); this.press('undo'); }
        },
    }"
    x-on:keydown.window="key($event)"
    {{ $attributes->class('theme-navy relative isolate overflow-hidden') }}
>
    <div class="container-site py-5 sm:py-8 lg:py-10">
        <div class="space-y-4">

            {{-- Top bar: game number, format, games so far, live state --}}
            @if ($onCourt)
                <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
                    <div class="flex min-w-0 flex-wrap items-baseline gap-x-3 gap-y-1">
                        <p class="num text-[2rem] font-extrabold uppercase leading-none text-white sm:text-[2.5rem]">
                            @if ($phase === 'decided')
                                {{ __('Final score') }}
                            @else
                                {{ __('Game :number', ['number' => $gameNumber]) }}
                            @endif
                        </p>
                        <p class="label-caps text-ink-muted">
                            {{ __('Best of :n · to :points', ['n' => $match->best_of, 'points' => $match->points_per_game]) }}
                        </p>
                        @if ($deciding && $phase !== 'decided')
                            <span class="inline-flex h-6 items-center border border-orange-400 px-2 text-[11px] font-semibold uppercase leading-none tracking-label text-orange-400">{{ __('Deciding game') }}</span>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                        @if ($completed !== [] && $phase !== 'decided')
                            <span class="flex items-center gap-2">
                                <span class="label-caps text-ink-muted">{{ __('Games') }}</span>
                                <x-smash.games :games="$completed" :side="$left" :target="$match->points_per_game" size="md" :live="false" />
                            </span>
                        @endif
                        @if ($phase === 'start')
                            <x-ui.tag tone="sky" variant="solid" size="sm">{{ __('Ready to play') }}</x-ui.tag>
                        @else
                            <x-ui.live variant="solid" />
                        @endif
                        <span x-show="busy" x-cloak class="inline-flex items-center gap-2 label-caps text-ink-muted">
                            <svg class="size-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" /><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                            {{ __('Saving') }}
                        </span>
                    </div>
                </div>
            @endif

            {{-- Umpire calls --}}
            @if ($phase === 'start')
                <x-live.notice tone="sky" icon="hand-raised" :title="__('Who serves first?')">
                    {{ __('Tap the side that serves the first rally and check each side is at the right end. Then start the match: the score goes live on the match page.') }}
                </x-live.notice>
            @elseif ($phase === 'serve')
                <x-live.notice tone="sky" icon="hand-raised" :title="__('Who serves next?')">
                    @if ($current['a'] + $current['b'] === 0 && $gameNumber === 1)
                        {{ __('The match is live but nobody has picked a server yet. Tap the side that serves the first rally.') }}
                    @else
                        {{ __('This match was scored on another device, so the console picked it up from the match record at :score in game :game. Tap the side that serves the next rally.', ['score' => $score($current, $left), 'game' => $gameNumber]) }}
                    @endif
                </x-live.notice>
            @elseif ($phase === 'decided' && $winner !== null)
                <div class="theme-default flex flex-wrap items-center justify-between gap-x-8 gap-y-5 bg-surface p-5 shadow-card sm:p-6 dark:border dark:border-line">
                    <div class="min-w-0">
                        <p class="font-display text-[1.75rem] font-extrabold uppercase italic leading-none text-smash-600 dark:text-smash-400">{{ __('Game, set and match') }}</p>
                        <p class="mt-2 font-serif text-h4 text-heading">
                            {{ __(':name wins :won–:lost', ['name' => $match->sideLabel($winner), 'won' => $state->gamesWon()[$winner->value], 'lost' => $state->gamesWon()[$winner->opposite()->value]]) }}
                        </p>
                        <x-smash.games class="mt-2" :games="$state->games()" :side="$winner" :target="$match->points_per_game" :live="false" />
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <x-ui.button variant="ghost" icon="arrow-uturn-left" x-on:click="press('undo')" x-bind:disabled="busy">{{ __('Undo last rally') }}</x-ui.button>
                        <flux:modal.trigger name="confirm-result">
                            <x-ui.button variant="cta" data-test="confirm-result">{{ __('Confirm result') }}</x-ui.button>
                        </flux:modal.trigger>
                    </div>
                </div>
            @elseif ($phase === 'rally')
                @php
                    $last = $completed === [] ? null : $completed[count($completed) - 1];
                    $leader = $current['a'] === $current['b'] ? null : Scoring::gameWinner($current['a'], $current['b']);
                @endphp
                @if ($event === LiveScore::GAME && $last !== null)
                    @php $gameWinner = Scoring::gameWinner($last['a'], $last['b']); @endphp
                    <x-live.notice icon="flag" :title="__('Game :n to :name, :score', ['n' => count($completed), 'name' => $short($gameWinner), 'score' => $score($last, $gameWinner)])">
                        {{ __('The players change ends. :server serves first in game :next from the right service court.', ['server' => $short($gameWinner), 'next' => $gameNumber]) }}
                        <x-slot:actions>
                            <x-live.control size="sm" icon="arrows-right-left" x-on:click="press('swap')" x-bind:disabled="busy">{{ __('Swap sides') }}</x-live.control>
                        </x-slot:actions>
                    </x-live.notice>
                @elseif ($event === LiveScore::CHANGE_ENDS && $leader !== null)
                    <x-live.notice icon="arrows-right-left" :title="__('Interval · change ends')">
                        {{ __(':score to :name in the deciding game. Up to 60 seconds, then the players change ends.', ['name' => $short($leader), 'score' => $score($current, $leader)]) }}
                        <x-slot:actions>
                            <x-live.control size="sm" variant="solid" icon="arrows-right-left" x-on:click="press('swap')" x-bind:disabled="busy">{{ __('Swap sides') }}</x-live.control>
                        </x-slot:actions>
                    </x-live.notice>
                @elseif ($event === LiveScore::INTERVAL && $leader !== null)
                    <x-live.notice icon="clock" :title="__('Interval')">
                        {{ __(':score to :name. Up to 60 seconds; coaches may come on court.', ['name' => $short($leader), 'score' => $score($current, $leader)]) }}
                    </x-live.notice>
                @elseif ($state->isGoldenPoint())
                    <x-live.notice tone="smash" icon="bolt" :title="__('Golden point')">
                        {{ __(':score: the next rally wins the game.', ['score' => $score($current, $left)]) }}
                    </x-live.notice>
                @elseif ($state->isExtended())
                    <x-live.notice icon="scale" :title="__('Setting')">
                        {{ __('From :n-all a side needs a two-point lead; at :cap-all the next rally wins the game.', ['n' => $match->points_per_game - 1, 'cap' => Scoring::cap($match->points_per_game) - 1]) }}
                    </x-live.notice>
                @endif
            @endif

            {{-- The court --}}
            @if ($onCourt)
                <div @class([
                    'relative isolate grid grid-cols-2 bg-surface ring-1 ring-white/10',
                    'min-h-[clamp(20rem,calc(100svh_-_22rem),34rem)] lg:min-h-[clamp(28rem,calc(100svh_-_19rem),34rem)]',
                ])>
                    <x-live.court
                        class="absolute inset-0 -z-10 size-full"
                        :doubles="$doubles"
                        :server="$endOf($serving)"
                        :court="$serving !== null ? $state->serviceCourt() : null"
                        :choice="$phase === 'start' ? '$wire.firstServer === \''.$left->value.'\' ? \'left\' : ($wire.firstServer === \''.$right->value.'\' ? \'right\' : null)' : null"
                    />
                    <x-live.side :match="$match" :side="$left" :state="$state" position="left" :mode="$sideMode" wire:key="side-left-{{ $left->value }}" />
                    <x-live.side :match="$match" :side="$right" :state="$state" position="right" :mode="$sideMode" wire:key="side-right-{{ $right->value }}" />
                </div>

                {{-- Controls --}}
                <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-4">
                    <div @class(['grid flex-1 gap-3 sm:flex sm:flex-none', 'grid-cols-2' => $phase !== 'start'])>
                        @if ($phase === 'start')
                            <x-ui.button
                                variant="cta"
                                icon="play"
                                :arrow="false"
                                wire:click="start"
                                x-bind:disabled="! $wire.firstServer"
                                data-test="start-match"
                            >{{ __('Start match') }}</x-ui.button>
                            <x-live.control icon="arrows-right-left" x-on:click="press('swap')" x-bind:disabled="busy">{{ __('Swap sides') }}</x-live.control>
                        @else
                            <x-live.control
                                icon="arrow-uturn-left"
                                shortcut="U"
                                x-on:click="press('undo')"
                                x-bind:disabled="busy || {{ $state?->canUndo() ? 'false' : 'true' }}"
                                data-test="undo"
                            >{{ __('Undo') }}</x-live.control>
                            <x-live.control icon="arrows-right-left" x-on:click="press('swap')" x-bind:disabled="busy" data-test="swap">{{ __('Swap sides') }}</x-live.control>
                        @endif
                    </div>

                    @if ($phase === 'rally' && $serving !== null)
                        <p class="text-sm text-ink-soft max-sm:w-full">
                            <span class="label-caps me-1 text-ink-muted">{{ __('Call') }}</span>
                            <span class="num text-[19px] font-bold text-white">{{ $score($current, $serving) }}</span>
                            <span class="ms-1">{{ __(':name serving from the :court', ['name' => $short($serving), 'court' => $state->serviceCourt() === 'right' ? __('right') : __('left')]) }}</span>
                        </p>
                    @elseif ($phase === 'start')
                        @error('firstServer')
                            <p class="text-sm font-semibold text-smash-300" role="alert">{{ $message }}</p>
                        @enderror
                    @endif
                </div>

                @if ($phase === 'serve')
                    <p class="text-sm text-ink-muted max-lg:hidden">{{ __('Keyboard: A or ← the left end serves · L or → the right end serves.') }}</p>
                @elseif ($phase !== 'start')
                    <p class="text-sm text-ink-muted max-lg:hidden">
                        {{ __('Keyboard: A or ← point to the left end · L or → point to the right end · U or Backspace undo.') }}
                    </p>
                @endif
            @else
                {{-- Nothing to score here --}}
                <div class="theme-default bg-surface p-6 shadow-card sm:p-8 dark:border dark:border-line">
                    @switch($phase)
                        @case('lineup')
                            <p class="kicker">{{ __('Waiting for the line-up') }}</p>
                            <p class="mt-2 font-serif text-h4 text-heading">{{ __('Both sides must be known before this match can start') }}</p>
                            <p class="mt-3 max-w-2xl text-ink-soft">
                                {{ $match->tournament_id !== null
                                    ? __('The open slot is filled in automatically when the previous round is played. Come back once both players are in the draw.')
                                    : __('Ask the committee to add the missing players to this match first.') }}
                            </p>
                            @break
                        @case('finished')
                            <p class="kicker">{{ __('Full time') }}</p>
                            <p class="mt-2 font-serif text-h4 text-heading">
                                @if ($match->winning_side !== null)
                                    {{ __('This match is finished: :name won :score', ['name' => $match->sideLabel($match->winning_side), 'score' => $match->gamesWon($match->winning_side).'–'.$match->gamesWon($match->winning_side->opposite())]) }}
                                @else
                                    {{ __('This match is finished') }}
                                @endif
                            </p>
                            @if ($match->games->isNotEmpty())
                                <x-smash.games class="mt-3" :match="$match" size="lg" />
                            @endif
                            <p class="mt-3 max-w-2xl text-ink-soft">{{ __('Finished matches can no longer be scored live. Corrections go through the committee\'s edit form.') }}</p>
                            @break
                        @case('cancelled')
                            <p class="kicker">{{ __('Cancelled') }}</p>
                            <p class="mt-2 font-serif text-h4 text-heading">{{ __('This match was cancelled') }}</p>
                            <p class="mt-3 max-w-2xl text-ink-soft">{{ __('A cancelled match cannot be scored. Reschedule it from the edit form if it is back on.') }}</p>
                            @break
                        @default
                            <p class="kicker">{{ __('Score needs a look') }}</p>
                            <p class="mt-2 font-serif text-h4 text-heading">{{ __('The saved score of this match cannot be continued here') }}</p>
                            <p class="mt-3 max-w-2xl text-ink-soft">{{ __('The games on the match record do not add up to a match in progress. A committee member can fix them in the edit form.') }}</p>
                    @endswitch

                    <div class="mt-6 flex flex-wrap items-center gap-3">
                        <x-ui.button variant="primary" :href="route('matches.show', $match)">{{ __('Match page') }}</x-ui.button>
                        @if ($canManage && in_array($phase, ['finished', 'cancelled', 'unreadable', 'lineup'], true))
                            <x-ui.button variant="outline" icon="pencil-square" :href="route('matches.edit', $match)">{{ $phase === 'finished' ? __('Edit result') : __('Edit match') }}</x-ui.button>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
</section>
