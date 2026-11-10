<?php

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\User;
use App\Services\Prediction\MatchPrediction;
use App\Services\Prediction\MatchPredictor;
use App\Services\Stats\HeadToHead;
use App\Services\Stats\PlayerStats;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Head-to-head')] class extends Component {
    /** Rivalries and opponent suggestions shown while the line-up is incomplete. */
    private const SUGGESTIONS = 6;

    /** Player ids from the query string (strings, so junk in the URL is harmless). */
    #[Url(except: '')]
    public string $a = '';

    #[Url(except: '')]
    public string $b = '';

    /** all · singles · doubles */
    #[Url(except: 'all')]
    public string $discipline = 'all';

    public function swap(): void
    {
        [$this->a, $this->b] = [$this->b, $this->a];
    }

    #[Computed]
    public function playerA(): ?User
    {
        return $this->findPlayer($this->a);
    }

    #[Computed]
    public function playerB(): ?User
    {
        return $this->findPlayer($this->b);
    }

    /**
     * The discipline filter for meetings; null = all matches.
     */
    #[Computed]
    public function filter(): ?Discipline
    {
        return Discipline::tryFrom($this->discipline);
    }

    /**
     * Two different, existing players picked.
     */
    #[Computed]
    public function ready(): bool
    {
        return $this->playerA !== null && $this->playerB !== null && ! $this->playerA->is($this->playerB);
    }

    #[Computed]
    public function samePlayer(): bool
    {
        return $this->playerA !== null && $this->playerB !== null && $this->playerA->is($this->playerB);
    }

    /**
     * An id in the URL that is not a player (any more).
     */
    #[Computed]
    public function unknownPlayer(): bool
    {
        return ($this->a !== '' && $this->playerA === null) || ($this->b !== '' && $this->playerB === null);
    }

    #[Computed]
    public function record(): ?HeadToHead
    {
        return $this->ready ? PlayerStats::headToHead($this->playerA, $this->playerB, $this->filter) : null;
    }

    /**
     * Meetings in every discipline (only asked for when the filter hides all of them).
     */
    #[Computed]
    public function meetingsInAll(): int
    {
        if (! $this->ready) {
            return 0;
        }

        return $this->filter === null
            ? $this->record?->meetings() ?? 0
            : PlayerStats::headToHead($this->playerA, $this->playerB)->meetings();
    }

    /**
     * "Who wins?" for a singles match today.
     */
    #[Computed]
    public function prediction(): ?MatchPrediction
    {
        return $this->ready ? MatchPredictor::predict([$this->playerA], [$this->playerB], Discipline::Singles) : null;
    }

    /**
     * Current ranking positions per discipline.
     *
     * @return array<string, array{positions: array<int, int>, total: int}>
     */
    #[Computed]
    public function ranks(): array
    {
        $ranks = [];

        foreach (Discipline::cases() as $discipline) {
            $ids = User::query()->ranked($discipline)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $ranks[$discipline->value] = ['positions' => array_flip($ids), 'total' => count($ids)];
        }

        return $ranks;
    }

    /**
     * Everybody for the pickers: active members first, former members after.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function players(): Collection
    {
        return User::query()->orderBy('name')->get(['id', 'name', 'is_active']);
    }

    /**
     * The most-played pairings in the club (any two players who faced each other).
     *
     * @return Collection<int, array{a: User, b: User, meetings: int, winsA: int}>
     */
    #[Computed]
    public function rivalries(): Collection
    {
        $rows = $this->meetings()
            ->whereColumn('opponent.user_id', '>', 'player.user_id')
            ->groupBy('player.user_id', 'opponent.user_id')
            ->selectRaw('player.user_id as player_id, opponent.user_id as opponent_id, count(*) as meetings, sum(case when matches.winning_side = player.side then 1 else 0 end) as wins, max(matches.played_at) as last_played')
            ->orderByDesc('meetings')
            ->orderByDesc('last_played')
            ->limit(self::SUGGESTIONS)
            ->get();

        $users = User::query()->whereKey($rows->pluck('player_id')->merge($rows->pluck('opponent_id'))->unique()->all())->get()->keyBy('id');

        return $rows
            ->filter(fn (object $row): bool => $users->has((int) $row->player_id) && $users->has((int) $row->opponent_id))
            ->map(fn (object $row): array => [
                'a' => $users->get((int) $row->player_id),
                'b' => $users->get((int) $row->opponent_id),
                'meetings' => (int) $row->meetings,
                'winsA' => (int) $row->wins,
            ])
            ->values();
    }

    /**
     * Opponents for the one player picked so far: the people they meet most, or (with no
     * history yet) the active players closest to their singles rating.
     *
     * @return Collection<int, array{user: User, meetings: int, wins: int}>
     */
    #[Computed]
    public function suggestions(): Collection
    {
        $chosen = $this->ready ? null : ($this->playerA ?? $this->playerB);

        if ($chosen === null) {
            return collect();
        }

        $rows = $this->meetings()
            ->where('player.user_id', $chosen->id)
            ->groupBy('opponent.user_id')
            ->selectRaw('opponent.user_id as opponent_id, count(*) as meetings, sum(case when matches.winning_side = player.side then 1 else 0 end) as wins, max(matches.played_at) as last_played')
            ->orderByDesc('meetings')
            ->orderByDesc('last_played')
            ->limit(self::SUGGESTIONS)
            ->get();

        if ($rows->isNotEmpty()) {
            $users = User::query()->whereKey($rows->pluck('opponent_id')->all())->get()->keyBy('id');

            return $rows
                ->filter(fn (object $row): bool => $users->has((int) $row->opponent_id))
                ->map(fn (object $row): array => ['user' => $users->get((int) $row->opponent_id), 'meetings' => (int) $row->meetings, 'wins' => (int) $row->wins])
                ->values();
        }

        // Players who have actually played come first: newcomers all sit on the starting rating.
        return User::query()
            ->active()
            ->whereKeyNot($chosen->id)
            ->orderByRaw('case when singles_played > 0 then 0 else 1 end')
            ->orderByRaw('abs(singles_rating - ?)', [$chosen->ratingExact(Discipline::Singles)])
            ->orderBy('name')
            ->limit(self::SUGGESTIONS)
            ->get()
            ->map(fn (User $user): array => ['user' => $user, 'meetings' => 0, 'wins' => 0])
            ->values();
    }

    /**
     * URL of this page for a pairing, keeping the discipline filter.
     */
    public function pairUrl(int $a, int $b): string
    {
        return route('head-to-head', array_filter(['a' => $a, 'b' => $b, 'discipline' => $this->filter?->value]));
    }

    /**
     * Completed matches seen from each participant ("player") against each opponent on the other side.
     */
    private function meetings(): Builder
    {
        return DB::table('match_players as player')
            ->join('match_players as opponent', function (JoinClause $join): void {
                $join->on('opponent.match_id', '=', 'player.match_id')
                    ->on('opponent.side', '<>', 'player.side');
            })
            ->join('matches', 'matches.id', '=', 'player.match_id')
            ->where('matches.status', MatchStatus::Completed->value)
            ->whereNotNull('matches.winning_side')
            ->when($this->filter, fn (Builder $query, Discipline $discipline) => $query->where('matches.discipline', $discipline->value));
    }

    private function findPlayer(string $id): ?User
    {
        return ctype_digit($id) && strlen($id) < 10 ? User::query()->find((int) $id) : null;
    }
}; ?>

