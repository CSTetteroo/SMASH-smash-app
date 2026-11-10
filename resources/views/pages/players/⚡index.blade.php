<?php

use App\Enums\Programme;
use App\Enums\SkillLevel;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Players')] class extends Component {
    use WithPagination;

    public const PER_PAGE = 24;

    /** Members who joined in the last N days get a "New" marker. */
    public const NEW_DAYS = 30;

    public const SORTS = [
        'name' => 'Name (A–Z)',
        'singles' => 'Singles rating',
        'doubles' => 'Doubles rating',
        'newest' => 'Newest members',
    ];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $programme = '';

    #[Url(except: '')]
    public string $level = '';

    #[Url(except: 'name')]
    public string $sort = 'name';

    /** Admins only: include inactive members (ignored for everybody else). */
    #[Url(as: 'inactive', except: false)]
    public bool $showInactive = false;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'programme', 'level', 'sort', 'showInactive'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'programme', 'level');
        $this->resetPage();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Gate::allows('manage');
    }

    /**
     * Inactive members are only listed for admins who asked for them.
     */
    #[Computed]
    public function includesInactive(): bool
    {
        return $this->showInactive && $this->canManage;
    }

    #[Computed]
    public function programmeFilter(): ?Programme
    {
        return Programme::tryFrom($this->programme);
    }

    #[Computed]
    public function levelFilter(): ?SkillLevel
    {
        return SkillLevel::tryFrom($this->level);
    }

    #[Computed]
    public function sortKey(): string
    {
        return array_key_exists($this->sort, self::SORTS) ? $this->sort : 'name';
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return $this->searchTerm() !== '' || $this->programmeFilter !== null || $this->levelFilter !== null;
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function players(): LengthAwarePaginator
    {
        $query = $this->filtered(withProgramme: true);

        match ($this->sortKey) {
            'singles' => $query->orderByRaw('singles_played = 0')->orderByDesc('singles_rating'),
            'doubles' => $query->orderByRaw('doubles_played = 0')->orderByDesc('doubles_rating'),
            'newest' => $query->orderByDesc('created_at')->orderByDesc('id'),
            default => null,
        };

        return $query->orderBy('name')->orderBy('id')->paginate(self::PER_PAGE, [
            'id', 'name', 'role', 'programme', 'level', 'handedness', 'is_active',
            'singles_rating', 'doubles_rating', 'singles_played', 'doubles_played', 'created_at',
        ]);
    }

    /**
     * Players per programme for the filter chips (respecting the other filters).
     *
     * @return array<string, int>
     */
    #[Computed]
    public function programmeCounts(): array
    {
        return $this->filtered(withProgramme: false)
            ->toBase()
            ->whereNotNull('programme')
            ->groupBy('programme')
            ->selectRaw('programme, count(*) as aggregate')
            ->pluck('aggregate', 'programme')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * Club numbers for the hero: active members, members with a rated match, newcomers.
     *
     * @return array{members: int, rated: int, newcomers: int}
     */
    #[Computed]
    public function summary(): array
    {
        $row = User::query()
            ->active()
            ->toBase()
            ->selectRaw('count(*) as members')
            ->selectRaw('coalesce(sum(case when singles_played + doubles_played > 0 then 1 else 0 end), 0) as rated')
            ->selectRaw('coalesce(sum(case when created_at >= ? then 1 else 0 end), 0) as newcomers', [now()->subDays(self::NEW_DAYS)])
            ->first();

        return [
            'members' => (int) ($row->members ?? 0),
            'rated' => (int) ($row->rated ?? 0),
            'newcomers' => (int) ($row->newcomers ?? 0),
        ];
    }

    /**
     * @return Builder<User>
     */
    private function filtered(bool $withProgramme): Builder
    {
        $term = $this->searchTerm();

        return User::query()
            ->when(! $this->includesInactive, fn (Builder $query) => $query->active())
            ->when($term !== '', fn (Builder $query) => $query->where('name', 'like', '%'.$term.'%'))
            ->when($withProgramme && $this->programmeFilter !== null, fn (Builder $query) => $query->where('programme', $this->programmeFilter?->value))
            ->when($this->levelFilter !== null, fn (Builder $query) => $query->where('level', $this->levelFilter?->value));
    }

    private function searchTerm(): string
    {
        return trim(str_replace(['%', '_'], '', mb_substr($this->search, 0, 80)));
    }
}; ?>

<div>
    <x-ui.page-hero
        :breadcrumbs="[__('Players')]"
        :kicker="__('Season 2026–27')"
        :title="__('Players')"
        :intro="__('Everyone who plays at SMASH, from first-timers to league regulars. Find a doubles partner, size up your next opponent or say hi to the newcomers.')"
        court
    >
        <dl class="grid max-w-md grid-cols-3 items-end gap-x-6 gap-y-5 sm:flex sm:max-w-none sm:gap-x-10">
            <div>
                <dt class="label-caps text-ink-muted">{{ __('Members') }}</dt>
                <dd class="num mt-1 text-[2.5rem] font-extrabold leading-none text-heading">{{ $this->summary['members'] }}</dd>
            </div>
            <div>
                <dt class="label-caps text-ink-muted">{{ __('On the ladder') }}</dt>
                <dd class="num mt-1 text-[2.5rem] font-extrabold leading-none text-heading">{{ $this->summary['rated'] }}</dd>
            </div>
            <div>
                <dt class="label-caps text-ink-muted">{{ __('New this month') }}</dt>
                <dd class="num mt-1 text-[2.5rem] font-extrabold leading-none text-heading">{{ $this->summary['newcomers'] }}</dd>
            </div>
        </dl>

        <x-slot:actions>
            @if ($this->canManage)
                <x-ui.button variant="primary" icon="plus" :href="route('players.create')">{{ __('New player') }}</x-ui.button>
            @endif
            <x-ui.button variant="outline" :href="route('rankings.index')">{{ __('Rankings') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-hero>

    <x-ui.section :title="__('Directory')" :kicker="__('Find a player')">
        <x-slot:actions>
            <p class="text-sm text-ink-muted" aria-live="polite">
                {{ trans_choice(':n player|:n players', $this->players->total(), ['n' => $this->players->total()]) }}
            </p>
        </x-slot:actions>

        {{-- Phones: a 2-column grid (search and programme full width, level and sort side by side). --}}
        <x-ui.toolbar class="mb-5 max-sm:grid max-sm:grid-cols-2">
            <div class="col-span-2 min-w-0 sm:w-72">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    :label="__('Search')"
                    icon="magnifying-glass"
                    :placeholder="__('Search by name')"
                    clearable
                    data-test="player-search"
                />
            </div>

            {{-- Phones and tablets get a select; from md up the programme chips below do the job. --}}
            <div class="col-span-2 min-w-0 sm:w-56 md:hidden">
                <flux:select wire:model.live="programme" :label="__('Programme')">
                    <option value="">{{ __('All programmes') }}</option>
                    @foreach (Programme::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </flux:select>
            </div>

            <div class="min-w-0 sm:w-44">
                <flux:select wire:model.live="level" :label="__('Level')">
                    <option value="">{{ __('All levels') }}</option>
                    @foreach (SkillLevel::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </flux:select>
            </div>

            <div class="min-w-0 sm:w-52">
                <flux:select wire:model.live="sort" :label="__('Sort by')">
                    @foreach (self::SORTS as $value => $label)
                        <option value="{{ $value }}">{{ __($label) }}</option>
                    @endforeach
                </flux:select>
            </div>

            @if ($this->canManage)
                <x-slot:actions>
                    <div class="flex min-h-10 items-center max-sm:col-span-2">
                        <flux:switch wire:model.live="showInactive" :label="__('Show inactive members')" align="left" />
                    </div>
                </x-slot:actions>
            @endif
        </x-ui.toolbar>

        <div class="mb-8 flex flex-wrap gap-2 max-md:hidden" role="group" aria-label="{{ __('Filter by programme') }}">
            <x-players.chip wire:click="$set('programme', '')" :active="$this->programmeFilter === null">{{ __('All programmes') }}</x-players.chip>
            @foreach (Programme::cases() as $option)
                <x-players.chip
                    wire:click="$set('programme', '{{ $option->value }}')"
                    :active="$this->programmeFilter === $option"
                    :count="$this->programmeCounts[$option->value] ?? 0"
                    wire:key="chip-{{ $option->value }}"
                >{{ $option->label() }}</x-players.chip>
            @endforeach
        </div>

        <div class="relative" wire:loading.class="opacity-60" wire:target="search, programme, level, sort, showInactive, clearFilters, gotoPage, nextPage, previousPage">
            @if ($this->players->isEmpty())
                @if ($this->hasFilters)
                    <x-ui.empty :title="__('No players match those filters')" icon="magnifying-glass">
                        {{ __('Try another name, or clear the filters to see everyone.') }}
                        <x-slot:actions>
                            <x-ui.button variant="outline" size="sm" wire:click="clearFilters">{{ __('Clear filters') }}</x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty>
                @else
                    <x-ui.empty :title="__('No players yet')">
                        {{ __('As soon as the first members sign up they appear here.') }}
                        @if ($this->canManage)
                            <x-slot:actions>
                                <x-ui.button variant="primary" size="sm" icon="plus" :href="route('players.create')">{{ __('New player') }}</x-ui.button>
                            </x-slot:actions>
                        @endif
                    </x-ui.empty>
                @endif
            @else
                <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4" role="list">
                    @foreach ($this->players as $player)
                        <li class="min-w-0" wire:key="player-{{ $player->id }}">
                            <x-players.card
                                :player="$player"
                                :emphasis="in_array($this->sortKey, ['singles', 'doubles'], true) ? $this->sortKey : null"
                            />
                        </li>
                    @endforeach
                </ul>

                @if ($this->players->hasPages())
                    <div class="mt-8">
                        <flux:pagination :paginator="$this->players" />
                    </div>
                @endif
            @endif
        </div>
    </x-ui.section>
</div>
