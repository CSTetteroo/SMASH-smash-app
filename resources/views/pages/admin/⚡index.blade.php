<?php

use App\Enums\MatchStatus;
use App\Enums\RsvpStatus;
use App\Enums\TournamentStatus;
use App\Models\BadmintonMatch;
use App\Models\ClubNight;
use App\Models\ClubNightRsvp;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Prediction\PredictionBacktest;
use App\Services\Rating\RatingEngine;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Committee overview (can:manage): club counts, what needs attention, quick-create links,
 * recent activity, the prediction model's track record and a full rating recalculation.
 */
new #[Title('Admin overview')] class extends Component {
    /**
     * Columns shown for players here. Never the email address.
     */
    private const PLAYER_COLUMNS = [
        'id', 'name', 'role', 'programme', 'level', 'is_active', 'created_at',
        'singles_rating', 'doubles_rating', 'singles_played', 'doubles_played',
    ];

    public function mount(): void
    {
        Gate::authorize('manage');
    }

    /**
     * @return array{active: int, inactive: int, finished: int, scheduled: int, live: int, tournaments: array<string, int>, nights: int, going: int, waitlist: int}
     */
    #[Computed]
    public function counts(): array
    {
        $matches = BadmintonMatch::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $tournaments = Tournament::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $upcoming = ClubNight::query()->where('ends_at', '>=', now());

        $rsvps = ClubNightRsvp::query()
            ->whereHas('clubNight', fn (Builder $nights) => $nights->where('ends_at', '>=', now()))
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'active' => User::query()->where('is_active', true)->count(),
            'inactive' => User::query()->where('is_active', false)->count(),
            'finished' => (int) ($matches[MatchStatus::Completed->value] ?? 0) + (int) ($matches[MatchStatus::Walkover->value] ?? 0),
            'scheduled' => (int) ($matches[MatchStatus::Scheduled->value] ?? 0),
            'live' => (int) ($matches[MatchStatus::InProgress->value] ?? 0),
            'tournaments' => collect(TournamentStatus::cases())
                ->mapWithKeys(fn (TournamentStatus $status): array => [$status->value => (int) ($tournaments[$status->value] ?? 0)])
                ->all(),
            'nights' => $upcoming->count(),
            'going' => (int) ($rsvps[RsvpStatus::Going->value] ?? 0),
            'waitlist' => (int) ($rsvps[RsvpStatus::Waitlist->value] ?? 0),
        ];
    }

    /**
     * Scheduled matches whose start time has passed without a result.
     *
     * @return EloquentCollection<int, BadmintonMatch>
     */
    #[Computed]
    public function overdueMatches(): EloquentCollection
    {
        return BadmintonMatch::query()
            ->withDetails()
            ->where('matches.status', MatchStatus::Scheduled->value)
            ->whereNotNull('matches.scheduled_at')
            ->where('matches.scheduled_at', '<', now())
            ->orderBy('matches.scheduled_at')
            ->orderBy('matches.id')
            ->limit(12)
            ->get();
    }

    /**
     * Open tournaments whose registration has closed but that have no draw yet.
     *
     * @return EloquentCollection<int, Tournament>
     */
    #[Computed]
    public function drawsDue(): EloquentCollection
    {
        return Tournament::query()
            ->where('status', TournamentStatus::Open->value)
            ->whereDoesntHave('matches')
            ->where(fn (Builder $query) => $query
                ->where('registration_closes_at', '<', now())
                ->orWhere(fn (Builder $query) => $query->whereNull('registration_closes_at')->where('starts_at', '<', now())))
            ->withCount('entries')
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Full club nights in the next seven days (including tonight).
     *
     * @return EloquentCollection<int, ClubNight>
     */
    #[Computed]
    public function fullNights(): EloquentCollection
    {
        return ClubNight::query()
            ->upcoming()
            ->where('starts_at', '<=', now()->addDays(7))
            ->withRsvpCounts()
            ->get()
            ->filter(fn (ClubNight $night): bool => $night->isFull())
            ->values();
    }

    #[Computed]
    public function attentionCount(): int
    {
        return $this->overdueMatches->count() + $this->drawsDue->count() + $this->fullNights->count();
    }

    /**
     * The latest matches added to the system, with who logged them.
     *
     * @return EloquentCollection<int, BadmintonMatch>
     */
    #[Computed]
    public function recentMatches(): EloquentCollection
    {
        return BadmintonMatch::query()
            ->withDetails()
            ->with(['creator:id,name'])
            ->orderByDesc('matches.created_at')
            ->orderByDesc('matches.id')
            ->limit(8)
            ->get();
    }

    /**
     * @return EloquentCollection<int, User>
     */
    #[Computed]
    public function newestMembers(): EloquentCollection
    {
        return User::query()
            ->select(self::PLAYER_COLUMNS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get();
    }

    /**
     * @return array{matches: int, correct: int, accuracy: float|null, brier: float|null, calibration: list<array{bucket: string, predicted: float, actual: float, count: int}>, biggestUpsets: list<array{match_id: int, probability: float}>, skipped: int}
     */
    #[Computed]
    public function backtest(): array
    {
        return PredictionBacktest::run();
    }

    /**
     * The three biggest upsets the model got wrong, as match cards.
     *
     * @return EloquentCollection<int, BadmintonMatch>
     */
    #[Computed]
    public function upsets(): EloquentCollection
    {
        $ids = array_slice(array_column($this->backtest['biggestUpsets'], 'match_id'), 0, 3);

        if ($ids === []) {
            return new EloquentCollection;
        }

        return BadmintonMatch::query()
            ->withDetails()
            ->whereKey($ids)
            ->get()
            ->sortBy(fn (BadmintonMatch $match): int|false => array_search($match->id, $ids, true))
            ->values();
    }

    /**
     * Replay every rated result from scratch (the single source of truth for ratings).
     */
    public function recalculate(): void
    {
        Gate::authorize('manage');

        $started = hrtime(true);
        RatingEngine::recalculate();
        $elapsed = (int) round((hrtime(true) - $started) / 1_000_000);

        Flux::modal('recalculate-ratings')->close();
        Flux::toast(
            variant: 'success',
            heading: __('Ratings recalculated'),
            text: __('Replayed every rated match in :ms ms. Rankings and profiles are up to date.', ['ms' => number_format($elapsed)]),
        );

        unset($this->backtest, $this->upsets, $this->newestMembers);
    }
}; ?>

<div>
    @php
        $counts = $this->counts;
        $t = $counts['tournaments'];
        $backtest = $this->backtest;
        $issues = $this->attentionCount;
    @endphp

    <x-ui.page-hero
        tone="white"
        :breadcrumbs="[__('Admin')]"
        :kicker="__('Committee')"
        :title="__('Admin overview')"
        :intro="__('Everything the committee looks after in one place: what needs a result, a draw or a bigger hall, and what happened lately.')"
    >
        <x-slot:actions>
            <x-ui.button variant="primary" icon="plus" :href="route('matches.create')">{{ __('New match') }}</x-ui.button>
            <x-ui.button variant="outline" icon="trophy" :href="route('tournaments.create')">{{ __('New tournament') }}</x-ui.button>
            <x-ui.button variant="outline" icon="calendar-days" :href="route('club-nights.create')">{{ __('New club night') }}</x-ui.button>
            <x-ui.button variant="outline" icon="user-plus" :href="route('players.create')">{{ __('New player') }}</x-ui.button>
            <x-ui.button variant="ghost" icon="adjustments-horizontal" :href="route('rating-adjustments.index')">{{ __('Rating adjustments') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-hero>

    {{-- ==================================================================== COUNTS --}}
    <x-ui.section tone="grey" id="overview" spacing="sm" :title="__('The club today')" :kicker="now()->format('l j F')">
        <x-smash.stat-row
            :items="[
                ['value' => $counts['active'], 'label' => __('Active players'), 'sublabel' => trans_choice(':count inactive|:count inactive', $counts['inactive'])],
                ['value' => $counts['finished'], 'label' => __('Matches played'), 'sublabel' => __(':scheduled scheduled · :live live', ['scheduled' => $counts['scheduled'], 'live' => $counts['live']])],
                ['value' => array_sum($t), 'label' => __('Tournaments'), 'sublabel' => __(':open open · :playing playing · :done done · :draft draft', ['open' => $t['open'], 'playing' => $t['in_progress'], 'done' => $t['completed'], 'draft' => $t['draft']])],
                ['value' => $counts['nights'], 'label' => __('Upcoming club nights'), 'sublabel' => __(':going RSVPs · :waitlist waitlisted', ['going' => $counts['going'], 'waitlist' => $counts['waitlist']])],
            ]"
            data-admin-counts
        />
    </x-ui.section>

    {{-- =========================================================== NEEDS ATTENTION --}}
    <x-ui.section
        id="attention"
        :kicker="$issues === 0 ? __('All clear') : trans_choice(':count item|:count items', $issues)"
        :title="__('Needs attention')"
    >
        <div class="grid gap-6 lg:grid-cols-3" data-admin-attention>
            {{-- Results missing --}}
            <x-ui.card :highlight="$this->overdueMatches->isEmpty() ? 'green' : 'orange'" padding="lg" class="min-w-0" data-admin-overdue>
                <div class="flex items-baseline justify-between gap-4">
                    <h3 class="font-serif text-h4 font-normal text-heading">{{ __('Results missing') }}</h3>
                    <span class="num text-[28px] leading-none font-extrabold text-ink">{{ $this->overdueMatches->count() }}</span>
                </div>
                <p class="mt-1 text-sm text-ink-muted">{{ __('Scheduled matches whose start time has passed.') }}</p>

                @if ($this->overdueMatches->isEmpty())
                    <p class="mt-6 flex items-center gap-2 text-[15px] text-ink-soft">
                        <flux:icon.check-circle variant="mini" class="size-5 text-win" />
                        {{ __('Every match that should have been played has a result.') }}
                    </p>
                @else
                    <ul class="mt-5 divide-y divide-line border-t border-line" role="list">
                        @foreach ($this->overdueMatches as $match)
                            <li class="py-3" wire:key="overdue-{{ $match->id }}">
                                <a href="{{ route('matches.show', $match) }}" wire:navigate class="block font-medium text-ink hover:underline hover:underline-offset-4">{{ $match->matchup() }}</a>
                                <p class="mt-0.5 text-sm text-ink-muted">
                                    {{ $match->scheduled_at->format('D j M · H:i') }} ·
                                    {{ $match->tournament ? $match->tournament->name.($match->roundLabel() ? ' · '.$match->roundLabel() : '') : __('Friendly') }}
                                </p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <x-ui.button variant="outline" size="sm" icon="play" :href="route('matches.score', $match)">{{ __('Score') }}</x-ui.button>
                                    <x-ui.button variant="ghost" size="sm" icon="pencil-square" :href="route('matches.edit', $match)">{{ __('Enter result') }}</x-ui.button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            {{-- Draws to make --}}
            <x-ui.card :highlight="$this->drawsDue->isEmpty() ? 'green' : 'orange'" padding="lg" class="min-w-0" data-admin-draws>
                <div class="flex items-baseline justify-between gap-4">
                    <h3 class="font-serif text-h4 font-normal text-heading">{{ __('Draws to make') }}</h3>
                    <span class="num text-[28px] leading-none font-extrabold text-ink">{{ $this->drawsDue->count() }}</span>
                </div>
                <p class="mt-1 text-sm text-ink-muted">{{ __('Registration has closed, but there is no draw yet.') }}</p>

                @if ($this->drawsDue->isEmpty())
                    <p class="mt-6 flex items-center gap-2 text-[15px] text-ink-soft">
                        <flux:icon.check-circle variant="mini" class="size-5 text-win" />
                        {{ __('No tournament is waiting for its draw.') }}
                    </p>
                @else
                    <ul class="mt-5 divide-y divide-line border-t border-line" role="list">
                        @foreach ($this->drawsDue as $tournament)
                            <li class="py-3" wire:key="draw-{{ $tournament->id }}">
                                <a href="{{ route('tournaments.show', $tournament) }}" wire:navigate class="block font-medium text-ink hover:underline hover:underline-offset-4">{{ $tournament->name }}</a>
                                <p class="mt-0.5 text-sm text-ink-muted">
                                    {{ __('Closed :date', ['date' => $tournament->registrationDeadline()?->format('D j M · H:i')]) }} ·
                                    {{ trans_choice(':count entry|:count entries', $tournament->entries_count) }}
                                </p>
                                <div class="mt-2">
                                    <x-ui.button variant="outline" size="sm" icon="squares-2x2" :href="route('tournaments.show', $tournament)">{{ __('Make the draw') }}</x-ui.button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            {{-- Full club nights --}}
            <x-ui.card :highlight="$this->fullNights->isEmpty() ? 'green' : 'smash'" padding="lg" class="min-w-0" data-admin-full>
                <div class="flex items-baseline justify-between gap-4">
                    <h3 class="font-serif text-h4 font-normal text-heading">{{ __('Full this week') }}</h3>
                    <span class="num text-[28px] leading-none font-extrabold text-ink">{{ $this->fullNights->count() }}</span>
                </div>
                <p class="mt-1 text-sm text-ink-muted">{{ __('Club nights in the next 7 days with no places left.') }}</p>

                @if ($this->fullNights->isEmpty())
                    <p class="mt-6 flex items-center gap-2 text-[15px] text-ink-soft">
                        <flux:icon.check-circle variant="mini" class="size-5 text-win" />
                        {{ __('Every club night this week still has room.') }}
                    </p>
                @else
                    <ul class="mt-5 divide-y divide-line border-t border-line" role="list">
                        @foreach ($this->fullNights as $night)
                            <li class="flex items-start gap-4 py-3" wire:key="full-{{ $night->id }}">
                                <x-ui.date-badge :date="$night->starts_at" size="sm" />
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('club-nights.show', $night) }}" wire:navigate class="block font-medium text-ink hover:underline hover:underline-offset-4">{{ $night->title }}</a>
                                    <p class="mt-0.5 text-sm text-ink-muted">
                                        {{ $night->starts_at->format('D · H:i') }} · {{ $night->goingCount() }}/{{ $night->capacity }}
                                        @if ($night->waitlistCount() > 0)
                                            · <span class="font-semibold text-loss">{{ trans_choice(':count waiting|:count waiting', $night->waitlistCount()) }}</span>
                                        @endif
                                    </p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <x-ui.button variant="outline" size="sm" icon="users" :href="route('club-nights.show', $night)">{{ __('RSVPs') }}</x-ui.button>
                                        <x-ui.button variant="ghost" size="sm" icon="pencil-square" :href="route('club-nights.edit', $night)">{{ __('Add capacity') }}</x-ui.button>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>
    </x-ui.section>

    {{-- =========================================================== RECENT ACTIVITY --}}
    <x-ui.section tone="grey" id="activity" :kicker="__('Lately')" :title="__('Recent activity')">
        <div class="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <x-ui.table dense data-admin-recent>
                <x-slot:header>
                    <h3 class="font-serif text-h4 font-normal text-heading">{{ __('Latest logged matches') }}</h3>
                    <x-ui.link :href="route('matches.index')">{{ __('All matches') }}</x-ui.link>
                </x-slot:header>
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('Match') }}</th>
                            <th class="text-end">{{ __('Result') }}</th>
                            <th class="max-md:hidden">{{ __('Logged by') }}</th>
                            <th class="text-end max-sm:hidden">{{ __('Added') }}</th>
                            <th class="w-12"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->recentMatches as $match)
                            <tr wire:key="recent-{{ $match->id }}">
                                <td class="min-w-0">
                                    <a href="{{ route('matches.show', $match) }}" wire:navigate class="font-medium text-ink hover:underline hover:underline-offset-4">{{ $match->matchup() }}</a>
                                    <span class="mt-0.5 block text-sm text-ink-muted">
                                        {{ $match->tournament?->name ?? __('Friendly') }} · {{ $match->discipline->label() }}
                                    </span>
                                    <x-smash.status-badge :match="$match" size="sm" class="mt-1.5 sm:hidden" />
                                </td>
                                <td class="text-end whitespace-nowrap">
                                    @if ($match->isFinished() || $match->isLive())
                                        <span class="num text-[17px] font-bold text-ink">{{ $match->scoreline() }}</span>
                                    @endif
                                    <x-smash.status-badge :match="$match" size="sm" class="ms-2 max-sm:hidden" />
                                </td>
                                <td class="text-ink-soft max-md:hidden">{{ $match->creator?->name ?? __('Imported') }}</td>
                                <td class="text-end whitespace-nowrap text-ink-muted max-sm:hidden">{{ $match->created_at?->diffForHumans(short: true) }}</td>
                                <td class="text-end">
                                    <x-ui.button variant="ghost" size="sm" square icon="pencil-square" :href="route('matches.edit', $match)" :aria-label="__('Edit :match', ['match' => $match->matchup()])" />
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-10 text-center text-ink-soft">{{ __('No matches logged yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-ui.table>

            <x-ui.card padding="none" class="min-w-0" data-admin-members>
                <div class="flex items-center justify-between gap-4 border-b border-line px-5 py-4">
                    <h3 class="font-serif text-h4 font-normal text-heading">{{ __('Newest members') }}</h3>
                    <x-ui.link :href="route('players.index')">{{ __('Players') }}</x-ui.link>
                </div>
                <ul class="divide-y divide-line" role="list">
                    @forelse ($this->newestMembers as $member)
                        <li class="flex items-center justify-between gap-4 px-5 py-3" wire:key="member-{{ $member->id }}">
                            <x-smash.player :user="$member" size="sm" class="min-w-0" />
                            <div class="shrink-0 text-end">
                                <p class="text-sm text-ink-muted">{{ $member->created_at?->format('j M') }}</p>
                                <p class="label-caps text-ink-muted">
                                    {{ trans_choice(':count match|:count matches', $member->singles_played + $member->doubles_played) }}
                                </p>
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-10 text-center text-ink-soft">{{ __('No members yet.') }}</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>
    </x-ui.section>

    {{-- ===================================================== MODEL + RECALCULATION --}}
    <x-ui.section id="model" :kicker="__('Ratings and predictions')" :title="__('How good is the model?')"
        :intro="__('Every rated result replayed against the pre-match ratings: how often the favourite won, and how well the percentages match reality.')">
        <x-slot:actions>
            <x-ui.link :href="route('how-it-works')">{{ __('How it works') }}</x-ui.link>
        </x-slot:actions>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div class="min-w-0 space-y-6" data-admin-backtest>
                <x-smash.stat-row
                    :items="[
                        ['value' => $backtest['accuracy'] === null ? '–' : (int) round($backtest['accuracy'] * 100), 'suffix' => $backtest['accuracy'] === null ? null : '%', 'label' => __('Favourite won'), 'sublabel' => __(':correct of :count matches', ['correct' => $backtest['correct'], 'count' => $backtest['matches']])],
                        ['value' => $backtest['brier'] === null ? '–' : number_format($backtest['brier'], 3), 'label' => __('Brier score'), 'sublabel' => __('Lower is better · 0.250 = coin toss')],
                        ['value' => $backtest['matches'], 'label' => __('Matches scored'), 'sublabel' => trans_choice(':count even matchup skipped|:count even matchups skipped', $backtest['skipped'])],
                    ]"
                    size="sm"
                />

                @if ($backtest['calibration'] !== [])
                    <x-ui.table dense>
                        <x-slot:header>
                            <h3 class="font-serif text-h5 font-normal text-heading">{{ __('Calibration') }}</h3>
                            <p class="text-sm text-ink-muted">{{ __('Favourite\'s predicted chance vs how often they won') }}</p>
                        </x-slot:header>
                        <table>
                            <thead>
                                <tr>
                                    <th>{{ __('Predicted') }}</th>
                                    <th class="text-end">{{ __('Average') }}</th>
                                    <th class="text-end">{{ __('Actual') }}</th>
                                    <th class="text-end">{{ __('Matches') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($backtest['calibration'] as $bucket)
                                    <tr wire:key="bucket-{{ $bucket['bucket'] }}">
                                        <td class="font-medium text-ink">{{ $bucket['bucket'] }}</td>
                                        <td class="num text-end text-[17px] font-semibold text-ink">{{ (int) round($bucket['predicted'] * 100) }}%</td>
                                        <td class="num text-end text-[17px] font-semibold text-ink">{{ (int) round($bucket['actual'] * 100) }}%</td>
                                        <td class="num text-end text-[17px] text-ink-soft">{{ $bucket['count'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-ui.table>
                @endif

                @if ($this->upsets->isNotEmpty())
                    <div>
                        <p class="label-caps text-ink-muted">{{ __('Biggest upsets the model missed') }}</p>
                        <div class="mt-3 space-y-3">
                            @foreach ($this->upsets as $match)
                                <x-smash.match-card :match="$match" variant="compact" wire:key="upset-{{ $match->id }}" />
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <x-ui.card highlight="navy" padding="lg" class="min-w-0 self-start" data-admin-recalculate>
                <p class="kicker">{{ __('Maintenance') }}</p>
                <h3 class="mt-2 font-serif text-h4 font-normal text-heading">{{ __('Recalculate all ratings') }}</h3>
                <p class="mt-3 text-[15px] leading-relaxed text-ink-soft">
                    {{ __('Replays every rated match and rating adjustment in order, from a clean 1500 for everyone. Saving or deleting a match already does this, so you only need it after fixing data by hand.') }}
                </p>
                <div class="mt-6">
                    <flux:modal.trigger name="recalculate-ratings">
                        <x-ui.button variant="outline" icon="arrow-path" data-test="recalculate">{{ __('Recalculate ratings') }}</x-ui.button>
                    </flux:modal.trigger>
                </div>
            </x-ui.card>
        </div>
    </x-ui.section>

    <flux:modal name="recalculate-ratings" class="md:w-[30rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Recalculate every rating?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('All singles and doubles ratings, peaks and win counts are rebuilt from the match history. It takes a moment and the result is the same every time.') }}
                </flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><x-ui.button variant="ghost" size="sm">{{ __('Cancel') }}</x-ui.button></flux:modal.close>
                <x-ui.button variant="primary" size="sm" wire:click="recalculate" data-test="confirm-recalculate">{{ __('Recalculate now') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</div>
