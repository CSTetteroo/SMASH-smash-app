@props([
    /*
     | x-tournaments.card — a tournament on the index page, BUas event-card style: date badge,
     | kicker, serif name, when / where, then a block for its stage and a footer action.
     | The name is a stretched link (the whole card is clickable); buttons and links in the
     | footer stay separately clickable.
     |
     | tournament   App\Models\Tournament with entries_count. In-progress tournaments need their
     |              matches loaded (columns are enough); completed ones their matches with games and
     |              entryA/entryB (+ player, partner), and entries with player and partner.
     | stage        now · open · soon · draft · completed (default: from the status).
     | entered      The viewer plays in it: "You're in" instead of the Register button.
     */
    'tournament',
    'stage' => null,
    'entered' => false,
])

@use('App\Enums\MatchStatus')
@use('App\Enums\Side')
@use('App\Enums\TournamentStatus')

@php
    $t = $tournament;

    $stage ??= match ($t->status) {
        TournamentStatus::Draft => 'draft',
        TournamentStatus::Open => $t->isOpenForRegistration() ? 'open' : 'soon',
        TournamentStatus::InProgress => 'now',
        default => 'completed',
    };

    $tone = match ($stage) {
        'now' => 'orange',
        'open' => 'green',
        'soon' => 'sky',
        'draft' => 'grey',
        default => 'navy',
    };

    $href = route('tournaments.show', $t);
    $drawTab = $t->isRoundRobin() ? 'standings' : 'draw';
    $isDoubles = $t->isDoubles();
    $count = $t->entryCount();
    $max = $t->max_entries;
    $unit = fn (int $n): string => $isDoubles
        ? trans_choice(':n pair|:n pairs', $n, ['n' => $n])
        : trans_choice(':n player|:n players', $n, ['n' => $n]);

    $day = fn ($date): string => $date->format($date->isCurrentYear() ? 'D j M' : 'D j M Y');
    $starts = $t->starts_at;
    $ends = $t->ends_at;
    $when = $ends === null || $ends->isSameDay($starts)
        ? $day($starts).' · '.$starts->format('H:i').($ends ? ' – '.$ends->format('H:i') : '')
        : $day($starts).' – '.$day($ends);
    $venue = $t->venue ?: config('smash.club.venue');

    // ---- Draw numbers (in progress, coming up, completed) ---------------------------------
    $matches = $t->relationLoaded('matches') ? $t->matches : collect();
    $playable = $matches->reject(fn ($m) => $m->isBye() || $m->status === MatchStatus::Cancelled);
    $played = $playable->filter(fn ($m) => $m->isFinished())->count();
    $total = $playable->count();
    $live = $playable->filter(fn ($m) => $m->isLive())->count();
    $openRound = $playable->reject(fn ($m) => $m->isFinished())->min('round');
    $nextAt = $playable
        ->filter(fn ($m) => $m->status === MatchStatus::Scheduled && $m->scheduled_at !== null)
        ->min('scheduled_at');

    // Knockout: entries that have not lost yet.
    $knockedOut = $matches
        ->filter(fn ($m) => $m->isFinished() && ! $m->isBye() && $m->winning_side !== null)
        ->map(fn ($m) => $m->winning_side === Side::A ? $m->entry_b_id : $m->entry_a_id)
        ->filter()
        ->unique()
        ->count();

    // ---- Registration ---------------------------------------------------------------------
    $deadline = $t->registrationDeadline();
    $deadlineText = match (true) {
        $deadline === null => null,
        $deadline->isToday() => __('Closes today at :time', ['time' => $deadline->format('H:i')]),
        $deadline->isTomorrow() => __('Closes tomorrow at :time', ['time' => $deadline->format('H:i')]),
        default => __('Closes :day at :time', ['day' => $day($deadline), 'time' => $deadline->format('H:i')]),
    };
    $spotsLeft = $t->spotsLeft();

    // ---- Podium ---------------------------------------------------------------------------
    $champion = $stage === 'completed' ? $t->champion() : null;
    $runnerUp = $champion ? $t->runnerUp() : null;
    $final = $champion && $t->isKnockout() ? $t->finalMatch() : null;
    // Games won by the champion first ("2–1"), from the loaded games (no participants needed).
    $finalScore = $final && $final->status === MatchStatus::Completed && $final->winning_side !== null
        ? $final->gamesWon($final->winning_side).'–'.$final->gamesWon($final->winning_side->opposite())
        : null;