@php
    $playerA = $this->playerA;
    $playerB = $this->playerB;
    $filter = $this->filter;
    $filterLabel = $filter ? mb_strtolower($filter->label()) : null;
    $players = $this->players;
@endphp

<div>
    <x-ui.page-hero
        :breadcrumbs="[[__('Rankings'), route('rankings.index')], __('Head-to-head')]"
        :kicker="__('Rankings')"
        :title="__('Head-to-head')"
        :intro="__('Pick two players to see every meeting, who leads the series and what the predictor expects the next time they share a court.')"
    >
        <div class="space-y-5" role="search" aria-label="{{ __('Choose two players') }}">
            <div class="grid items-end gap-3 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
                @foreach (['a' => __('Player'), 'b' => __('Opponent')] as $side => $label)
                    @if ($side === 'b')
                        <x-ui.button
                            variant="outline"
                            square
                            size="sm"
                            icon="arrows-right-left"
                            wire:click="swap"
                            :aria-label="__('Swap players')"
                            class="size-10! bg-surface max-sm:hidden"
                        />
                    @endif
                    <flux:select wire:model.live="{{ $side }}" :label="$label" :placeholder="__('Choose a player')" wire:key="picker-{{ $side }}">
                        <optgroup label="{{ __('Members') }}">
                            @foreach ($players->where('is_active', true) as $player)
                                <option value="{{ $player->id }}" wire:key="{{ $side }}-{{ $player->id }}">{{ $player->name }}</option>
                            @endforeach
                        </optgroup>
                        @if ($players->where('is_active', false)->isNotEmpty())
                            <optgroup label="{{ __('Former members') }}">
                                @foreach ($players->where('is_active', false) as $player)
                                    <option value="{{ $player->id }}" wire:key="{{ $side }}-{{ $player->id }}">{{ $player->name }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                    </flux:select>
                @endforeach
            </div>

            <div class="flex flex-wrap items-end justify-between gap-3">
                <flux:radio.group wire:model.live="discipline" :label="__('Meetings')" variant="segmented" class="w-full sm:w-auto">
                    <flux:radio value="all" :label="__('All')" />
                    <flux:radio value="singles" :label="__('Singles')" />
                    <flux:radio value="doubles" :label="__('Doubles')" />
                </flux:radio.group>
                <x-ui.button variant="outline" size="sm" icon="arrows-right-left" wire:click="swap" class="bg-surface sm:hidden">{{ __('Swap players') }}</x-ui.button>
            </div>

            @if ($this->samePlayer)
                <x-ui.alert tone="warning" :title="__('That is the same player twice')">
                    {{ __(':name cannot play themselves. Pick someone else for one of the sides.', ['name' => $playerA->name]) }}
                </x-ui.alert>
            @elseif ($this->unknownPlayer)
                <x-ui.alert tone="warning" :title="__('We could not find that player')">
                    {{ __('The link may be out of date. Choose the players from the lists above.') }}
                </x-ui.alert>
            @endif
        </div>
    </x-ui.page-hero>

    @if ($this->ready)
        @php
            $record = $this->record;
            $prediction = $this->prediction;
            $meetings = $record->meetings();
            $leader = match (true) {
                $record->winsA > $record->winsB => $playerA,
                $record->winsB > $record->winsA => $playerB,
                default => null,
            };
            $last = $record->lastMeeting;
        @endphp

        {{-- Face-off -------------------------------------------------------------------------- --}}
        <x-ui.section tone="navy" spacing="none" class="relative isolate overflow-hidden py-12 lg:py-16" aria-labelledby="face-off-heading">
            <h2 id="face-off-heading" class="sr-only">{{ __(':a against :b', ['a' => $playerA->name, 'b' => $playerB->name]) }}</h2>

            <div class="grid grid-cols-2 gap-x-4 gap-y-10 md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] md:items-center md:gap-x-10">
                <x-rankings.contender :user="$playerA" :ranks="$this->ranks" align="end" :leading="$leader?->is($playerA) ?? false" class="md:order-1" />

                <div class="order-last col-span-2 flex flex-col items-center text-center md:order-2 md:col-span-1 md:px-4">
                    <p class="label-caps text-ink-muted">{{ $filter ? __(':d wins', ['d' => $filter->label()]) : __('Wins') }}</p>
                    <p class="sr-only">
                        @if ($meetings === 0)
                            {{ __('No meetings yet.') }}
                        @else
                            {{ __(':a :wa, :b :wb.', ['a' => $playerA->name, 'wa' => $record->winsA, 'b' => $playerB->name, 'wb' => $record->winsB]) }}
                        @endif
                    </p>
                    <p class="num mt-3 flex items-start font-extrabold leading-[0.8] text-[clamp(5rem,3.6rem+6vw,9rem)]" aria-hidden="true">
                        <span @class(['text-ink', 'opacity-45' => $leader?->is($playerB)])>{{ $record->winsA }}</span>
                        <span class="px-[0.12em] font-semibold text-ink-muted">–</span>
                        <span @class(['text-ink', 'opacity-45' => $leader?->is($playerA)])>{{ $record->winsB }}</span>
                    </p>

                    @if ($meetings > 0)
                        <div class="mt-5 flex h-1.5 w-full max-w-60 gap-0.5" aria-hidden="true">
                            <span class="h-full bg-white" style="width: {{ round($record->winsA / $meetings * 100, 2) }}%"></span>
                            <span class="h-full flex-1 bg-orange-400"></span>
                        </div>
                    @endif

                    <p class="mt-4 max-w-72 text-[15px] leading-snug text-ink-soft">
                        @if ($meetings === 0)
                            {{ $filter ? __('No :d meetings yet.', ['d' => $filterLabel]) : __('They have never met. Yet.') }}
                        @elseif ($leader === null)
                            {{ trans_choice('All square after :n meeting|All square after :n meetings', $meetings, ['n' => $meetings]) }}
                        @else
                            {{ trans_choice(':name leads after :n meeting|:name leads after :n meetings', $meetings, ['name' => $leader->firstName(), 'n' => $meetings]) }}
                        @endif
                    </p>
                </div>

                <x-rankings.contender :user="$playerB" :ranks="$this->ranks" :leading="$leader?->is($playerB) ?? false" class="md:order-3" />
            </div>

            @if ($meetings > 0)
                @php
                    $lastSide = $last->sideOf($playerA);
                    $lastWonByA = $last->isWonBy($playerA);
                @endphp
                <dl class="mt-12 grid grid-cols-2 gap-x-6 gap-y-8 border-t border-line pt-8 md:grid-cols-[1fr_1fr_2fr] lg:mt-14">
                    <div>
                        <dt class="label-caps text-ink-muted">{{ __('Games won') }}</dt>
                        <dd class="num mt-2 text-[2.25rem] leading-none font-extrabold text-ink">
                            <span class="sr-only">{{ __(':a :x, :b :y', ['a' => $playerA->firstName(), 'x' => $record->gamesA, 'b' => $playerB->firstName(), 'y' => $record->gamesB]) }}</span>
                            <span aria-hidden="true">{{ $record->gamesA }}<span class="px-0.5 font-semibold text-ink-muted">–</span>{{ $record->gamesB }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="label-caps text-ink-muted">{{ __('Points won') }}</dt>
                        <dd class="num mt-2 text-[2.25rem] leading-none font-extrabold text-ink">
                            <span class="sr-only">{{ __(':a :x, :b :y', ['a' => $playerA->firstName(), 'x' => $record->pointsA, 'b' => $playerB->firstName(), 'y' => $record->pointsB]) }}</span>
                            <span aria-hidden="true">{{ $record->pointsA }}<span class="px-0.5 font-semibold text-ink-muted">–</span>{{ $record->pointsB }}</span>
                        </dd>
                    </div>
                    <div class="col-span-2 md:col-span-1">
                        <dt class="label-caps text-ink-muted">{{ __('Last meeting') }}</dt>
                        <dd class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2">
                            <a href="{{ route('matches.show', $last) }}" wire:navigate class="font-serif text-[19px] leading-snug text-heading decoration-1 underline-offset-4 hover:underline">
                                {{ $last->played_at?->format($last->played_at->isCurrentYear() ? 'l j F' : 'j F Y') ?? __('Date unknown') }}
                            </a>
                            <span class="text-[15px] text-ink-soft">
                                {{ __(':winner won :score', ['winner' => $last->sideLabel($last->winning_side), 'score' => $last->gamesWon($last->winning_side).'–'.$last->gamesWon($last->winning_side->opposite())]) }}
                                @if ($last->tournament)
                                    · {{ $last->tournament->name }}
                                @endif
                            </span>
                            <x-smash.games :match="$last" :side="$lastSide" size="sm" />
                        </dd>
                    </div>
                </dl>
            @endif
        </x-ui.section>

        {{-- Prediction ---------------------------------------------------------------------------- --}}
        <x-ui.section :kicker="__('Who wins?')" :title="__('If they played today')" :intro="__('The predictor’s view of a singles match between them right now: current ratings, recent form and their previous singles meetings.')">
            <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)] lg:gap-10">
                <x-ui.card padding="lg">
                    <x-smash.win-probability :prediction="$prediction" :side-a="[$playerA]" :side-b="[$playerB]" variant="large" :caption="__('Singles · best of 3 to 21')" />
                </x-ui.card>

                <div>
                    <dl class="divide-y divide-line border-y border-line">
                        @foreach ($prediction->factors as $factor)
                            @php $shift = (int) round($factor['shift']); @endphp
                            <div class="grid gap-1 py-3.5 sm:grid-cols-[10rem_minmax(0,1fr)_auto] sm:items-baseline sm:gap-4" wire:key="factor-{{ $factor['key'] }}">
                                <dt class="label-caps text-ink-muted">{{ $factor['label'] }}</dt>
                                <dd class="text-[15px] text-ink-soft">{{ $factor['detail'] }}</dd>
                                <dd class="text-sm whitespace-nowrap text-ink-muted sm:text-end">
                                    @if ($factor['key'] !== 'provisional' && $shift !== 0)
                                        <span class="num text-[15px] font-bold text-ink">+{{ abs($shift) }}</span>
                                        {{ __('for :name', ['name' => \Illuminate\Support\Str::ucfirst(($shift > 0 ? $playerA : $playerB)->lastName())]) }}
                                    @elseif ($factor['key'] !== 'provisional')
                                        {{ __('Neutral') }}
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                        <div class="grid gap-1 py-3.5 sm:grid-cols-[10rem_minmax(0,1fr)] sm:items-baseline sm:gap-4">
                            <dt class="label-caps text-ink-muted">{{ __('Typical game') }}</dt>
                            <dd class="text-[15px] text-ink-soft">
                                <span class="num text-[17px] font-bold text-ink">{{ $prediction->expectedGameScore[0] }}–{{ $prediction->expectedGameScore[1] }}</span>
                                {{ $prediction->favourite === null ? __('either way') : __('to :name', ['name' => ($prediction->favourite === Side::A ? $playerA : $playerB)->firstName()]) }}
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-6 flex flex-wrap items-center gap-x-6 gap-y-3">
                        <x-ui.button variant="primary" icon-trailing="arrow-right" :href="route('predictor', ['a1' => $playerA->id, 'b1' => $playerB->id, 'd' => Discipline::Singles->value])">{{ __('Open in predictor') }}</x-ui.button>
                        <x-ui.link :href="route('how-it-works')" variant="promo">{{ __('How predictions work') }}</x-ui.link>
                    </div>
                </div>
            </div>
        </x-ui.section>

        {{-- Meetings ----------------------------------------------------------------------------- --}}
        <x-ui.section
            tone="grey"
            id="meetings"
            :kicker="$filter ? __(':d only', ['d' => $filter->label()]) : __('Singles and doubles')"
            :title="__('Every meeting')"
        >
            @if ($meetings > 0)
                <x-slot:actions>
                    <p class="text-sm text-ink-soft">
                        {{ trans_choice(':n match, newest first|:n matches, newest first', $meetings, ['n' => $meetings]) }}
                    </p>
                </x-slot:actions>

                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach ($record->matches as $match)
                        <x-smash.match-card :match="$match" :for="$playerA" variant="compact" wire:key="meeting-{{ $match->id }}" />
                    @endforeach
                </div>
            @else
                <x-ui.empty :title="$filter && $this->meetingsInAll > 0 ? __('No :d meetings', ['d' => $filterLabel]) : __(':a and :b have never met', ['a' => $playerA->firstName(), 'b' => $playerB->firstName()])" class="bg-surface">
                    @if ($filter && $this->meetingsInAll > 0)
                        {{ trans_choice('They have met :n time in the other discipline.|They have met :n times in the other discipline.', $this->meetingsInAll, ['n' => $this->meetingsInAll]) }}
                    @else
                        {{ __('No completed matches between them yet. Find each other at the next club night and settle it on court.') }}
                    @endif
                    <x-slot:actions>
                        @if ($filter && $this->meetingsInAll > 0)
                            <x-ui.button variant="outline" wire:click="$set('discipline', 'all')">{{ __('Show all meetings') }}</x-ui.button>
                        @else
                            <x-ui.button variant="outline" :href="route('club-nights.index')" icon="calendar-days">{{ __('See club nights') }}</x-ui.button>
                        @endif
                    </x-slot:actions>
                </x-ui.empty>
            @endif
        </x-ui.section>
    @elseif ($this->suggestions->isNotEmpty())
        @php
            $chosen = $playerA ?? $playerB;
            $emptySide = $playerA ? 'b' : 'a';
            $hasHistory = $this->suggestions->sum('meetings') > 0;
        @endphp

        {{-- One player picked: suggest opponents ------------------------------------------------------ --}}
        <x-ui.section
            :kicker="$hasHistory ? __('Most frequent opponents') : __('Similar rating')"
            :title="__('Who should :name face?', ['name' => $chosen->firstName()])"
            :intro="$hasHistory ? __('The players :name has met most often. Pick one, or choose anybody from the list above.', ['name' => $chosen->firstName()]) : __(':name has no completed matches yet, so here are members with a similar singles rating.', ['name' => $chosen->firstName()])"
        >
            <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->suggestions as $item)
                    @php
                        $url = $emptySide === 'b' ? $this->pairUrl($chosen->id, $item['user']->id) : $this->pairUrl($item['user']->id, $chosen->id);
                    @endphp
                    <li wire:key="suggestion-{{ $item['user']->id }}">
                        <x-ui.card :href="$url" padding="sm" class="h-full flex-row! items-center justify-between gap-4 px-5">
                            <x-smash.player :user="$item['user']" :href="false" rating />
                            @if ($item['meetings'] > 0)
                                <span class="flex shrink-0 flex-col items-end">
                                    <span class="num text-[19px] leading-none font-bold text-ink">{{ $item['wins'] }}–{{ $item['meetings'] - $item['wins'] }}</span>
                                    <span class="mt-1 label-caps text-ink-muted">{{ trans_choice(':n meeting|:n meetings', $item['meetings'], ['n' => $item['meetings']]) }}</span>
                                </span>
                            @else
                                <flux:icon.arrow-right variant="micro" class="size-4 shrink-0 text-link" />
                            @endif
                        </x-ui.card>
                    </li>
                @endforeach
            </ul>
        </x-ui.section>
    @else
        {{-- Nobody picked yet: the club's classic rivalries --------------------------------------------- --}}
        <x-ui.section
            :kicker="__('Classic rivalries')"
            :title="__('The most-played match-ups')"
            :intro="__('Pairs who have faced each other most often across the net. Open one, or pick any two players above.')"
        >
            @if ($this->rivalries->isNotEmpty())
                <ul class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($this->rivalries as $rivalry)
                        @php
                            $winsB = $rivalry['meetings'] - $rivalry['winsA'];
                        @endphp
                        <li wire:key="rivalry-{{ $rivalry['a']->id }}-{{ $rivalry['b']->id }}">
                            <x-ui.card :href="$this->pairUrl($rivalry['a']->id, $rivalry['b']->id)" padding="md" class="h-full">
                                <div class="flex items-center justify-between gap-3">
                                    <x-smash.player :user="$rivalry['a']" :href="false" size="sm" :winner="$rivalry['winsA'] === $winsB ? null : $rivalry['winsA'] > $winsB" />
                                    <span class="num shrink-0 text-[26px] leading-none font-extrabold text-ink">{{ $rivalry['winsA'] }}</span>
                                </div>
                                <div class="mt-3 flex items-center justify-between gap-3">
                                    <x-smash.player :user="$rivalry['b']" :href="false" size="sm" :winner="$rivalry['winsA'] === $winsB ? null : $winsB > $rivalry['winsA']" />
                                    <span class="num shrink-0 text-[26px] leading-none font-extrabold text-ink">{{ $winsB }}</span>
                                </div>
                                <div class="mt-5 flex items-center justify-between gap-3 border-t border-line pt-4">
                                    <span class="label-caps text-ink-muted">{{ trans_choice(':n meeting|:n meetings', $rivalry['meetings'], ['n' => $rivalry['meetings']]) }}</span>
                                    <x-ui.link>{{ __('Compare') }}</x-ui.link>
                                </div>
                            </x-ui.card>
                        </li>
                    @endforeach
                </ul>
            @else
                <x-ui.empty :title="__('No rivalries yet')">
                    {{ __('Once members have played a few matches against each other, the most-played match-ups show up here.') }}
                </x-ui.empty>
            @endif
        </x-ui.section>
    @endif
</div>
