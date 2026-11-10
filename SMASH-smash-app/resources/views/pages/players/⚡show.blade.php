<?php

use App\Actions\Players\DeletePlayer;
use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Enums\TournamentStatus;
use App\Models\BadmintonMatch;
use App\Models\ClubNightRsvp;
use App\Models\TournamentEntry;
use App\Models\User;
use App\Services\Prediction\MatchPrediction;
use App\Services\Prediction\MatchPredictor;
use App\Services\Stats\Achievement;
use App\Services\Stats\Achievements;
use App\Services\Stats\PlayerStats;
use App\Services\Stats\PlayerStatsSummary;
use App\Services\Tournament\StandingRow;
use App\Services\Tournament\Standings;
use App\Services\Tournament\TournamentProgress;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    public const RECENT = 10;

    public const UPCOMING = 6;

    public const PARTNERS = 8;

    #[Locked]
    public User $user;

    /** singles · doubles; empty = the discipline this player plays most. */
    #[Url(except: '')]
    public string $discipline = '';

    /** Player id picked in the "Compare with…" dialog. */
    public string $compareWith = '';

    public function mount(User $user): void
    {
        $this->user = $user;

        $viewer = auth()->user();

        if ($viewer instanceof User && $viewer->isNot($user)) {
            $this->compareWith = (string) $viewer->id;
        }
    }

    public function render(): View
    {
        return $this->view()->title($this->user->name);
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    /**
     * Open the head-to-head page for this player and the one picked in the dialog.
     */
    public function compare(): void
    {
        $this->validate(
            ['compareWith' => ['required', 'integer', Rule::exists('users', 'id'), Rule::notIn([$this->user->id])]],
            [
                'compareWith.required' => __('Pick a player to compare with.'),
                'compareWith.not_in' => __('Pick somebody else: comparing a player with themselves is a short story.'),
                'compareWith.*' => __('Pick a player from the list.'),
            ],
        );

        $this->redirectRoute('head-to-head', ['a' => $this->user->id, 'b' => (int) $this->compareWith], navigate: true);
    }

    /**
     * Admins: hide a player from the directory, rankings and pickers (or bring them back).
     */
    public function toggleActive(): void
    {
        $this->authorize('update', $this->user);

        abort_if($this->user->is(auth()->user()), 403, __("You can't deactivate your own account."));

        $this->user->is_active = ! $this->user->is_active;
        $this->user->save();

        unset($this->singles, $this->doubles, $this->stats);

        Flux::toast(
            variant: 'success',
            text: $this->user->is_active
                ? __(':name is active again and back in the rankings.', ['name' => $this->user->name])
                : __(':name is now inactive and hidden from the directory and rankings.', ['name' => $this->user->name]),
        );
    }

    /**
     * Admins: delete the player for good (DeletePlayer removes their friendlies and entries and
     * recalculates every rating).
     */
    public function delete(): void
    {
        $this->authorize('delete', $this->user);

        $name = $this->user->name;

        DeletePlayer::handle($this->user);

        Flux::toast(variant: 'success', text: __(':name has been deleted and all ratings were recalculated.', ['name' => $name]));

        $this->redirectRoute('players.index', navigate: true);
    }

    // ------------------------------------------------------------------
    // Data
    // ------------------------------------------------------------------

    #[Computed]
    public function selectedDiscipline(): Discipline
    {
        return Discipline::tryFrom($this->discipline)
            ?? ($this->user->played(Discipline::Doubles) > $this->user->played(Discipline::Singles) ? Discipline::Doubles : Discipline::Singles);
    }

    #[Computed]
    public function singles(): PlayerStatsSummary
    {
        return PlayerStats::for($this->user, Discipline::Singles);
    }

    #[Computed]
    public function doubles(): PlayerStatsSummary
    {
        return PlayerStats::for($this->user, Discipline::Doubles);
    }

    /**
     * Manual rating adjustments per discipline value (count and net points), from the ratings admin.
     *
     * @return array<string, array{count: int, total: float}>
     */
    #[Computed]
    public function adjustments(): array
    {
        return \App\Models\RatingAdjustment::query()
            ->forPlayer($this->user)
            ->toBase()
            ->selectRaw('discipline, count(*) as aggregate, sum(amount) as total')
            ->groupBy('discipline')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->discipline => ['count' => (int) $row->aggregate, 'total' => (float) $row->total]])
            ->all();
    }

    #[Computed]
    public function stats(): PlayerStatsSummary
    {
        return $this->selectedDiscipline === Discipline::Doubles ? $this->doubles : $this->singles;
    }

    /**
     * Best current rank in either discipline (for the #1 ring on the avatar).
     */
    #[Computed]
    public function topRank(): ?int
    {
        $ranks = array_filter([$this->singles->rank, $this->doubles->rank]);

        return $ranks === [] ? null : min($ranks);
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function statItems(): array
    {
        $s = $this->stats;
        $winRate = $s->winRate === null ? null : (int) round($s->winRate * 100);
        $games = $s->gamesWon + $s->gamesLost;
        $pointDiff = $s->pointsWon - $s->pointsLost;
        $decidersLost = $s->decidersPlayed - $s->decidersWon;

        return [
            [
                'value' => $s->played,
                'label' => __('Matches'),
                'sublabel' => trans_choice('{1} :n rated :discipline match|[2,*] :n rated :discipline matches', $s->played, ['n' => $s->played, 'discipline' => Str::lower($s->discipline->label())]),
            ],
            [
                'value' => $s->wins.'–'.$s->losses,
                'label' => __('Won–lost'),
                'sublabel' => $winRate === null ? __('No results yet') : __(':n% of matches won', ['n' => $winRate]),
            ],
            [
                'value' => $s->currentStreak === 0 ? '–' : abs($s->currentStreak),
                'label' => $s->currentStreak < 0 ? __('Losing streak') : __('Win streak'),
                'tone' => $s->currentStreak >= 3 ? 'smash' : null,
                'sublabel' => __('Longest win streak: :n', ['n' => $s->longestWinStreak]),
            ],
            [
                'value' => $s->gamesWon.'–'.$s->gamesLost,
                'label' => __('Games won–lost'),
                'sublabel' => $games === 0 ? __('No games yet') : __(':n% of games won', ['n' => (int) round($s->gamesWon / $games * 100)]),
            ],
            [
                'value' => ($pointDiff > 0 ? '+' : ($pointDiff < 0 ? '−' : '±')).abs($pointDiff),
                'label' => __('Point difference'),
                'tone' => $pointDiff > 0 ? 'green' : null,
                'sublabel' => __(':won points won, :lost lost', ['won' => $s->pointsWon, 'lost' => $s->pointsLost]),
            ],
            [
                'value' => $s->decidersPlayed === 0 ? '–' : $s->decidersWon.'–'.$decidersLost,
                'label' => __('Deciders won–lost'),
                'sublabel' => trans_choice('{0} No three-game matches yet|{1} :n match went the distance|[2,*] :n matches went the distance', $s->decidersPlayed, ['n' => $s->decidersPlayed]),
            ],
        ];
    }

    /**
     * Every badge, unlocked ones first (otherwise in the catalogue order).
     *
     * @return list<Achievement>
     */
    #[Computed]
    public function achievements(): array
    {
        $all = Achievements::for($this->user);

        return [
            ...array_values(array_filter($all, fn (Achievement $achievement): bool => $achievement->earned)),
            ...array_values(array_filter($all, fn (Achievement $achievement): bool => ! $achievement->earned)),
        ];
    }

    /**
     * Latest finished matches (both disciplines).
     *
     * @return Collection<int, BadmintonMatch>
     */
    #[Computed]
    public function recentMatches(): Collection
    {
        return BadmintonMatch::query()
            ->forPlayer($this->user)
            ->finished()
            ->withDetails()
            ->latestFirst()
            ->limit(self::RECENT)
            ->get();
    }

    /**
     * Live and scheduled matches, including tournament matches whose opponent is not known yet.
     *
     * @return Collection<int, BadmintonMatch>
     */
    #[Computed]
    public function upcomingMatches(): Collection
    {
        $entryIds = TournamentEntry::query()->forPlayer($this->user)->pluck('id')->all();
        $userId = $this->user->id;

        return BadmintonMatch::query()
            ->whereIn('matches.status', [MatchStatus::Scheduled->value, MatchStatus::InProgress->value])
            ->where(function (Builder $query) use ($entryIds, $userId): void {
                $query->whereHas('participants', fn (Builder $participants) => $participants->where('user_id', $userId));

                if ($entryIds !== []) {
                    $query->orWhereIn('matches.entry_a_id', $entryIds)->orWhereIn('matches.entry_b_id', $entryIds);
                }
            })
            ->withDetails()
            ->orderByRaw("case when matches.status = 'in_progress' then 0 else 1 end")
            ->orderByRaw('matches.scheduled_at is null')
            ->orderBy('matches.scheduled_at')
            ->orderBy('matches.id')
            ->limit(self::UPCOMING)
            ->get();
    }

    /**
     * Win chances for the scheduled fixtures whose line-ups are known.
     *
     * @return array<int, MatchPrediction>
     */
    #[Computed]
    public function predictions(): array
    {
        $predictions = [];

        foreach ($this->upcomingMatches as $match) {
            $known = $match->participants->contains(fn ($participant) => $participant->side === Side::A)
                && $match->participants->contains(fn ($participant) => $participant->side === Side::B);

            if ($match->status === MatchStatus::Scheduled && $known) {
                $predictions[$match->id] = MatchPredictor::forMatch($match);
            }
        }

        return $predictions;
    }

    /**
     * Tournament entries (newest first) with how far the player got.
     *
     * @return list<array{tournament: \App\Models\Tournament, partner: ?User, result: string, detail: ?string, tone: string, icon: ?string, solid: bool}>
     */
    #[Computed]
    public function tournamentHistory(): array
    {
        $viewer = auth()->user();

        $entries = TournamentEntry::query()
            ->forPlayer($this->user)
            ->whereHas('tournament', fn (Builder $query) => $query->visibleTo($viewer instanceof User ? $viewer : null))
            ->with(['tournament', 'player', 'partner'])
            ->get()
            ->sortByDesc(fn (TournamentEntry $entry): int => $entry->tournament->starts_at->getTimestamp())
            ->values();

        if ($entries->isEmpty()) {
            return [];
        }

        $entryIds = $entries->pluck('id')->all();

        $matches = BadmintonMatch::query()
            ->whereIn('tournament_id', $entries->pluck('tournament_id')->unique()->all())
            ->where(fn (Builder $query) => $query->whereIn('entry_a_id', $entryIds)->orWhereIn('entry_b_id', $entryIds))
            ->orderBy('round')
            ->orderBy('id')
            ->get(['id', 'tournament_id', 'round', 'bracket_position', 'status', 'winning_side', 'entry_a_id', 'entry_b_id', 'scheduled_at', 'played_at'])
            ->groupBy('tournament_id');

        return $entries
            ->map(fn (TournamentEntry $entry): array => $this->tournamentResult($entry, $matches->get($entry->tournament_id) ?? new Collection))
            ->all();
    }

    /**
     * Club night check-ins.
     *
     * @return array{attended: int, last: ?ClubNightRsvp}
     */
    #[Computed]
    public function clubNights(): array
    {
        $checkIns = fn () => ClubNightRsvp::query()->where('user_id', $this->user->id)->whereNotNull('checked_in_at');

        return [
            'attended' => $checkIns()->count(),
            'last' => $checkIns()->with('clubNight')->orderByDesc('checked_in_at')->first(),
        ];
    }

    /**
     * Players for the "Compare with…" dialog (names only).
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function opponents(): Collection
    {
        return User::query()->active()->whereKeyNot($this->user->id)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @param  Collection<int, BadmintonMatch>  $matches  the entry's matches in that tournament
     * @return array{tournament: \App\Models\Tournament, partner: ?User, result: string, detail: ?string, tone: string, icon: ?string, solid: bool}
     */
    private function tournamentResult(TournamentEntry $entry, Collection $matches): array
    {
        $tournament = $entry->tournament;
        $row = [
            'tournament' => $tournament,
            'partner' => $entry->members()->first(fn (User $member): bool => $member->isNot($this->user)),
            'result' => __('Registered'),
            'detail' => null,
            'tone' => 'sky',
            'icon' => null,
            'solid' => false,
        ];

        if ($matches->isEmpty() || in_array($tournament->status, [TournamentStatus::Draft, TournamentStatus::Open], true)) {
            return [...$row, 'detail' => $entry->seed ? __('Seed :n', ['n' => $entry->seed]) : null];
        }

        $completed = $tournament->status === TournamentStatus::Completed;

        if ($tournament->isRoundRobin()) {
            $table = Standings::for($tournament);
            $standing = $table->first(fn (StandingRow $standingRow): bool => $standingRow->entry->id === $entry->id);
            $position = $standing?->position;
            $record = $standing ? __('Won :won, lost :lost', ['won' => $standing->won, 'lost' => $standing->lost]) : null;

            if ($position === null) {
                return $row;
            }

            if ($completed && $position === 1) {
                return [...$row, 'result' => __('Champion'), 'detail' => $record, 'tone' => 'orange', 'icon' => 'trophy', 'solid' => true];
            }

            if ($completed && $position === 2) {
                return [...$row, 'result' => __('Runner-up'), 'detail' => $record, 'tone' => 'navy', 'icon' => 'star', 'solid' => true];
            }

            return [
                ...$row,
                'result' => $completed
                    ? __(':position of :count', ['position' => $this->ordinal($position), 'count' => $table->count()])
                    : __(':position so far', ['position' => $this->ordinal($position)]),
                'detail' => $record,
                'tone' => $completed ? 'grey' : 'green',
            ];
        }

        if ($completed && $tournament->champion()?->id === $entry->id) {
            return [...$row, 'result' => __('Champion'), 'detail' => __('Won the final'), 'tone' => 'orange', 'icon' => 'trophy', 'solid' => true];
        }

        if ($completed && $tournament->runnerUp()?->id === $entry->id) {
            return [...$row, 'result' => __('Runner-up'), 'detail' => __('Reached the final'), 'tone' => 'navy', 'icon' => 'star', 'solid' => true];
        }

        /** @var BadmintonMatch $last */
        $last = $matches->sortByDesc(fn (BadmintonMatch $match): int => (int) $match->round)->first();
        $round = $tournament->roundLabel((int) $last->round, plural: true);

        if (! $last->isFinished()) {
            $when = $last->scheduled_at?->format('D j M');

            return [
                ...$row,
                'result' => __('Still in'),
                'detail' => $when ? __('Next: :round, :date', ['round' => $tournament->roundLabel((int) $last->round), 'date' => $when]) : __('Next: :round', ['round' => $tournament->roundLabel((int) $last->round)]),
                'tone' => 'green',
            ];
        }

        if (TournamentProgress::advancingEntryId($last) === $entry->id) {
            return [...$row, 'result' => __('Through'), 'detail' => __('Won in the :round', ['round' => Str::lower($round)]), 'tone' => 'green'];
        }

        return [...$row, 'result' => __('Out in the :round', ['round' => Str::lower($round)]), 'tone' => 'grey'];
    }

    private function ordinal(int $number): string
    {
        $suffix = match (true) {
            in_array($number % 100, [11, 12, 13], true) => 'th',
            $number % 10 === 1 => 'st',
            $number % 10 === 2 => 'nd',
            $number % 10 === 3 => 'rd',
            default => 'th',
        };

        return $number.$suffix;
    }
}; ?>