@endphp

<x-ui.card
    as="article"
    padding="none"
    :highlight="$tone"
    {{ $attributes->class('h-full [&_[data-raise]]:relative [&_[data-raise]]:z-10') }}
    data-tournament-card="{{ $stage }}"
>
    <div class="flex flex-1 flex-col p-5 sm:p-6">
        <div class="flex items-start gap-4">
            <x-ui.date-badge :date="$starts" weekday size="sm" class="mt-0.5" />

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                    <p class="kicker">{{ $t->discipline->label() }} · {{ $t->format->label() }}</p>

                    @if ($stage === 'now' && $live > 0)
                        <x-ui.live variant="solid" />
                    @elseif ($stage === 'soon')
                        @if ($t->status === TournamentStatus::InProgress)
                            <x-ui.tag tone="navy" size="sm">{{ __('Draw made') }}</x-ui.tag>
                        @elseif ($t->isFull())
                            <x-ui.tag tone="smash" size="sm">{{ __('Full') }}</x-ui.tag>
                        @else
                            <x-ui.tag tone="grey" size="sm">{{ __('Registration closed') }}</x-ui.tag>
                        @endif
                    @else
                        <x-smash.status-badge :status="$t->status" size="sm" />
                    @endif
                </div>

                <h3 class="mt-2 font-serif text-h4 text-balance text-heading">
                    <a
                        href="{{ $href }}"
                        wire:navigate
                        class="decoration-1 underline-offset-4 after:absolute after:inset-0 hover:underline group-hover/card:underline"
                    >{{ $t->name }}</a>
                </h3>

                <div class="mt-3 space-y-1">
                    <x-ui.meta icon="clock" class="text-sm">{{ $when }}</x-ui.meta>
                    <x-ui.meta icon="map-pin" class="text-sm">{{ $venue }}</x-ui.meta>
                </div>
            </div>
        </div>

        <div class="mt-6 flex flex-1 flex-col justify-end gap-4">
            @switch ($stage)
                @case('open')
                    @if ($max !== null)
                        <x-ui.progress
                            :value="$count"
                            :max="$max"
                            capacity
                            :label="__(':count of :max', ['count' => $count, 'max' => $unit($max)])"
                            :value-label="$spotsLeft === 1 ? __('1 place left') : __(':n places left', ['n' => $spotsLeft])"
                        />
                    @else
                        <x-ui.meta icon="user-group" class="text-sm">{{ __(':entries registered · no limit', ['entries' => $unit($count)]) }}</x-ui.meta>
                    @endif
                    @if ($deadlineText)
                        <x-ui.meta icon="calendar-days" @class(['text-sm', 'font-semibold text-ink' => $deadline->diffInHours(now(), true) < 48])>{{ $deadlineText }}</x-ui.meta>
                    @endif
                    @break

                @case('now')
                    @if ($openRound !== null)
                        <x-ui.meta icon="trophy" class="text-sm">
                            <span class="font-semibold text-ink">{{ __(':round next', ['round' => $t->roundLabel((int) $openRound, plural: true)]) }}</span>
                            @if ($nextAt)
                                · {{ $day($nextAt) }}, {{ $nextAt->format('H:i') }}
                            @endif
                        </x-ui.meta>
                    @endif
                    <x-ui.progress
                        :value="$played"
                        :max="max(1, $total)"
                        tone="orange"
                        :label="__('Matches played')"
                        :value-label="$played.' / '.$total"
                    />
                    <x-ui.meta icon="user-group" class="text-sm">
                        @if ($t->isKnockout())
                            {{ __(':left of :entries still in', ['left' => max(0, $count - $knockedOut), 'entries' => $unit($count)]) }}
                        @else
                            {{ __(':entries · :day of :days', [
                                'entries' => $unit($count),
                                'day' => $openRound !== null ? __('matchday :n', ['n' => $openRound]) : __('last matchday'),
                                'days' => $t->totalRounds(),
                            ]) }}
                        @endif
                    </x-ui.meta>
                    @break

                @case('soon')
                    <p class="text-[15px] leading-relaxed text-ink-soft">
                        @if ($t->status === TournamentStatus::InProgress)
                            {{ __('The draw is out: :matches for :entries. First serve :day at :time.', [
                                'matches' => trans_choice(':n match|:n matches', $total, ['n' => $total]),
                                'entries' => $unit($count),
                                'day' => $day($starts),
                                'time' => $starts->format('H:i'),
                            ]) }}
                        @elseif ($t->isFull())
                            {{ __('All :max are taken. The draw is made before the first serve.', ['max' => $unit((int) $max)]) }}
                        @else
                            {{ __('Registration closed with :entries. The draw is made before the first serve.', ['entries' => $unit($count)]) }}
                        @endif
                    </p>
                    @break

                @case('draft')
                    <p class="text-[15px] leading-relaxed text-ink-soft">
                        {{ __('Only admins can see this draft. Open registration from the tournament page when the details are final.') }}
                    </p>
                    @break

                @default
                    @if ($champion)
                        <div class="border-t border-line pt-4">
                            <p class="tone-orange flex items-center gap-1.5 label-caps text-[var(--tone-ink)]">
                                <flux:icon.trophy variant="mini" class="size-4" />
                                {{ __('Champion') }}
                            </p>
                            <x-smash.player
                                :users="$champion->members()"
                                :discipline="$t->discipline"
                                :meta="false"
                                size="sm"
                                class="mt-2"
                                data-raise
                            />
                            @if ($runnerUp)
                                <p class="mt-2 text-sm text-ink-soft">
                                    @if ($finalScore)
                                        {{ __('Beat :name :score in the final.', ['name' => $runnerUp->name(), 'score' => $finalScore]) }}
                                    @elseif ($final)
                                        {{ __('Won the final against :name by walkover.', ['name' => $runnerUp->name()]) }}
                                    @else
                                        {{ __('Topped the table ahead of :name.', ['name' => $runnerUp->name()]) }}
                                    @endif
                                </p>
                            @endif
                        </div>
                    @endif
                    <x-ui.meta icon="user-group" class="text-sm">
                        {{ $unit($count) }} · {{ trans_choice(':n match|:n matches', $total, ['n' => $total]) }}
                    </x-ui.meta>
            @endswitch
        </div>
    </div>

    <footer class="flex min-h-14 flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-2.5 sm:px-6">
        @switch ($stage)
            @case('open')
                @if ($entered)
                    <x-ui.tag tone="green" variant="solid" icon="check">{{ __("You're in") }}</x-ui.tag>
                @else
                    <x-ui.button variant="cta" size="sm" :href="$href" data-raise>{{ __('Register') }}</x-ui.button>
                @endif
                <x-ui.link>{{ __('Details') }}</x-ui.link>
                @break

            @case('now')
                @if ($entered)
                    <x-ui.tag tone="navy" variant="solid" size="sm">{{ __('You play') }}</x-ui.tag>
                @endif
                <x-ui.link class="ms-auto" :href="route('tournaments.show', ['tournament' => $t, 'tab' => $drawTab])" data-raise>
                    {{ $t->isRoundRobin() ? __('Standings') : __('Follow the draw') }}
                </x-ui.link>
                @break

            @case('soon')
                @if ($entered)
                    <x-ui.tag tone="navy" variant="solid" size="sm">{{ __("You're in") }}</x-ui.tag>
                @endif
                <x-ui.link class="ms-auto" :href="route('tournaments.show', ['tournament' => $t, 'tab' => $t->hasDraw() ? $drawTab : 'entries'])" data-raise>
                    {{ $t->hasDraw() ? __('See the draw') : __('Who is in') }}
                </x-ui.link>
                @break

            @case('draft')
                @can('update', $t)
                    <x-ui.button variant="outline" size="sm" icon="pencil-square" :href="route('tournaments.edit', $t)" data-raise>{{ __('Edit') }}</x-ui.button>
                @endcan
                <x-ui.link class="ms-auto">{{ __('Preview') }}</x-ui.link>
                @break

            @default
                <x-ui.link class="ms-auto" :href="route('tournaments.show', ['tournament' => $t, 'tab' => $drawTab])" data-raise>
                    {{ $t->isRoundRobin() ? __('Final table') : __('Results and draw') }}
                </x-ui.link>
        @endswitch
    </footer>
</x-ui.card>
