<?php

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Programme;
use App\Models\BadmintonMatch;
use App\Models\User;
use App\Services\Rating\Leaderboard;
use App\Services\Rating\LeaderboardRow;
use App\Services\Rating\RatedHistory;
use App\Services\Rating\RatedResult;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Rankings')] class extends Component {
    use WithPagination;

    /** Rows per page in the full table. */
    private const PER_PAGE = 25;

    /** Options of the "minimum matches" filter. */
    private const MINIMUMS = [1, 5, 10];

    /** Entries in each side panel. */
    private const PANEL_SIZE = 5;

    #[Url(except: 'singles')]
    public string $discipline = 'singles';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $programme = '';

    #[Url(except: '1')]
    public string $min = '1';

    #[Url(except: true)]
    public bool $provisional = true;

    /** "Compare two players" (user ids as strings, validated on submit). */
    public string $compareA = '';

    public string $compareB = '';

    public function mount(): void
    {
        $user = auth()->user();

        if ($user instanceof User && $user->is_active) {
            $this->compareA = (string) $user->id;
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['discipline', 'search', 'programme', 'min', 'provisional'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'programme', 'min', 'provisional');
        $this->resetPage();
    }

    /**
     * Jump to the page that holds the signed-in player's row (clearing name/programme filters
     * that hide them) and scroll it into view.
     */
    public function findMe(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        if ($this->me === null) {
            Flux::toast(text: __('You are not in this ranking yet. Play a rated :discipline match to get on the board.', ['discipline' => mb_strtolower($this->selectedDiscipline->label())]));

            return;
        }

        $index = $this->filtered->search(fn (LeaderboardRow $row): bool => $row->user->is($user));

        if ($index === false) {
            $this->search = '';
            $this->programme = '';
            unset($this->filtered, $this->rows);
            $index = $this->filtered->search(fn (LeaderboardRow $row): bool => $row->user->is($user));
        }

        $this->setPage(intdiv((int) $index, self::PER_PAGE) + 1);
        unset($this->rows);

        $this->js("setTimeout(() => document.getElementById('player-{$user->id}')?.scrollIntoView({ block: 'center', behavior: 'smooth' }), 60)");
    }

    /**
     * Open the head-to-head page for two players.
     */
    public function compare(): void
    {
        $this->validate([
            'compareA' => ['required', 'integer', Rule::exists('users', 'id')],
            'compareB' => ['required', 'integer', 'different:compareA', Rule::exists('users', 'id')],
        ], [
            'compareA.required' => __('Pick the first player.'),
            'compareB.required' => __('Pick someone to compare with.'),
            'compareB.different' => __('Pick two different players.'),
            'compareA.integer' => __('Pick a player from the list.'),
            'compareA.exists' => __('Pick a player from the list.'),
            'compareB.integer' => __('Pick a player from the list.'),
            'compareB.exists' => __('Pick a player from the list.'),
        ]);

        $this->redirectRoute('head-to-head', ['a' => (int) $this->compareA, 'b' => (int) $this->compareB], navigate: true);
    }

    #[Computed]
    public function selectedDiscipline(): Discipline
    {
        return Discipline::tryFrom($this->discipline) ?? Discipline::Singles;
    }

    #[Computed]
    public function selectedProgramme(): ?Programme
    {
        return Programme::tryFrom($this->programme);
    }

    /**
     * Rated matches needed to appear: hiding provisional players raises it to the provisional threshold.
     */
    #[Computed]
    public function minimum(): int
    {
        $min = in_array((int) $this->min, self::MINIMUMS, true) ? (int) $this->min : 1;

        return $this->provisional ? $min : max($min, $this->provisionalThreshold());
    }

    /**
     * The ranking itself: everybody in the pool, best first, with their real positions.
     *
     * @return Collection<int, LeaderboardRow>
     */
    #[Computed]
    public function pool(): Collection
    {
        return Leaderboard::for($this->selectedDiscipline, $this->minimum);
    }

    /**
     * The pool narrowed by name and programme (positions stay the real ones).
     *
     * @return Collection<int, LeaderboardRow>
     */
    #[Computed]
    public function filtered(): Collection
    {
        $search = mb_substr(trim($this->search), 0, 60);

        if ($search === '' && $this->selectedProgramme === null) {
            return $this->pool;
        }

        return Leaderboard::for($this->selectedDiscipline, $this->minimum, $search, $this->selectedProgramme);
    }

    /**
     * @return LengthAwarePaginator<int, LeaderboardRow>
     */
    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $rows = $this->filtered;
        $total = $rows->count();
        $page = min(max(1, (int) $this->getPage()), max(1, (int) ceil($total / self::PER_PAGE)));

        return new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $total,
            self::PER_PAGE,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    /**
     * Ranked players per discipline, for the tab counts.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        $counts = [];

        foreach (Discipline::cases() as $discipline) {
            $counts[$discipline->value] = $discipline === $this->selectedDiscipline
                ? $this->pool->count()
                : User::query()->ranked($discipline, $this->minimum)->count();
        }

        return $counts;
    }

    /**
     * The signed-in player's row in the pool, if they are ranked.
     */
    #[Computed]
    public function me(): ?LeaderboardRow
    {
        $user = auth()->user();

        return $user instanceof User
            ? $this->pool->first(fn (LeaderboardRow $row): bool => $row->user->is($user))
            : null;
    }

    /**
     * Side panels over the whole pool: rating gained in the last 30 days, current win streaks
     * and rated matches played in the last 30 days.
     *
     * @return array{movers: Collection<int, LeaderboardRow>, streaks: Collection<int, array{row: LeaderboardRow, wins: int}>, active: Collection<int, array{row: LeaderboardRow, matches: int}>}
     */
    #[Computed]
    public function highlights(): array
    {
        $rows = $this->pool->keyBy(fn (LeaderboardRow $row): int => $row->user->id);

        if ($rows->isEmpty()) {
            return ['movers' => collect(), 'streaks' => collect(), 'active' => collect()];
        }

        $history = RatedHistory::load($this->selectedDiscipline, $rows->keys()->all());
        $since = RatedHistory::format(now()->subDays(Leaderboard::PERIOD_DAYS));
        $streaks = [];
        $active = [];

        foreach ($rows as $id => $row) {
            $results = $history->for($id);

            $run = 0;
            foreach (array_reverse($results) as $result) {
                if (! $result->won) {
                    break;
                }
                $run++;
            }

            if ($run >= 2) {
                $streaks[] = ['row' => $row, 'wins' => $run];
            }

            $recent = count(array_filter($results, fn (RatedResult $result): bool => $result->playedAt !== null && $result->playedAt >= $since));

            if ($recent > 0) {
                $active[] = ['row' => $row, 'matches' => $recent];
            }
        }

        return [
            'movers' => $this->pool
                ->filter(fn (LeaderboardRow $row): bool => round($row->change30d) >= 1)
                ->sortBy([
                    fn (LeaderboardRow $a, LeaderboardRow $b): int => $b->change30d <=> $a->change30d,
                    fn (LeaderboardRow $a, LeaderboardRow $b): int => $a->position <=> $b->position,
                ])
                ->take(self::PANEL_SIZE)
                ->values(),
            'streaks' => collect($streaks)
                ->sortBy([
                    fn (array $a, array $b): int => $b['wins'] <=> $a['wins'],
                    fn (array $a, array $b): int => $a['row']->position <=> $b['row']->position,
                ])
                ->take(self::PANEL_SIZE)
                ->values(),
            'active' => collect($active)
                ->sortBy([
                    fn (array $a, array $b): int => $b['matches'] <=> $a['matches'],
                    fn (array $a, array $b): int => $a['row']->position <=> $b['row']->position,
                ])
                ->take(self::PANEL_SIZE)
                ->values(),
        ];
    }

    /**
     * When the last rated match in this discipline was played.
     */
    #[Computed]
    public function lastResult(): ?CarbonImmutable
    {
        $value = BadmintonMatch::query()
            ->where('status', MatchStatus::Completed->value)
            ->where('is_rated', true)
            ->where('discipline', $this->selectedDiscipline->value)
            ->max('played_at');

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }

    /**
     * Active players for the comparison pickers.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function players(): Collection
    {
        return User::query()->active()->orderBy('name')->get(['id', 'name']);
    }

    /**
     * "Season 2026–27": the club season starts on 1 September.
     */
    #[Computed]
    public function season(): string
    {
        $now = now();
        $start = $now->month >= 9 ? $now->year : $now->year - 1;

        return sprintf('Season %d–%02d', $start, ($start + 1) % 100);
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return trim($this->search) !== '' || $this->selectedProgramme !== null || $this->minimum !== 1 || ! $this->provisional;
    }

    public function provisionalThreshold(): int
    {
        return config()->integer('smash.rating.provisional_matches', 10);
    }
}; ?>