<div>
    @php
        $user = $this->user;
        $discipline = $this->selectedDiscipline;
        $s = $this->stats;
        $viewer = auth()->user();
        $isSelf = $viewer !== null && $viewer->is($user);
        $canEdit = $viewer?->can('update', $user) ?? false;
        $canDelete = $viewer?->can('delete', $user) ?? false;
        $firstName = $user->firstName();
        $isNew = $user->created_at !== null && $user->created_at->greaterThan(now()->subDays(30));
        $initialRating = (int) config('smash.rating.initial', 1500);
        $unlocked = collect($this->achievements)->where('earned', true)->count();
    @endphp

    {{-- ============================================================== HERO --}}
    <x-ui.page-hero
        :breadcrumbs="[[__('Players'), route('players.index')], $user->name]"
        :kicker="$user->programme?->label() ?? __('SMASH member')"
        :title="$user->name"
        :intro="$user->bio"
    >
        @if (! $user->is_active || $user->isAdmin() || $isNew)
            <div class="space-y-5">
                @unless ($user->is_active)
                    <x-ui.alert tone="warning" :title="__('Inactive member')" data-test="inactive-notice">
                        {{ __(':name isn\'t playing at the moment, so they\'re hidden from the directory, the rankings and the match pickers. Their results stay on record.', ['name' => $firstName]) }}
                    </x-ui.alert>
                @endunless

                @if ($user->isAdmin() || $isNew)
                    <div class="flex flex-wrap gap-2">
                        @if ($user->isAdmin())
                            <x-ui.tag tone="navy" variant="solid" icon="shield-check">{{ __('Committee') }}</x-ui.tag>
                        @endif
                        @if ($isNew)
                            <x-ui.tag tone="green" variant="soft">{{ __('New member') }}</x-ui.tag>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        <x-slot:actions>
            <flux:modal.trigger name="compare-player">
                <x-ui.button variant="outline" icon="arrows-right-left" data-test="compare-player">{{ __('Compare with…') }}</x-ui.button>
            </flux:modal.trigger>

            @if ($isSelf && ! $canEdit)
                <x-ui.button variant="ghost" icon="pencil-square" :href="route('player-profile.edit')">{{ __('Edit your player profile') }}</x-ui.button>
            @endif

            @if ($canEdit)
                <x-ui.button variant="primary" icon="pencil-square" :href="route('players.edit', $user)">{{ __('Edit') }}</x-ui.button>

                @unless ($isSelf)
                    <x-ui.button
                        variant="ghost"
                        :icon="$user->is_active ? 'pause-circle' : 'play-circle'"
                        wire:click="toggleActive"
                        data-test="toggle-active"
                    >{{ $user->is_active ? __('Deactivate') : __('Activate') }}</x-ui.button>
                @endunless
            @endif

            @if ($canDelete)
                <flux:modal.trigger name="delete-player">
                    <x-ui.button variant="danger-outline" icon="trash" data-test="delete-player">{{ __('Delete') }}</x-ui.button>
                </flux:modal.trigger>
            @endif
        </x-slot:actions>

        {{-- Player card: identity facts and both ratings. --}}
        <x-slot:aside>
            <div class="flex items-center gap-5">
                <x-smash.avatar :user="$user" size="2xl" ring="auto" :rank="$this->topRank" />
                <dl class="grid min-w-0 grid-cols-[auto_minmax(0,1fr)] items-baseline gap-x-4 gap-y-2.5 text-[15px]">
                    <dt class="label-caps text-ink-muted">{{ __('Level') }}</dt>
                    <dd class="truncate font-medium text-ink">{{ $user->level?->label() ?? __('Not set') }}</dd>
                    <dt class="label-caps text-ink-muted">{{ __('Plays') }}</dt>
                    <dd class="truncate font-medium text-ink">{{ $user->handedness?->label() ?? __('Not set') }}</dd>
                    <dt class="label-caps text-ink-muted">{{ __('Since') }}</dt>
                    <dd class="truncate font-medium text-ink">{{ $user->created_at?->format('F Y') ?? '–' }}</dd>
                </dl>
            </div>

            <div class="mt-6 grid grid-cols-2 border-t border-line">
                @foreach ([Discipline::Singles->value => $this->singles, Discipline::Doubles->value => $this->doubles] as $key => $summary)
                    <div @class(['min-w-0 pt-5', 'pe-4' => $loop->first, 'border-s border-line ps-4 sm:ps-5' => ! $loop->first]) data-test="rating-{{ $key }}">
                        <p class="label-caps text-ink-muted">{{ Discipline::from($key)->label() }}</p>
                        <x-smash.rating
                            class="mt-2"
                            :value="$summary->played > 0 ? $summary->rating : null"
                            :provisional="$summary->played > 0 && $summary->provisional"
                            size="lg"
                        />
                        <p class="mt-2 text-sm text-ink-soft">
                            @if ($summary->rank !== null)
                                <span class="num text-[17px] font-bold text-ink">#{{ $summary->rank }}</span>
                                {{ __('of :n', ['n' => $summary->rankedPlayers]) }}
                            @elseif ($summary->played > 0)
                                {{ __('Not ranked') }}
                            @else
                                {{ __('No matches yet') }}
                            @endif
                        </p>
                        @if ($summary->played > 0)
                            <p class="mt-0.5 text-sm text-ink-muted">{{ __('Peak') }} <span class="num font-semibold text-ink-soft">{{ $summary->peak }}</span></p>
                        @endif
                        @if ($adjusted = $this->adjustments[$key] ?? null)
                            @php
                                $adjustedLabel = trans_choice(':total by adjustment|:total by :count adjustments', $adjusted['count'], [
                                    'total' => \App\Models\RatingAdjustment::format($adjusted['total']),
                                    'count' => $adjusted['count'],
                                ]);
                            @endphp
                            <p class="mt-0.5 text-sm text-ink-muted" data-test="rating-adjusted-{{ $key }}">
                                @can('manage')
                                    <a href="{{ route('rating-adjustments.index', ['player' => $user->id, 'discipline' => $key]) }}" wire:navigate class="text-link underline-offset-[3px] hover:underline">{{ $adjustedLabel }}</a>
                                @else
                                    {{ $adjustedLabel }}
                                @endcan
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-slot:aside>
    </x-ui.page-hero>

    {{-- ============================================================== STATS --}}
    <x-ui.section id="stats" :kicker="__('Rated matches')" :title="__('Statistics')">
        <x-slot:actions>
            <x-ui.link :href="route('rankings.index', ['discipline' => $discipline->value])">{{ __(':discipline rankings', ['discipline' => $discipline->label()]) }}</x-ui.link>
        </x-slot:actions>

        <x-ui.tabs :label="__('Discipline')" class="mb-8">
            @foreach (Discipline::cases() as $option)
                <x-ui.tabs.item
                    wire:click="$set('discipline', '{{ $option->value }}')"
                    :current="$discipline === $option"
                    :count="$user->played($option)"
                    :icon="$option->icon()"
                    wire:key="tab-{{ $option->value }}"
                >{{ $option->label() }}</x-ui.tabs.item>
            @endforeach
        </x-ui.tabs>

        <div wire:loading.class="opacity-60" wire:target="discipline" class="transition-opacity">
            @if ($s->played === 0)
                <x-ui.empty :title="__('No rated :discipline matches yet', ['discipline' => Str::lower($discipline->label())])">
                    @if ($isSelf)
                        {{ __('Play a :discipline match at a club night and log the result: your rating, form and stats start here.', ['discipline' => Str::lower($discipline->label())]) }}
                    @else
                        {{ __(':name hasn\'t played a rated :discipline match yet. Their rating, form and stats show up after the first one.', ['name' => $firstName, 'discipline' => Str::lower($discipline->label())]) }}
                    @endif
                    @if ($isSelf)
                        <x-slot:actions>
                            <x-ui.button variant="cta" size="sm" :href="route('matches.create')">{{ __('Log a match') }}</x-ui.button>
                        </x-slot:actions>
                    @endif
                </x-ui.empty>
            @else
                <x-smash.stat-row :items="$this->statItems" :columns="3" />

                <div class="mt-6 grid gap-6 lg:grid-cols-3">
                    <x-ui.card padding="lg" class="lg:col-span-2">
                        <div class="flex flex-wrap items-end justify-between gap-4">
                            <div>
                                <p class="kicker">{{ __('Rating history') }}</p>
                                <h3 class="mt-1 font-serif text-h4 text-heading">{{ __(':discipline rating', ['discipline' => $discipline->label()]) }}</h3>
                            </div>
                            <div class="flex flex-col items-end gap-1">
                                <x-smash.rating-delta :value="$s->change30d" size="lg" :label="__('Change in the last 30 days')" />
                                <span class="label-caps text-ink-muted">{{ __('Last 30 days') }}</span>
                            </div>
                        </div>
                        <x-smash.rating-chart
                            class="mt-6"
                            :points="$s->ratingHistory"
                            :label="__(':discipline rating', ['discipline' => $discipline->label()])"
                            :baseline="$initialRating"
                            table
                            wire:key="chart-{{ $discipline->value }}"
                        />
                    </x-ui.card>

                    <x-ui.card padding="lg">
                        <h3 class="font-serif text-h4 text-heading">{{ __('Highlights') }}</h3>
                        <dl class="mt-2 divide-y divide-line">
                            <div class="py-4">
                                <dt class="label-caps text-ink-muted">{{ __('Form') }}</dt>
                                <dd class="mt-2.5"><x-smash.form :results="$s->form" /></dd>
                            </div>
                            <div class="py-4">
                                <dt class="label-caps text-ink-muted">{{ __('Best win') }}</dt>
                                <dd class="mt-2.5">
                                    @if ($s->bestWin)
                                        <x-ui.link :href="route('matches.show', $s->bestWin['match_id'])" variant="promo">
                                            {{ __('Beat :opponent', ['opponent' => $s->bestWin['opponent']]) }}
                                            <span class="num font-bold">({{ $s->bestWin['opponent_rating'] }})</span>
                                        </x-ui.link>
                                    @else
                                        <span class="text-sm text-ink-muted">{{ __('No rated win yet.') }}</span>
                                    @endif
                                </dd>
                            </div>
                            <div class="py-4">
                                <dt class="label-caps text-ink-muted">{{ __('Nemesis') }}</dt>
                                <dd class="mt-2.5">
                                    @if ($s->nemesis)
                                        <x-smash.player
                                            :user="$s->nemesis['user']"
                                            size="sm"
                                            :meta="__('Won :n of :played against :name', ['n' => $s->nemesis['losses'], 'played' => $s->nemesis['played'], 'name' => $firstName])"
                                        />
                                    @else
                                        <span class="text-sm text-ink-muted">{{ __('Nobody has the upper hand yet.') }}</span>
                                    @endif
                                </dd>
                            </div>
                            <div class="pt-4">
                                <dt class="label-caps text-ink-muted">{{ __('Favourite opponent') }}</dt>
                                <dd class="mt-2.5">
                                    @if ($s->favouriteOpponent)
                                        <x-smash.player
                                            :user="$s->favouriteOpponent['user']"
                                            size="sm"
                                            :meta="__('Lost :n of :played against :name', ['n' => $s->favouriteOpponent['wins'], 'played' => $s->favouriteOpponent['played'], 'name' => $firstName])"
                                        />
                                    @else
                                        <span class="text-sm text-ink-muted">{{ __('No regular victim yet.') }}</span>
                                    @endif
                                </dd>
                            </div>
                        </dl>
                    </x-ui.card>
                </div>

                @if ($discipline === Discipline::Doubles && $s->partners !== [])
                    <x-ui.table class="mt-6" data-test="partners">
                        <x-slot:header>
                            <div>
                                <h3 class="font-serif text-h4 text-heading">{{ __('Partners') }}</h3>
                                <p class="mt-1 text-sm text-ink-muted">{{ __('Who :name plays rated doubles with most.', ['name' => $firstName]) }}</p>
                            </div>
                        </x-slot:header>
                        <table>
                            <thead>
                                <tr>
                                    <th>{{ __('Partner') }}</th>
                                    <th class="text-end max-sm:hidden">{{ __('Played') }}</th>
                                    <th class="text-end">{{ __('W–L') }}</th>
                                    <th class="w-48 max-md:hidden">{{ __('Wins together') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach (array_slice($s->partners, 0, self::PARTNERS) as $partner)
                                    <tr wire:key="partner-{{ $partner['user']->id }}">
                                        <td class="min-w-0"><x-smash.player :user="$partner['user']" size="sm" rating discipline="doubles" /></td>
                                        <td class="num text-end text-[17px] font-semibold text-ink max-sm:hidden">{{ $partner['played'] }}</td>
                                        <td class="text-end"><x-smash.record :wins="$partner['wins']" :losses="$partner['played'] - $partner['wins']" size="sm" :label="false" :rate="false" /></td>
                                        <td class="max-md:hidden">
                                            <x-ui.progress :value="$partner['wins']" :max="max(1, $partner['played'])" tone="green" size="sm" :value-label="(int) round($partner['wins'] / max(1, $partner['played']) * 100).'%'" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-ui.table>
                @endif
            @endif
        </div>
    </x-ui.section>

    {{-- ============================================================== MATCHES --}}
    <x-ui.section id="matches" tone="grey" :kicker="__('Results and fixtures')" :title="__('Matches')">
        <x-slot:actions>
            <x-ui.link :href="route('matches.index', ['player' => $user->id])">{{ __('All matches') }}</x-ui.link>
        </x-slot:actions>

        @if ($this->upcomingMatches->isNotEmpty())
            <x-ui.heading :level="3" size="h4">{{ __('Coming up') }}</x-ui.heading>
            <div class="mt-4 mb-10 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->upcomingMatches as $match)
                    <x-smash.match-card
                        :match="$match"
                        :for="$user"
                        :probability="$this->predictions[$match->id] ?? null"
                        wire:key="upcoming-{{ $match->id }}"
                    />
                @endforeach
            </div>
        @endif

        <x-ui.heading :level="3" size="h4">{{ __('Recent results') }}</x-ui.heading>
        @if ($this->recentMatches->isEmpty())
            <x-ui.empty class="mt-4" variant="plain" align="start" :title="__('No results yet')">
                {{ $isSelf ? __('Your finished matches will show up here.') : __(':name\'s finished matches will show up here.', ['name' => $firstName]) }}
            </x-ui.empty>
        @else
            <div class="mt-4 grid gap-3 lg:grid-cols-2">
                @foreach ($this->recentMatches as $match)
                    <x-smash.match-card
                        :match="$match"
                        :for="$user"
                        variant="compact"
                        @class(['max-md:hidden' => $loop->index >= 6])
                        wire:key="recent-{{ $match->id }}"
                    />
                @endforeach
            </div>
        @endif
    </x-ui.section>

    {{-- ============================================================== ACHIEVEMENTS --}}
    <x-ui.section
        id="achievements"
        :kicker="__(':n of :total unlocked', ['n' => $unlocked, 'total' => count($this->achievements)])"
        :title="__('Achievements')"
    >
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->achievements as $achievement)
                <x-players.achievement :achievement="$achievement" wire:key="achievement-{{ $achievement->key }}" />
            @endforeach
        </div>
    </x-ui.section>

    {{-- ============================================================== TOURNAMENTS & CLUB NIGHTS --}}
    <x-ui.section id="history" tone="grey" :kicker="__('On and off court')" :title="__('Tournaments and club nights')">
        <div class="grid items-start gap-6 lg:grid-cols-3">
            <x-ui.card padding="none" class="lg:col-span-2" data-test="tournament-history">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
                    <h3 class="font-serif text-h4 text-heading">{{ __('Tournament history') }}</h3>
                    <x-ui.link :href="route('tournaments.index')">{{ __('Tournaments') }}</x-ui.link>
                </div>
                @if ($this->tournamentHistory === [])
                    <x-ui.empty variant="plain" align="start" :title="__('No tournaments yet')" icon="trophy">
                        {{ $isSelf ? __('Register for the next tournament on its page: singles on your own, doubles with a partner.') : __(':name hasn\'t entered a tournament yet.', ['name' => $firstName]) }}
                    </x-ui.empty>
                @else
                    <div class="divide-y divide-line">
                        @foreach ($this->tournamentHistory as $row)
                            <x-players.tournament-row :row="$row" wire:key="tournament-{{ $row['tournament']->id }}" />
                        @endforeach
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card padding="lg" highlight="green" data-test="club-nights">
                <p class="kicker">{{ __('Club nights') }}</p>
                <p class="mt-3 num text-stat font-extrabold leading-none text-heading">{{ $this->clubNights['attended'] }}</p>
                <p class="mt-2 label-caps text-ink-muted">{{ trans_choice('{0} Club nights attended|{1} Club night attended|[2,*] Club nights attended', $this->clubNights['attended']) }}</p>
                @if ($last = $this->clubNights['last'])
                    <p class="mt-5 text-[15px] text-ink-soft">
                        {{ __('Last checked in at') }}
                        <a href="{{ route('club-nights.show', $last->clubNight) }}" wire:navigate class="link">{{ $last->clubNight->title }}</a>,
                        {{ $last->clubNight->starts_at->format('D j M') }}.
                    </p>
                @else
                    <p class="mt-5 text-[15px] text-ink-soft">{{ __('No check-ins yet. Club nights are on Tuesdays and Thursdays in the sports hall.') }}</p>
                @endif
                <x-ui.link class="mt-6" :href="route('club-nights.index')">{{ __('Upcoming club nights') }}</x-ui.link>
            </x-ui.card>
        </div>
    </x-ui.section>

    {{-- ============================================================== DIALOGS --}}
    <flux:modal name="compare-player" class="md:w-[30rem]">
        <form wire:submit="compare" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Compare :name with…', ['name' => $firstName]) }}</flux:heading>
                <flux:text class="mt-2">{{ __('See every meeting, the head-to-head record and who the predictor fancies next time.') }}</flux:text>
            </div>

            <flux:select wire:model="compareWith" :label="__('Player')">
                <option value="">{{ __('Choose a player') }}</option>
                @foreach ($this->opponents as $opponent)
                    <option value="{{ $opponent->id }}">{{ $opponent->name }}{{ $viewer?->is($opponent) ? ' ('.__('you').')' : '' }}</option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <x-ui.button variant="ghost" size="sm">{{ __('Cancel') }}</x-ui.button>
                </flux:modal.close>
                <x-ui.button variant="primary" size="sm" type="submit" icon-trailing="arrow-right">{{ __('Compare') }}</x-ui.button>
            </div>
        </form>
    </flux:modal>

    @if ($canDelete)
        <x-players.delete-modal :user="$user" :can-deactivate="$canEdit && ! $isSelf" />
    @endif
</div>
