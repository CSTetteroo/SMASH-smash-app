<?php

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Models\BadmintonMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Prediction\MatchPrediction;
use App\Services\Prediction\MatchPredictor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Results & fixtures')] class extends Component {
    use WithPagination;

    public const TABS = ['results', 'upcoming', 'live'];

    public const PER_PAGE = 20;

    /** results · upcoming · live */
    #[Url(except: 'results')]
    public string $tab = 'results';

    /** '' · singles · doubles */
    #[Url(except: '')]
    public string $discipline = '';

    /** '' · friendly · tournament */
    #[Url(except: '')]
    public string $type = '';

    /** Player id ('' = everyone). */
    #[Url(except: '')]
    public string $player = '';

    /** Tournament slug ('' = all). */
    #[Url(except: '')]
    public string $tournament = '';

    public function mount(): void
    {
        $this->normalise();
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['tab', 'discipline', 'type', 'player', 'tournament'], true)) {
            return;
        }

        // Picking a tournament means tournament matches; picking friendlies clears the tournament.
        if ($property === 'tournament' && $this->tournament !== '' && $this->type === 'friendly') {
            $this->type = '';
        }

        if ($property === 'type' && $this->type === 'friendly') {
            $this->tournament = '';
        }

        $this->normalise();
        $this->resetPage();
    }

    public function showTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'results';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('discipline', 'type', 'player', 'tournament');
        $this->resetPage();
    }

    /**
     * Unknown values from the query string fall back to "all".
     */
    private function normalise(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'results';
        }

        if (Discipline::tryFrom($this->discipline) === null) {
            $this->discipline = '';
        }

        if (! in_array($this->type, ['friendly', 'tournament'], true)) {
            $this->type = '';
        }

        if ($this->player !== '' && ! ctype_digit($this->player)) {
            $this->player = '';
        }
    }

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    #[Computed]
    public function disciplineFilter(): ?Discipline
    {
        return Discipline::tryFrom($this->discipline);
    }

    #[Computed]
    public function playerFilter(): ?User
    {
        return $this->player === '' ? null : User::query()->find((int) $this->player, ['id', 'name']);
    }

    #[Computed]
    public function tournamentFilter(): ?Tournament
    {
        return $this->tournament === ''
            ? null
            : Tournament::query()->visibleTo(Auth::user())->where('slug', $this->tournament)->first();
    }

    #[Computed]
    public function filtering(): bool
    {
        return $this->discipline !== '' || $this->type !== '' || $this->player !== '' || $this->tournament !== '';
    }

    /**
     * Everyone who has played or is down to play (names only: nothing private in the markup).
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function players(): Collection
    {
        return User::query()
            ->whereHas('matchPlayers')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @return Collection<int, Tournament>
     */
    #[Computed]
    public function tournaments(): Collection
    {
        return Tournament::query()
            ->visibleTo(Auth::user())
            ->has('matches')
            ->orderByDesc('starts_at')
            ->get(['id', 'name', 'slug', 'status']);
    }

    // ------------------------------------------------------------------
    // Matches
    // ------------------------------------------------------------------

    /**
     * Every match the filters allow (drafts' matches stay hidden from non-admins).
     *
     * @return Builder<BadmintonMatch>
     */
    private function filtered(): Builder
    {
        $user = Auth::user();

        return BadmintonMatch::query()
            ->where(fn (Builder $query) => $query
                ->whereNull('matches.tournament_id')
                ->orWhereHas('tournament', fn (Builder $tournaments) => $tournaments->visibleTo($user)))
            ->when($this->disciplineFilter, fn (Builder $query, Discipline $discipline) => $query->forDiscipline($discipline))
            ->when($this->type === 'friendly', fn (Builder $query) => $query->friendly())
            ->when($this->type === 'tournament', fn (Builder $query) => $query->whereNotNull('matches.tournament_id'))
            ->when($this->player !== '', fn (Builder $query) => $query->forPlayer((int) $this->player))
            ->when($this->tournament !== '', fn (Builder $query) => $query->where('matches.tournament_id', $this->tournamentFilter?->id ?? 0));
    }

    /**
     * @param  Builder<BadmintonMatch>  $query
     * @return Builder<BadmintonMatch>
     */
    private function forTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'upcoming' => $query->upcoming(),
            'live' => $query->live()->orderByDesc('matches.updated_at')->orderByDesc('matches.id'),
            default => $query
                ->whereIn('matches.status', [MatchStatus::Completed->value, MatchStatus::Walkover->value, MatchStatus::Cancelled->value])
                // Byes are part of the draw, not results.
                ->whereNot(fn (Builder $byes) => $byes
                    ->whereNotNull('matches.tournament_id')
                    ->where('matches.status', MatchStatus::Walkover->value)
                    ->where(fn (Builder $missing) => $missing->whereNull('matches.entry_a_id')->orWhereNull('matches.entry_b_id')))
                ->orderByRaw('coalesce(matches.played_at, matches.scheduled_at) desc')
                ->orderByDesc('matches.id'),
        };
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return collect(self::TABS)
            ->mapWithKeys(fn (string $tab) => [$tab => $this->forTab($this->filtered(), $tab)->reorder()->count()])
            ->all();
    }

    #[Computed]
    public function matches(): LengthAwarePaginator
    {
        return $this->forTab($this->filtered(), $this->tab)
            ->withDetails()
            ->paginate(self::PER_PAGE);
    }

    /**
     * The page's matches by day: [['key' => '2026-11-03', 'kicker' => 'Today', 'title' => 'Tuesday 3 November', 'matches' => …], …].
     *
     * @return list<array{key: string, kicker: string|null, title: string, matches: \Illuminate\Support\Collection<int, BadmintonMatch>}>
     */
    #[Computed]
    public function days(): array
    {
        $results = $this->tab === 'results';

        return collect($this->matches->items())
            ->groupBy(function (BadmintonMatch $match) use ($results): string {
                $moment = $results ? ($match->played_at ?? $match->scheduled_at) : $match->scheduled_at;

                return $moment?->toDateString() ?? 'tbc';
            })
            ->map(function ($matches, string $key): array {
                if ($key === 'tbc') {
                    return ['key' => $key, 'kicker' => null, 'title' => __('Date to be confirmed'), 'matches' => $matches->values()];
                }

                $day = CarbonImmutable::parse($key);

                return [
                    'key' => $key,
                    'kicker' => match (true) {
                        $day->isToday() => __('Today'),
                        $day->isYesterday() => __('Yesterday'),
                        $day->isTomorrow() => __('Tomorrow'),
                        default => null,
                    },
                    'title' => $day->format($day->isCurrentYear() ? 'l j F' : 'l j F Y'),
                    'matches' => $matches->values(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Win chances for the fixtures on this page whose line-ups are known ([match id => prediction]).
     *
     * @return array<int, MatchPrediction>
     */
    #[Computed]
    public function predictions(): array
    {
        if ($this->tab !== 'upcoming') {
            return [];
        }

        return collect($this->matches->items())
            ->filter(fn (BadmintonMatch $match) => $this->lineUpKnown($match))
            ->mapWithKeys(fn (BadmintonMatch $match) => [$match->id => MatchPredictor::forMatch($match)])
            ->all();
    }

    private function lineUpKnown(BadmintonMatch $match): bool
    {
        $perSide = $match->discipline->playersPerSide();

        return $match->participants->where('side', \App\Enums\Side::A)->count() === $perSide
            && $match->participants->where('side', \App\Enums\Side::B)->count() === $perSide;
    }

    #[Computed]
    public function season(): string
    {
        $now = CarbonImmutable::now();
        $start = $now->month >= 9 ? $now->year : $now->year - 1;

        return __('Season :from–:to', ['from' => $start, 'to' => substr((string) ($start + 1), -2)]);
    }
}; ?>

<div>
    <x-ui.page-hero
        :breadcrumbs="[__('Matches')]"
        :kicker="$this->season"
        :title="__('Results & fixtures')"
        :intro="__('Every friendly and tournament match at SMASH: final scores, who gained rating points, and the challenges still to come.')"
        court
    >
        <x-slot:actions>
            @auth
                <x-ui.button variant="cta" :href="route('matches.create')">{{ __('Log a match') }}</x-ui.button>
            @else
                <x-ui.button variant="outline" icon="arrow-right-end-on-rectangle" :href="route('login')">{{ __('Log in to add your results') }}</x-ui.button>
            @endauth
            <x-ui.button variant="ghost" icon="chart-bar" :href="route('rankings.index')">{{ __('Rankings') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-hero>

    <x-ui.section spacing="md">
        <div class="border-b border-line">
            <x-ui.tabs :label="__('Match lists')" class="-mb-px">
                <x-ui.tabs.item wire:click="showTab('results')" :current="$tab === 'results'" :count="$this->counts['results']" data-test="tab-results">{{ __('Results') }}</x-ui.tabs.item>
                <x-ui.tabs.item wire:click="showTab('upcoming')" :current="$tab === 'upcoming'" :count="$this->counts['upcoming']" data-test="tab-upcoming">{{ __('Upcoming') }}</x-ui.tabs.item>
                <x-ui.tabs.item wire:click="showTab('live')" :current="$tab === 'live'" :count="$this->counts['live']" data-test="tab-live">
                    <span class="inline-flex items-center gap-2">
                        @if ($this->counts['live'] > 0)
                            <span class="relative inline-block size-2 rounded-full bg-smash-500 animate-live" aria-hidden="true"></span>
                        @endif
                        {{ __('Live') }}
                    </span>
                </x-ui.tabs.item>
            </x-ui.tabs>
        </div>

        <div class="mt-6 flex flex-col items-start gap-3 sm:flex-row sm:flex-wrap sm:items-end" data-test="filters">
            <div class="grid w-full grid-cols-2 gap-3 sm:flex sm:w-auto sm:flex-wrap sm:items-end">
                <div class="min-w-0 sm:w-40">
                    <flux:select wire:model.live="discipline" :label="__('Discipline')">
                        <option value="">{{ __('All') }}</option>
                        @foreach (Discipline::cases() as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="min-w-0 sm:w-40">
                    <flux:select wire:model.live="type" :label="__('Type')">
                        <option value="">{{ __('All matches') }}</option>
                        <option value="friendly">{{ __('Friendlies') }}</option>
                        <option value="tournament">{{ __('Tournament') }}</option>
                    </flux:select>
                </div>

                <div class="min-w-0 sm:w-56">
                    <flux:select wire:model.live="player" :label="__('Player')">
                        <option value="">{{ __('Everyone') }}</option>
                        @foreach ($this->players as $person)
                            <option value="{{ $person->id }}">{{ $person->name }}</option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="min-w-0 sm:w-56">
                    <flux:select wire:model.live="tournament" :label="__('Tournament')">
                        <option value="">{{ __('All tournaments') }}</option>
                        @foreach ($this->tournaments as $option)
                            <option value="{{ $option->slug }}">{{ $option->name }}</option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            @if ($this->filtering)
                <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters" class="sm:ms-auto" data-test="clear-filters">{{ __('Clear filters') }}</x-ui.button>
            @endif
        </div>

        <div
            class="mt-10"
            @if ($tab === 'live') wire:poll.10s.visible @endif
            data-test="match-list"
        >
            @forelse ($this->days as $day)
                <section @class(['mt-12' => ! $loop->first]) wire:key="day-{{ $tab }}-{{ $day['key'] }}" aria-labelledby="day-{{ $day['key'] }}">
                    <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-1 border-b border-line pb-3">
                        <div>
                            @if ($day['kicker'])
                                <p class="kicker mb-1">{{ $day['kicker'] }}</p>
                            @endif
                            <h2 id="day-{{ $day['key'] }}" class="font-serif text-h4 font-normal text-heading">{{ $day['title'] }}</h2>
                        </div>
                        <p class="label-caps text-ink-muted">{{ trans_choice(':count match|:count matches', $day['matches']->count(), ['count' => $day['matches']->count()]) }}</p>
                    </div>

                    <div class="mt-5 grid gap-4 lg:grid-cols-2">
                        @foreach ($day['matches'] as $match)
                            <x-smash.match-card
                                :match="$match"
                                :for="auth()->user()"
                                :probability="$this->predictions[$match->id] ?? null"
                                wire:key="m-{{ $match->id }}"
                            />
                        @endforeach
                    </div>
                </section>
            @empty
                @if ($this->filtering)
                    <x-ui.empty :title="__('No matches fit these filters')" data-test="empty-filtered">
                        {{ __('Try another player or discipline, or clear the filters to see everything.') }}
                        <x-slot:actions>
                            <x-ui.button variant="outline" size="sm" wire:click="clearFilters">{{ __('Clear filters') }}</x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty>
                @elseif ($tab === 'live')
                    <x-ui.empty :title="__('Nobody is on court right now')" data-test="empty-live">
                        {{ __('Matches show up here the moment someone starts scoring them. This list refreshes by itself.') }}
                    </x-ui.empty>
                @elseif ($tab === 'upcoming')
                    <x-ui.empty :title="__('No fixtures planned yet')" data-test="empty-upcoming">
                        {{ __('Challenged someone for the next club night? Add it as a scheduled match so everyone can make their pick.') }}
                        @auth
                            <x-slot:actions>
                                <x-ui.button variant="outline" size="sm" icon="plus" :href="route('matches.create')">{{ __('Schedule a match') }}</x-ui.button>
                            </x-slot:actions>
                        @endauth
                    </x-ui.empty>
                @else
                    <x-ui.empty :title="__('No results yet')" data-test="empty-results">
                        {{ __('The first results of the season appear here as soon as they are logged.') }}
                    </x-ui.empty>
                @endif
            @endforelse

            @if ($this->matches->hasPages())
                <div class="mt-10">
                    <flux:pagination :paginator="$this->matches" />
                </div>
            @endif
        </div>
    </x-ui.section>
</div>
