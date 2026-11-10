<?php

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\TournamentStatus;
use App\Models\BadmintonMatch;
use App\Models\ClubNight;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Site search (public): players by name, tournaments by name, club nights by title and matches by
 * player names. "All" shows a few of each kind; picking a kind shows all of them, paginated.
 * Player queries never select (or search) email addresses.
 */
new #[Title('Search')] class extends Component {
    use WithPagination;

    /**
     * Result kinds, in display order => [label, results in the "All" overview].
     */
    private const TYPES = [
        'players' => ['Players', 6],
        'tournaments' => ['Tournaments', 4],
        'club-nights' => ['Club nights', 4],
        'matches' => ['Matches', 6],
    ];

    private const MIN_LENGTH = 2;

    private const PER_PAGE = 12;

    /**
     * Player columns we show. Never the email address.
     */
    private const PLAYER_COLUMNS = [
        'id', 'name', 'role', 'programme', 'level', 'is_active',
        'singles_rating', 'doubles_rating', 'singles_played', 'doubles_played',
    ];

    #[Url(as: 'q', except: '')]
    public string $q = '';

    #[Url(except: '')]
    public string $type = '';

    public function mount(): void
    {
        $this->q = Str::limit((string) $this->q, 80, '');
        $this->type = array_key_exists($this->type, self::TYPES) ? $this->type : '';
    }

    /**
     * $type is public (it lives in the URL), so a client can set it to anything: keep it to known kinds.
     */
    public function updatedType(): void
    {
        $this->type = array_key_exists($this->type, self::TYPES) ? $this->type : '';
        $this->resetPage();
    }

    public function updatedQ(): void
    {
        $this->q = Str::limit($this->q, 80, '');
        $this->resetPage();
    }

    /**
     * Switch between "All" ('') and one kind of result.
     */
    public function show(string $type = ''): void
    {
        $this->type = array_key_exists($type, self::TYPES) ? $type : '';
        $this->resetPage();
    }

    public function clear(): void
    {
        $this->reset('q', 'type');
        $this->resetPage();
    }

    /**
     * The search as typed, whitespace squashed and LIKE wildcards removed.
     */
    #[Computed]
    public function term(): string
    {
        return Str::of($this->q)->replace(['%', '_'], ' ')->squish()->limit(80, '')->toString();
    }

    #[Computed]
    public function searching(): bool
    {
        return mb_strlen($this->term) >= self::MIN_LENGTH;
    }

    /**
     * Every word must match (so "sam vries" finds "Sam de Vries").
     *
     * @return list<string>
     */
    #[Computed]
    public function words(): array
    {
        return array_slice(array_values(array_filter(explode(' ', $this->term), fn (string $word): bool => $word !== '')), 0, 6);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    #[Computed]
    public function types(): array
    {
        return self::TYPES;
    }

    /**
     * Number of results per kind.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        if (! $this->searching) {
            return [];
        }

        $counts = [];

        foreach (array_keys(self::TYPES) as $type) {
            $counts[$type] = $this->query($type)->count();
        }

        return $counts;
    }

    #[Computed]
    public function total(): int
    {
        return array_sum($this->counts);
    }

    /**
     * The first few results of every kind ("All").
     *
     * @return array<string, EloquentCollection<int, \Illuminate\Database\Eloquent\Model>>
     */
    #[Computed]
    public function previews(): array
    {
        if (! $this->searching || $this->type !== '') {
            return [];
        }

        $previews = [];

        foreach (self::TYPES as $type => [, $limit]) {
            $previews[$type] = ($this->counts[$type] ?? 0) > 0
                ? $this->query($type)->limit($limit)->get()
                : new EloquentCollection;
        }

        return $previews;
    }

    /**
     * All results of the chosen kind, paginated.
     */
    #[Computed]
    public function results(): ?LengthAwarePaginator
    {
        if (! $this->searching || $this->type === '') {
            return null;
        }

        return $this->query($this->type)->paginate(self::PER_PAGE);
    }

    /**
     * Starting points before anything is typed: the best players, the next club night and the
     * tournaments people are looking for.
     *
     * @return array{players: EloquentCollection<int, User>, night: ClubNight|null, tournaments: EloquentCollection<int, Tournament>}
     */
    #[Computed]
    public function popular(): array
    {
        return [
            'players' => User::query()->ranked(Discipline::Singles)->limit(6)->get(self::PLAYER_COLUMNS),
            'night' => ClubNight::query()->upcoming()->first(),
            'tournaments' => Tournament::query()
                ->whereIn('status', [TournamentStatus::Open->value, TournamentStatus::InProgress->value])
                ->orderBy('starts_at')
                ->limit(3)
                ->get(),
        ];
    }

    /**
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    private function query(string $type): Builder
    {
        $words = $this->words;

        /** @var Builder<\Illuminate\Database\Eloquent\Model> $query */
        $query = match ($type) {
            'players' => User::query()
                ->select(self::PLAYER_COLUMNS)
                ->where(function (Builder $query) use ($words): void {
                    foreach ($words as $word) {
                        $query->whereLike('name', '%'.$word.'%');
                    }
                })
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->orderBy('id'),

            'tournaments' => Tournament::query()
                ->visibleTo(auth()->user())
                ->withCount('entries')
                ->where(function (Builder $query) use ($words): void {
                    foreach ($words as $word) {
                        $query->whereLike('name', '%'.$word.'%');
                    }
                })
                ->orderByDesc('starts_at')
                ->orderByDesc('id'),

            'club-nights' => ClubNight::query()
                ->withRsvpCounts()
                ->where(function (Builder $query) use ($words): void {
                    foreach ($words as $word) {
                        $query->whereLike('title', '%'.$word.'%');
                    }
                })
                // Coming up first (soonest first), then the most recent past nights.
                ->orderByRaw('case when ends_at >= ? then 0 else 1 end', [now()])
                ->orderByRaw('case when ends_at >= ? then starts_at end asc', [now()])
                ->orderByDesc('starts_at')
                ->orderByDesc('id'),

            default => BadmintonMatch::query()
                ->withDetails()
                ->where(function (Builder $query) use ($words): void {
                    // Each word names somebody in the match: "lotte wei" finds their meetings.
                    foreach ($words as $word) {
                        $query->whereHas('participants.user', fn (Builder $users) => $users->whereLike('name', '%'.$word.'%'));
                    }
                })
                ->orderByRaw('case when status = ? then 0 else 1 end', [MatchStatus::InProgress->value])
                ->orderByRaw('coalesce(played_at, scheduled_at, created_at) desc')
                ->orderByDesc('id'),
        };

        return $query;
    }
}; ?>

