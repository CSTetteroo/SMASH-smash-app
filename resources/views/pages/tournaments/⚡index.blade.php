<?php

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Tournaments')] class extends Component {
    use WithPagination;

    /**
     * Tournaments that are not finished yet (drafts only for admins), soonest first.
     * Matches are loaded with just the columns the cards count with.
     *
     * @return Collection<int, Tournament>
     */
    #[Computed]
    public function current(): Collection
    {
        return Tournament::query()
            ->visibleTo(Auth::user())
            ->whereIn('status', [
                TournamentStatus::Draft->value,
                TournamentStatus::Open->value,
                TournamentStatus::InProgress->value,
            ])
            ->withCount('entries')
            ->with(['matches' => fn ($query) => $query->select([
                'id', 'tournament_id', 'round', 'bracket_position', 'status',
                'entry_a_id', 'entry_b_id', 'scheduled_at', 'played_at', 'winning_side',
            ])])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The current tournaments by stage: happening now, open for registration, coming up
     * (registration closed or draw made but not started) and drafts.
     *
     * @return array{now: Collection<int, Tournament>, open: Collection<int, Tournament>, soon: Collection<int, Tournament>, draft: Collection<int, Tournament>}
     */
    #[Computed]
    public function stages(): array
    {
        $stages = ['now' => [], 'open' => [], 'soon' => [], 'draft' => []];

        foreach ($this->current as $tournament) {
            $stages[$this->stageOf($tournament)][] = $tournament;
        }

        $open = new Collection($stages['open']);

        return [
            'now' => new Collection($stages['now']),
            // Closing soonest first.
            'open' => $open->sortBy(fn (Tournament $t) => $t->registrationDeadline()?->getTimestamp() ?? PHP_INT_MAX)->values(),
            'soon' => new Collection($stages['soon']),
            'draft' => new Collection($stages['draft']),
        ];
    }

    /**
     * Completed tournaments, most recent first, with what the cards need for the champion.
     */
    #[Computed]
    public function completed(): LengthAwarePaginator
    {
        return Tournament::query()
            ->visibleTo(Auth::user())
            ->past()
            ->withCount('entries')
            ->with([
                'entries.player',
                'entries.partner',
                'matches.games',
                'matches.entryA.player',
                'matches.entryA.partner',
                'matches.entryB.player',
                'matches.entryB.partner',
            ])
            ->paginate(6, pageName: 'completed');
    }

    /**
     * Ids of the tournaments the viewer plays in (as player or partner).
     *
     * @return list<int>
     */
    #[Computed]
    public function enteredIds(): array
    {
        $user = Auth::user();

        if ($user === null) {
            return [];
        }

        return TournamentEntry::query()
            ->forPlayer($user)
            ->pluck('tournament_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * "Season 2026–27" (seasons start in September).
     */
    #[Computed]
    public function season(): string
    {
        $now = now();
        $start = $now->month >= 9 ? $now->year : $now->year - 1;

        return __('Season :from–:to', ['from' => $start, 'to' => substr((string) ($start + 1), 2)]);
    }

    protected function stageOf(Tournament $tournament): string
    {
        return match ($tournament->status) {
            TournamentStatus::Draft => 'draft',
            TournamentStatus::Open => $tournament->isOpenForRegistration() ? 'open' : 'soon',
            default => $this->hasStarted($tournament) ? 'now' : 'soon',
        };
    }

    /**
     * Started: the first serve has passed or a real match (not a bye) is live or finished.
     */
    protected function hasStarted(Tournament $tournament): bool
    {
        return $tournament->starts_at->isPast()
            || $tournament->matches->contains(fn ($match): bool => ! $match->isBye() && ($match->isFinished() || $match->isLive()));
    }
}; ?>

<div>
    @php
        $stages = $this->stages;
        $completed = $this->completed;
        $entered = $this->enteredIds;

        // Sections alternate white and grey in the order they are shown.
        $sections = collect([
            'now' => $stages['now']->isNotEmpty(),
            'open' => true,
            'soon' => $stages['soon']->isNotEmpty(),
            'draft' => $stages['draft']->isNotEmpty(),
            'completed' => $completed->total() > 0,
        ])->filter()->keys()->values();
        $tone = fn (string $key): string => $sections->search($key) % 2 === 0 ? 'white' : 'grey';
    @endphp

    <x-ui.page-hero
        :breadcrumbs="['Tournaments']"
        :kicker="$this->season"
        title="Tournaments"
        intro="Knockouts and round robins for every level. Sign up on your own for singles or with a partner for doubles, then follow the draw on the day."
        court
    >
        <x-slot:meta>
            @if ($stages['now']->isNotEmpty())
                <x-ui.tag tone="orange" dot>{{ __(':n happening now', ['n' => $stages['now']->count()]) }}</x-ui.tag>
            @endif
            <x-ui.tag tone="green" :dot="$stages['open']->isNotEmpty()">{{ __(':n open for registration', ['n' => $stages['open']->count()]) }}</x-ui.tag>
            @if ($completed->total() > 0)
                <x-ui.tag tone="navy">{{ __(':n completed', ['n' => $completed->total()]) }}</x-ui.tag>
            @endif
        </x-slot:meta>

        @can('create', \App\Models\Tournament::class)
            <x-slot:actions>
                <x-ui.button variant="primary" icon="plus" :href="route('tournaments.create')">{{ __('New tournament') }}</x-ui.button>
            </x-slot:actions>
        @endcan
    </x-ui.page-hero>

    @if ($stages['now']->isNotEmpty())
        <x-ui.section
            id="happening-now"
            :tone="$tone('now')"
            kicker="Live draws"
            title="Happening now"
            intro="Results move the winners on straight away. Open a draw to see who plays next and on which court."
        >
            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($stages['now'] as $tournament)
                    <x-tournaments.card :tournament="$tournament" stage="now" :entered="in_array($tournament->id, $entered, true)" wire:key="now-{{ $tournament->id }}" />
                @endforeach
            </div>
        </x-ui.section>
    @endif

    <x-ui.section
        id="open-for-registration"
        :tone="$tone('open')"
        kicker="Sign up"
        title="Open for registration"
        intro="Places are limited and seeds go by rating, so the strongest meet late. You can withdraw any time before the draw is made."
    >
        @if ($stages['open']->isNotEmpty())
            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($stages['open'] as $tournament)
                    <x-tournaments.card :tournament="$tournament" stage="open" :entered="in_array($tournament->id, $entered, true)" wire:key="open-{{ $tournament->id }}" />
                @endforeach
            </div>
        @else
            <x-ui.empty
                :variant="$tone('open') === 'grey' ? 'plain' : 'muted'"
                :title="$this->current->isEmpty() && $completed->total() === 0 ? __('No tournaments yet') : __('Nothing open for registration right now')"
                icon="trophy"
                @class(['bg-surface' => $tone('open') === 'grey'])
            >
                {{ __('New tournaments are announced at club nights and here first. Keep an eye on this page.') }}
                @can('create', \App\Models\Tournament::class)
                    <x-slot:actions>
                        <x-ui.button variant="primary" icon="plus" :href="route('tournaments.create')">{{ __('New tournament') }}</x-ui.button>
                    </x-slot:actions>
                @endcan
            </x-ui.empty>
        @endif
    </x-ui.section>

    @if ($stages['soon']->isNotEmpty())
        <x-ui.section
            id="coming-up"
            :tone="$tone('soon')"
            kicker="Registration closed"
            title="Coming up"
            intro="The line-up is set. Check the entries now and the draw once it is out."
        >
            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($stages['soon'] as $tournament)
                    <x-tournaments.card :tournament="$tournament" stage="soon" :entered="in_array($tournament->id, $entered, true)" wire:key="soon-{{ $tournament->id }}" />
                @endforeach
            </div>
        </x-ui.section>
    @endif

    @if ($stages['draft']->isNotEmpty())
        <x-ui.section
            id="drafts"
            :tone="$tone('draft')"
            kicker="Admins only"
            title="Drafts"
            intro="Members cannot see these yet. Check the details, then open registration from the tournament page."
        >
            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($stages['draft'] as $tournament)
                    <x-tournaments.card :tournament="$tournament" stage="draft" wire:key="draft-{{ $tournament->id }}" />
                @endforeach
            </div>
        </x-ui.section>
    @endif

    @if ($completed->total() > 0)
        <x-ui.section
            id="completed"
            :tone="$tone('completed')"
            kicker="Roll of honour"
            title="Completed"
            intro="Champions, finals and full draws from earlier tournaments."
        >
            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($completed as $tournament)
                    <x-tournaments.card :tournament="$tournament" stage="completed" :entered="in_array($tournament->id, $entered, true)" wire:key="done-{{ $tournament->id }}" />
                @endforeach
            </div>

            @if ($completed->hasPages())
                <div class="mt-8">
                    <flux:pagination :paginator="$completed" />
                </div>
            @endif
        </x-ui.section>
    @endif
</div>