@php
    $d = $this->selectedDiscipline;
    $lowerDiscipline = mb_strtolower($d->label());
    $me = auth()->user();
    $lastResult = $this->lastResult;
    $highlights = $this->highlights;
@endphp

<div>
    <x-ui.page-hero
        :breadcrumbs="['Rankings']"
        :kicker="$this->season"
        title="Rankings"
        :intro="__('Singles and doubles have separate ELO ratings, updated after every rated match. Everyone starts at :initial; beat stronger players to climb faster. Your first :n matches are provisional, so your rating settles quickly.', ['initial' => config('smash.rating.initial'), 'n' => $this->provisionalThreshold()])"
        court
    >
        <x-slot:meta>
            <x-ui.meta icon="user-group" class="me-4">{{ trans_choice(':n ranked player in :d|:n ranked players in :d', $this->pool->count(), ['n' => $this->pool->count(), 'd' => $lowerDiscipline]) }}</x-ui.meta>
            @if ($lastResult)
                <x-ui.meta icon="clock">
                    {{ __('Last rated result :when', ['when' => match (true) {
                        $lastResult->isToday() => __('today at :time', ['time' => $lastResult->format('H:i')]),
                        $lastResult->isYesterday() => __('yesterday'),
                        default => $lastResult->format($lastResult->isCurrentYear() ? 'D j M' : 'D j M Y'),
                    }]) }}
                </x-ui.meta>
            @endif
        </x-slot:meta>
        <x-slot:actions>
            <x-ui.button variant="primary" icon="arrows-right-left" :href="route('head-to-head')">{{ __('Head-to-head') }}</x-ui.button>
            <x-ui.link :href="route('how-it-works')" variant="promo">{{ __('How the ratings work') }}</x-ui.link>
        </x-slot:actions>
    </x-ui.page-hero>

    {{-- Discipline + the top three ------------------------------------------------------------ --}}
    <x-ui.section spacing="none" class="pt-6 pb-12 lg:pt-8 lg:pb-16" aria-labelledby="top-three-heading">
        <x-ui.tabs :label="__('Discipline')" class="mb-8">
            @foreach (Discipline::cases() as $tab)
                <x-ui.tabs.item
                    wire:click="$set('discipline', '{{ $tab->value }}')"
                    :current="$tab === $d"
                    :count="$this->counts[$tab->value]"
                    :icon="$tab->icon()"
                    wire:key="tab-{{ $tab->value }}"
                >{{ $tab->label() }}</x-ui.tabs.item>
            @endforeach
        </x-ui.tabs>

        <h2 id="top-three-heading" class="sr-only">{{ __('Top three in :d', ['d' => $lowerDiscipline]) }}</h2>

        @if ($this->pool->isEmpty())
            <x-ui.empty :title="$this->hasFilters ? __('Nobody qualifies yet') : __('No one is ranked in :d yet', ['d' => $lowerDiscipline])">
                @if ($this->hasFilters)
                    {{ __('No active player has :n or more rated :d matches yet. Lower the minimum to see the full ranking.', ['n' => $this->minimum, 'd' => $lowerDiscipline]) }}
                @else
                    {{ __('The ranking fills up after the first rated :d match. Play one at a club night and log the score.', ['d' => $lowerDiscipline]) }}
                @endif
                <x-slot:actions>
                    @if ($this->hasFilters)
                        <x-ui.button variant="outline" wire:click="clearFilters">{{ __('Show everyone') }}</x-ui.button>
                    @else
                        @auth
                            <x-ui.button variant="primary" icon="plus" :href="route('matches.create')">{{ __('Log a match') }}</x-ui.button>
                        @endauth
                        <x-ui.button variant="outline" :href="route('club-nights.index')">{{ __('See club nights') }}</x-ui.button>
                    @endif
                </x-slot:actions>
            </x-ui.empty>
        @else
            <x-rankings.podium :rows="$this->pool->take(3)" :discipline="$d" :for="$me" />
        @endif
    </x-ui.section>

    {{-- Full table + side panels --------------------------------------------------------------- --}}
    <x-ui.section tone="grey" id="table" :kicker="__('All ranked players')" :title="__(':d ranking', ['d' => $d->label()])">
        @if ($this->me)
            <x-slot:actions>
                <div class="flex items-center gap-4">
                    <p class="text-sm text-ink-soft">
                        {{ __('You are') }}
                        <span class="num text-[19px] font-extrabold text-ink">#{{ $this->me->position }}</span>
                        <x-ui.movement :value="$this->me->movement" class="ms-1 align-middle" />
                    </p>
                    <x-ui.button variant="outline" size="sm" icon="viewfinder-circle" wire:click="findMe">{{ __('Find me') }}</x-ui.button>
                </div>
            </x-slot:actions>
        @endif

        <div class="grid items-start gap-8 xl:grid-cols-[minmax(0,1fr)_20rem] 2xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="min-w-0">
                <x-ui.toolbar class="mb-5 gap-x-4 gap-y-4">
                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        :label="__('Search')"
                        icon="magnifying-glass"
                        :placeholder="__('Player name')"
                        clearable
                        class="w-full sm:w-56"
                    />
                    <flux:select wire:model.live="programme" :label="__('Programme')" class="w-full sm:w-56">
                        <flux:select.option value="">{{ __('All programmes') }}</flux:select.option>
                        @foreach (Programme::options() as $value => $label)
                            <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:radio.group wire:model.live="min" :label="__('Min. matches')" variant="segmented" class="w-full sm:w-auto">
                        @foreach ([1, 5, 10] as $option)
                            <flux:radio :value="(string) $option" :label="$option.'+'" />
                        @endforeach
                    </flux:radio.group>
                    <div class="flex h-10 items-center max-sm:w-full">
                        <flux:switch wire:model.live="provisional" :label="__('Include provisional')" align="left" />
                    </div>
                </x-ui.toolbar>

                @if ($this->rows->total() === 0)
                    <x-ui.empty variant="bordered" class="bg-surface" :title="$this->pool->isEmpty() ? __('Nobody to show yet') : __('No players match')">
                        @if ($this->pool->isEmpty())
                            {{ __('Once players have rated :d matches that meet these filters, they appear here.', ['d' => $lowerDiscipline]) }}
                        @else
                            {{ __('Nobody in the :d ranking matches those filters. Check the spelling or try another programme.', ['d' => $lowerDiscipline]) }}
                        @endif
                        @if ($this->hasFilters)
                            <x-slot:actions>
                                <x-ui.button variant="outline" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</x-ui.button>
                            </x-slot:actions>
                        @endif
                    </x-ui.empty>
                @else
                    <x-ui.table id="ranking-table">
                        <table
                            x-data
                            x-on:click="
                                const row = $event.target.closest('tr[data-href]');
                                if (! row || $event.target.closest('a, button') || window.getSelection().toString() !== '') return;
                                if ($event.metaKey || $event.ctrlKey) { window.open(row.dataset.href, '_blank'); return; }
                                Livewire.navigate(row.dataset.href);
                            "
                        >
                            <caption class="sr-only">{{ __(':d ranking, :season', ['d' => $d->label(), 'season' => $this->season]) }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col" class="w-24">#</th>
                                    <th scope="col">{{ __('Player') }}</th>
                                    <th scope="col" class="text-end">{{ __('Rating') }}</th>
                                    <th scope="col" class="text-end max-sm:hidden">
                                        <abbr title="{{ __('Rating change in the last 30 days') }}" class="no-underline">{{ __('30 days') }}</abbr>
                                    </th>
                                    <th scope="col" class="text-end max-md:hidden">{{ __('W–L') }}</th>
                                    <th scope="col" class="text-end max-lg:hidden">{{ __('Win %') }}</th>
                                    <th scope="col" class="max-md:hidden">{{ __('Form') }}</th>
                                    <th scope="col" class="text-end max-lg:hidden">{{ __('Peak') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->rows as $row)
                                    <tr
                                        wire:key="row-{{ $d->value }}-{{ $row->user->id }}"
                                        id="player-{{ $row->user->id }}"
                                        data-href="{{ route('players.show', $row->user) }}"
                                        class="cursor-pointer"
                                        @if ($me && $row->user->is($me)) data-current @endif
                                    >
                                        <td class="whitespace-nowrap">
                                            <span class="inline-flex items-center gap-2">
                                                <x-smash.rank :position="$row->position" size="sm" />
                                                <x-ui.movement :value="$row->movement" class="max-sm:hidden" />
                                            </span>
                                        </td>
                                        <td>
                                            {{-- Names may wrap on phones so the table fits without scrolling. --}}
                                            <x-smash.player :user="$row->user" size="sm" class="max-sm:[&_.truncate]:whitespace-normal" />
                                            <x-smash.form :results="$row->form" size="sm" class="mt-2 ms-11 md:hidden" />
                                        </td>
                                        <td class="text-end">
                                            <x-smash.rating :value="$row->rating" :provisional="$row->provisional" size="sm" />
                                            <div class="mt-1.5 sm:hidden">
                                                <x-smash.rating-delta :value="$row->change30d" size="sm" :label="__('Last 30 days')" />
                                            </div>
                                        </td>
                                        <td class="text-end max-sm:hidden">
                                            <x-smash.rating-delta :value="$row->change30d" size="sm" :label="__('Last 30 days')" />
                                        </td>
                                        <td class="num text-end text-[17px] font-semibold whitespace-nowrap text-ink max-md:hidden">
                                            <span class="sr-only">{{ __(':w wins, :l losses', ['w' => $row->wins, 'l' => $row->losses]) }}</span>
                                            <span aria-hidden="true">{{ $row->wins }}<span class="text-ink-muted">–</span>{{ $row->losses }}</span>
                                        </td>
                                        <td class="num text-end text-[17px] font-semibold text-ink max-lg:hidden">
                                            {{ $row->winRate === null ? '–' : (int) round($row->winRate * 100).'%' }}
                                        </td>
                                        <td class="max-md:hidden">
                                            <x-smash.form :results="$row->form" size="sm" />
                                        </td>
                                        <td class="num text-end text-[17px] font-semibold text-ink-soft max-lg:hidden">{{ $row->peak }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        @if ($this->rows->hasPages())
                            <x-slot:footer>
                                <flux:pagination :paginator="$this->rows" scroll-to="#table" />
                            </x-slot:footer>
                        @endif
                    </x-ui.table>

                    <p class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-ink-soft">
                        <span class="inline-flex items-center gap-1.5">
                            <span class="inline-flex h-3.5 min-w-3.5 items-center justify-center border border-orange-500 px-0.5 text-[8px] font-bold leading-none text-orange-700 dark:border-orange-400 dark:text-orange-300" aria-hidden="true">P</span>
                            {{ __('Provisional: fewer than :n rated matches', ['n' => $this->provisionalThreshold()]) }}
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <x-ui.movement :value="2" />
                            {{ __('Places moved in 30 days') }}
                        </span>
                    </p>
                @endif
            </div>

            {{-- Side panels ------------------------------------------------------------------------ --}}
            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-1">
                <x-rankings.panel :title="__('Biggest movers')" :kicker="__('Last 30 days')" icon="arrow-trending-up" :empty="__('Nobody has gained rating in the last 30 days. A good week at club night changes that.')">
                    @if ($highlights['movers']->isNotEmpty())
                        <ol class="divide-y divide-line">
                            @foreach ($highlights['movers'] as $row)
                                <li class="flex items-center justify-between gap-3 px-5 py-3" wire:key="mover-{{ $row->user->id }}">
                                    <x-smash.player :user="$row->user" size="sm" :meta="__('#:n · :rating', ['n' => $row->position, 'rating' => $row->rating])" />
                                    <x-smash.rating-delta :value="$row->change30d" :label="__('Gained in 30 days')" />
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </x-rankings.panel>

                <x-rankings.panel :title="__('Hottest streaks')" :kicker="__('Wins in a row')" icon="fire" :empty="__('No one is on a winning run right now. Two wins in a row gets you on this list.')">
                    @if ($highlights['streaks']->isNotEmpty())
                        <ol class="divide-y divide-line">
                            @foreach ($highlights['streaks'] as $item)
                                <li class="flex items-center justify-between gap-3 px-5 py-3" wire:key="streak-{{ $item['row']->user->id }}">
                                    <x-smash.player :user="$item['row']->user" size="sm" :meta="__('#:n · :rating', ['n' => $item['row']->position, 'rating' => $item['row']->rating])" />
                                    <span class="flex shrink-0 items-baseline gap-1.5">
                                        <span @class([
                                            'num text-[26px] font-extrabold leading-none',
                                            'text-smash-500' => $item['wins'] >= 5,
                                            'text-ink' => $item['wins'] < 5,
                                        ])>{{ $item['wins'] }}</span>
                                        <span class="label-caps text-ink-muted">{{ trans_choice('win|wins', $item['wins']) }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </x-rankings.panel>

                <x-rankings.panel :title="__('Most active')" :kicker="__('Last 30 days')" icon="calendar-days" :empty="__('No rated :d matches in the last 30 days.', ['d' => $lowerDiscipline])">
                    @if ($highlights['active']->isNotEmpty())
                        <ol class="divide-y divide-line">
                            @foreach ($highlights['active'] as $item)
                                <li class="flex items-center justify-between gap-3 px-5 py-3" wire:key="active-{{ $item['row']->user->id }}">
                                    <x-smash.player :user="$item['row']->user" size="sm" :meta="__('#:n · :rating', ['n' => $item['row']->position, 'rating' => $item['row']->rating])" />
                                    <span class="flex shrink-0 items-baseline gap-1.5">
                                        <span class="num text-[22px] font-bold leading-none text-ink">{{ $item['matches'] }}</span>
                                        <span class="label-caps text-ink-muted">{{ trans_choice('match|matches', $item['matches']) }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </x-rankings.panel>

                {{-- Compare two players → head-to-head --}}
                <x-ui.card variant="navy" padding="md" as="section" aria-labelledby="compare-heading">
                    <p class="kicker">{{ __('Head-to-head') }}</p>
                    <h3 id="compare-heading" class="mt-1.5 font-serif text-h4 text-heading">{{ __('Compare two players') }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-ink-soft">{{ __('Every meeting, who leads, and who the predictor fancies next time.') }}</p>

                    <form wire:submit="compare" class="mt-5 space-y-4">
                        <flux:select wire:model="compareA" :label="__('Player')" :placeholder="__('Choose a player')">
                            @foreach ($this->players as $player)
                                <flux:select.option :value="(string) $player->id" wire:key="ca-{{ $player->id }}">{{ $player->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model="compareB" :label="__('Against')" :placeholder="__('Choose an opponent')">
                            @foreach ($this->players as $player)
                                <flux:select.option :value="(string) $player->id" wire:key="cb-{{ $player->id }}">{{ $player->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <x-ui.button type="submit" variant="pill-orange" icon-trailing="arrow-right" block>{{ __('Compare') }}</x-ui.button>
                    </form>
                </x-ui.card>
            </div>
        </div>
    </x-ui.section>
</div>