<div>
    @php
        $searching = $this->searching;
        $counts = $this->counts;
        $total = $this->total;
        $term = $this->term;
    @endphp

    <x-ui.page-hero
        :breadcrumbs="[__('Search')]"
        :kicker="__('Search SMASH')"
        :title="__('Search')"
        :intro="__('Find players, tournaments, club nights and matches. Search a name to see everyone\'s matches against each other.')"
    >
        <form role="search" wire:submit="$refresh" class="max-w-2xl" data-search-form>
            <flux:input
                wire:model.live.debounce.300ms="q"
                type="search"
                icon="magnifying-glass"
                :placeholder="__('Try a name, “Halloween” or “clinic”')"
                :aria-label="__('Search the club')"
                autocomplete="off"
                autofocus
                maxlength="80"
                class:input="h-13! text-[17px]! bg-surface!"
            />
        </form>
        <p class="mt-3 text-sm text-ink-soft" aria-live="polite">
            @if ($searching)
                {{ trans_choice(':count result for “:term”|:count results for “:term”', $total, ['term' => $term]) }}
            @elseif (mb_strlen($term) === 1)
                {{ __('Keep typing: searches start at two characters.') }}
            @else
                {{ __('Results appear as you type.') }}
            @endif
        </p>
    </x-ui.page-hero>

    @if (! $searching)
        {{-- =========================================================== Before searching --}}
        @php $popular = $this->popular; @endphp
        <x-ui.section :kicker="__('Popular')" :title="__('Go straight to')">
            <div class="grid gap-x-16 gap-y-12 lg:grid-cols-2">
                <ul class="space-y-3" role="list" data-search-popular>
                    <li><x-ui.link variant="serif" :href="route('rankings.index')">{{ __('Singles and doubles rankings') }}</x-ui.link></li>
                    @if ($popular['night'])
                        <li>
                            <x-ui.link variant="serif" :href="route('club-nights.show', $popular['night'])">
                                {{ __('Next club night: :title, :date', ['title' => $popular['night']->title, 'date' => $popular['night']->starts_at->format('D j M')]) }}
                            </x-ui.link>
                        </li>
                    @else
                        <li><x-ui.link variant="serif" :href="route('club-nights.index')">{{ __('Club nights') }}</x-ui.link></li>
                    @endif
                    @forelse ($popular['tournaments'] as $tournament)
                        <li wire:key="popular-t-{{ $tournament->id }}">
                            <x-ui.link variant="serif" :href="route('tournaments.show', $tournament)">
                                {{ $tournament->name }}<span class="text-ink-muted"> · {{ $tournament->status->label() }}</span>
                            </x-ui.link>
                        </li>
                    @empty
                        <li><x-ui.link variant="serif" :href="route('tournaments.index')">{{ __('Tournaments') }}</x-ui.link></li>
                    @endforelse
                    <li><x-ui.link variant="serif" :href="route('matches.index')">{{ __('Results and fixtures') }}</x-ui.link></li>
                    <li><x-ui.link variant="serif" :href="route('predictor')">{{ __('Who wins? The match predictor') }}</x-ui.link></li>
                    <li><x-ui.link variant="serif" :href="route('how-it-works')">{{ __('How ratings and predictions work') }}</x-ui.link></li>
                </ul>

                <div class="min-w-0">
                    <p class="label-caps text-ink-muted">{{ __('Top of the singles rankings') }}</p>
                    @if ($popular['players']->isEmpty())
                        <p class="mt-4 text-[15px] text-ink-soft">{{ __('Nobody is ranked yet. Rankings start after the first rated match.') }}</p>
                    @else
                        <ul class="mt-4 grid gap-x-6 gap-y-4 sm:grid-cols-2" role="list">
                            @foreach ($popular['players'] as $player)
                                <li class="min-w-0" wire:key="popular-p-{{ $player->id }}"><x-smash.player :user="$player" size="sm" rating /></li>
                            @endforeach
                        </ul>
                    @endif
                    <x-ui.link class="mt-6" :href="route('players.index')">{{ __('All players') }}</x-ui.link>
                </div>
            </div>
        </x-ui.section>
    @elseif ($total === 0)
        {{-- =========================================================== Nothing found --}}
        <x-ui.section>
            <x-ui.empty :title="__('Nothing found for “:term”', ['term' => $term])" icon="magnifying-glass" data-search-empty>
                {{ __('Check the spelling, or search for just a first or last name. Club nights are found by title, like “clinic” or “ladder”.') }}
                <x-slot:actions>
                    <x-ui.button variant="outline" size="sm" wire:click="clear">{{ __('Clear search') }}</x-ui.button>
                    <x-ui.button variant="ghost" size="sm" :href="route('players.index')">{{ __('Browse players') }}</x-ui.button>
                </x-slot:actions>
            </x-ui.empty>
        </x-ui.section>
    @else
        {{-- =========================================================== Results --}}
        <x-ui.section spacing="md">
            <x-ui.tabs :label="__('Result types')" class="mb-10">
                <x-ui.tabs.item wire:click="show('')" :current="$type === ''" :count="$total">{{ __('All') }}</x-ui.tabs.item>
                @foreach ($this->types as $key => [$label])
                    <x-ui.tabs.item wire:click="show('{{ $key }}')" :current="$type === $key" :count="$counts[$key] ?? 0" wire:key="tab-{{ $key }}">{{ __($label) }}</x-ui.tabs.item>
                @endforeach
            </x-ui.tabs>

            <div class="space-y-14" wire:loading.class="opacity-60" wire:target="q, show">
                @foreach ($this->types as $key => [$label, $limit])
                    @php
                        $items = $type === '' ? ($this->previews[$key] ?? collect()) : ($type === $key ? $this->results : null);
                        $count = $counts[$key] ?? 0;
                    @endphp

                    @if ($items !== null && $count > 0)
                        <section aria-labelledby="results-{{ $key }}" wire:key="group-{{ $key }}" data-search-group="{{ $key }}">
                            <div class="mb-5 flex flex-wrap items-end justify-between gap-x-6 gap-y-3 border-b border-line pb-3">
                                <h2 id="results-{{ $key }}" class="font-serif text-h3 font-normal text-heading">
                                    {{ __($label) }} <span class="num ms-1 text-[0.8em] font-bold text-ink-muted">{{ $count }}</span>
                                </h2>
                                @if ($type === '' && $count > $limit)
                                    <x-ui.button variant="link" wire:click="show('{{ $key }}')">{{ __('Show all :count', ['count' => $count]) }}</x-ui.button>
                                @endif
                            </div>

                            @switch($key)
                                @case('players')
                                    <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" role="list">
                                        @foreach ($items as $player)
                                            <li wire:key="player-{{ $player->id }}" class="flex min-w-0 items-center justify-between gap-4 border border-line bg-surface px-4 py-3">
                                                <x-smash.player :user="$player" size="sm" class="min-w-0" />
                                                <div class="flex shrink-0 items-center gap-3">
                                                    @if (! $player->is_active)
                                                        <x-ui.tag tone="grey" size="sm">{{ __('Inactive') }}</x-ui.tag>
                                                    @endif
                                                    <dl class="flex gap-3 text-end">
                                                        <div>
                                                            <dt class="label-caps text-[10px] text-ink-muted">{{ __('Singles') }}</dt>
                                                            <dd><x-smash.rating :user="$player" discipline="singles" size="xs" /></dd>
                                                        </div>
                                                        <div>
                                                            <dt class="label-caps text-[10px] text-ink-muted">{{ __('Doubles') }}</dt>
                                                            <dd><x-smash.rating :user="$player" discipline="doubles" size="xs" /></dd>
                                                        </div>
                                                    </dl>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                    @break

                                @case('tournaments')
                                    <ul class="divide-y divide-line border-y border-line" role="list">
                                        @foreach ($items as $tournament)
                                            <li wire:key="tournament-{{ $tournament->id }}">
                                                <a href="{{ route('tournaments.show', $tournament) }}" wire:navigate class="group/row flex flex-wrap items-center justify-between gap-x-6 gap-y-2 px-1 py-4 transition-colors hover:bg-surface-muted sm:px-3">
                                                    <span class="min-w-0">
                                                        <span class="kicker block">{{ $tournament->discipline->label() }} · {{ $tournament->format->label() }}</span>
                                                        <span class="mt-1 block font-serif text-[19px] leading-snug text-heading group-hover/row:underline group-hover/row:decoration-1 group-hover/row:underline-offset-4">{{ $tournament->name }}</span>
                                                        <span class="mt-1 block text-sm text-ink-muted">{{ $tournament->starts_at->format('l j F Y') }} · {{ trans_choice(':count entry|:count entries', $tournament->entries_count ?? 0) }}</span>
                                                    </span>
                                                    <x-smash.status-badge :status="$tournament->status" size="sm" />
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                    @break

                                @case('club-nights')
                                    <ul class="divide-y divide-line border-y border-line" role="list">
                                        @foreach ($items as $night)
                                            <li wire:key="night-{{ $night->id }}">
                                                <a href="{{ route('club-nights.show', $night) }}" wire:navigate class="group/row flex items-center gap-5 px-1 py-4 transition-colors hover:bg-surface-muted sm:px-3">
                                                    <x-ui.date-badge :date="$night->starts_at" size="sm" />
                                                    <span class="min-w-0 flex-1">
                                                        <span class="block font-serif text-[19px] leading-snug text-heading group-hover/row:underline group-hover/row:decoration-1 group-hover/row:underline-offset-4">{{ $night->title }}</span>
                                                        <span class="mt-1 block text-sm text-ink-muted">
                                                            {{ $night->starts_at->format('D j M Y · H:i') }} – {{ $night->ends_at->format('H:i') }}
                                                            · {{ $night->isPast() ? trans_choice(':count player went|:count players went', $night->goingCount()) : ($night->isFull() ? __('Full') : trans_choice(':count place left|:count places left', $night->spotsLeft())) }}
                                                        </span>
                                                    </span>
                                                    <x-smash.status-badge :status="$night->kind" size="sm" class="max-sm:hidden" />
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                    @break

                                @default
                                    <div class="grid gap-4 lg:grid-cols-2">
                                        @foreach ($items as $match)
                                            <x-smash.match-card :match="$match" variant="compact" :for="auth()->user()" wire:key="match-{{ $match->id }}" />
                                        @endforeach
                                    </div>
                            @endswitch

                            @if ($type === $key && $this->results?->hasPages())
                                <div class="mt-8">
                                    <flux:pagination :paginator="$this->results" />
                                </div>
                            @endif
                        </section>
                    @endif
                @endforeach

                @if ($type !== '' && ($counts[$type] ?? 0) === 0)
                    <x-ui.empty :title="__('No :type match “:term”', ['type' => mb_strtolower(__($this->types[$type][0])), 'term' => $term])" variant="bordered">
                        <x-slot:actions>
                            <x-ui.button variant="outline" size="sm" wire:click="show('')">{{ __('Show all results') }}</x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty>
                @endif
            </div>
        </x-ui.section>
    @endif
</div>
