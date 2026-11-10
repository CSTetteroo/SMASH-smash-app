<?php

use App\Actions\ClubNights\JoinClubNight;
use App\Actions\ClubNights\LeaveClubNight;
use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Enums\TournamentStatus;
use App\Models\BadmintonMatch;
use App\Models\ClubNight;
use App\Models\ClubNightRsvp;
use App\Models\Prediction;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use App\Services\Prediction\MatchPrediction;
use App\Services\Prediction\MatchPredictor;
use App\Services\Predictions\PredictionLeaderboard;
use App\Services\Predictions\PredictorRow;
use App\Services\Stats\Achievement;
use App\Services\Stats\Achievements;
use App\Services\Stats\PlayerStats;
use App\Services\Stats\PlayerStatsSummary;
use App\Services\Tournament\Standings;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The member's home base (auth + verified): ratings, the next club night with an inline RSVP,
 * upcoming matches with the model's view and what is at stake, recent results, tournaments,
 * pick'em and achievements. Admins also get a "needs attention" card that links to /admin.
 */
new #[Title('Dashboard')] class extends Component {
    #[Computed]
    public function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    #[Computed]
    public function greeting(): string
    {
        $hour = now()->hour;

        return match (true) {
            $hour >= 5 && $hour < 12 => __('Good morning'),
            $hour >= 12 && $hour < 18 => __('Good afternoon'),
            default => __('Good evening'),
        };
    }

    #[Computed]
    public function season(): string
    {
        $now = now();
        $start = $now->month >= 9 ? $now->year : $now->year - 1;

        return __('Season :from–:to', ['from' => $start, 'to' => substr((string) ($start + 1), -2)]);
    }

    /**
     * No rated match yet in either discipline: show the welcome instead of empty stat cards.
     */
    #[Computed]
    public function newcomer(): bool
    {
        return $this->user->singles_played + $this->user->doubles_played === 0;
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

    // ------------------------------------------------------------------ club nights

    #[Computed]
    public function nextNight(): ?ClubNight
    {
        return ClubNight::query()->upcoming()->withRsvpCounts()->first();
    }

    #[Computed]
    public function myRsvp(): ?ClubNightRsvp
    {
        return $this->nextNight?->rsvpFor($this->user);
    }

    /**
     * My other upcoming RSVPs (after the next night), with my RSVP loaded.
     *
     * @return EloquentCollection<int, ClubNight>
     */
    #[Computed]
    public function otherRsvps(): EloquentCollection
    {
        $userId = $this->user->id;

        return ClubNight::query()
            ->upcoming()
            ->when($this->nextNight, fn (Builder $query, ClubNight $next) => $query->whereKeyNot($next->id))
            ->whereHas('rsvps', fn (Builder $rsvps) => $rsvps->where('user_id', $userId))
            ->with(['rsvps' => fn ($rsvps) => $rsvps->where('user_id', $userId)])
            ->limit(3)
            ->get();
    }

    public function join(int $nightId): void
    {
        $night = ClubNight::query()->findOrFail($nightId);
        $user = $this->user;

        if (Gate::forUser($user)->denies('rsvp', $night)) {
            Flux::toast(variant: 'danger', text: __('This club night has already finished, so RSVPs are closed.'));

            return;
        }

        try {
            $rsvp = JoinClubNight::handle($night, $user);
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());

            return;
        }

        if ($rsvp->isGoing()) {
            Flux::toast(variant: 'success', heading: __('You\'re going'), text: __('See you on :date.', ['date' => $night->starts_at->format('l j F \a\t H:i')]));
        } else {
            Flux::toast(variant: 'warning', heading: __('You\'re on the waitlist'), text: __('You\'re #:position in line and move up automatically when a place frees up.', ['position' => $night->waitlistPosition($user) ?? 1]));
        }

        $this->forgetNights();
    }

    public function leave(int $nightId): void
    {
        Flux::modal('leave-night')->close();

        $night = ClubNight::query()->findOrFail($nightId);
        $user = $this->user;

        if (Gate::forUser($user)->denies('rsvp', $night)) {
            Flux::toast(variant: 'danger', text: __('This club night has already finished, so RSVPs are closed.'));

            return;
        }

        $rsvp = $night->rsvps()->where('user_id', $user->id)->first();

        if ($rsvp === null) {
            Flux::toast(text: __('You hadn\'t RSVP\'d for this club night.'));

            return;
        }

        $handedOn = $rsvp->isGoing() && $night->rsvps()->waitlisted()->exists();

        try {
            LeaveClubNight::handle($night, $user);
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());

            return;
        }

        Flux::toast(text: match (true) {
            $rsvp->isWaitlisted() => __('You\'re off the waitlist.'),
            $handedOn => __('You\'ve left. Your place went to the first person on the waitlist.'),
            default => __('You\'ve left. Changed your mind? RSVP again while there are places.'),
        });

        $this->forgetNights();
    }

    private function forgetNights(): void
    {
        unset($this->nextNight, $this->myRsvp, $this->otherRsvps);
    }

    // ------------------------------------------------------------------ matches

    /**
     * Live first, then scheduled matches soonest first.
     *
     * @return EloquentCollection<int, BadmintonMatch>
     */
    #[Computed]
    public function upcomingMatches(): EloquentCollection
    {
        return BadmintonMatch::query()
            ->withDetails()
            ->forPlayer($this->user)
            ->whereIn('matches.status', [MatchStatus::Scheduled->value, MatchStatus::InProgress->value])
            ->orderByRaw('case when matches.status = ? then 0 else 1 end', [MatchStatus::InProgress->value])
            ->orderByRaw('matches.scheduled_at is null')
            ->orderBy('matches.scheduled_at')
            ->orderBy('matches.id')
            ->limit(4)
            ->get();
    }

    /**
     * Model forecasts for my scheduled matches with both sides known.
     *
     * @return array<int, MatchPrediction>
     */
    #[Computed]
    public function predictions(): array
    {
        return $this->upcomingMatches
            ->filter(fn (BadmintonMatch $match): bool => $match->status === MatchStatus::Scheduled
                && $match->playersOn(Side::A)->isNotEmpty()
                && $match->playersOn(Side::B)->isNotEmpty())
            ->mapWithKeys(fn (BadmintonMatch $match): array => [$match->id => MatchPredictor::forMatch($match)])
            ->all();
    }

    /**
     * @return EloquentCollection<int, BadmintonMatch>
     */
    #[Computed]
    public function recentResults(): EloquentCollection
    {
        return BadmintonMatch::query()
            ->withDetails()
            ->forPlayer($this->user)
            ->finished()
            ->latestFirst()
            ->limit(5)
            ->get();
    }

    // ------------------------------------------------------------------ tournaments

    /**
     * Tournaments I entered (as player or partner): being played first, then open, then results.
     *
     * @return Collection<int, array{tournament: Tournament, note: string, champion: TournamentEntry|null}>
     */
    #[Computed]
    public function tournaments(): Collection
    {
        $user = $this->user;

        return Tournament::query()
            ->visibleTo($user)
            ->whereHas('entries', fn (Builder $entries) => $entries->where(fn (Builder $mine) => $mine->where('player_id', $user->id)->orWhere('partner_id', $user->id)))
            ->withCount('entries')
            ->orderByDesc('starts_at')
            ->get()
            ->sortBy(fn (Tournament $tournament): int => match ($tournament->status) {
                TournamentStatus::InProgress => 0,
                TournamentStatus::Open => 1,
                TournamentStatus::Draft => 2,
                TournamentStatus::Completed => 3,
            })
            ->take(3)
            ->map(function (Tournament $tournament) use ($user): array {
                // One query per tournament for its draw; champion(), progress() and round labels reuse it.
                $tournament->load(['matches.games', 'matches.entryA.player', 'matches.entryA.partner', 'matches.entryB.player', 'matches.entryB.partner']);
                $champion = $tournament->status === TournamentStatus::Completed ? $tournament->champion() : null;

                return ['tournament' => $tournament, 'note' => $this->tournamentNote($tournament, $user, $champion), 'champion' => $champion];
            })
            ->values();
    }

    /**
     * Open tournaments I could still enter.
     *
     * @return EloquentCollection<int, Tournament>
     */
    #[Computed]
    public function openTournaments(): EloquentCollection
    {
        $user = $this->user;

        return Tournament::query()
            ->where('status', TournamentStatus::Open->value)
            ->whereDoesntHave('entries', fn (Builder $entries) => $entries->where(fn (Builder $mine) => $mine->where('player_id', $user->id)->orWhere('partner_id', $user->id)))
            ->withCount('entries')
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Tournament $tournament): bool => $tournament->isOpenForRegistration())
            ->values();
    }

    /**
     * My status in a tournament, from its loaded matches ("Next: Quarter-final v Jonas Weber · Sun 8 Nov · 10:00").
     */
    private function tournamentNote(Tournament $tournament, User $user, ?TournamentEntry $champion): string
    {
        $entry = $tournament->entryFor($user);

        if (! $entry instanceof TournamentEntry) {
            return '';
        }

        $entry->loadMissing(['player', 'partner']);
        $partner = $entry->members()->first(fn (User $member): bool => ! $member->is($user));

        if ($tournament->status === TournamentStatus::Open || $tournament->status === TournamentStatus::Draft) {
            return $partner
                ? __('You\'re in, with :partner.', ['partner' => $partner->name])
                : __('You\'re in. The draw is made when registration closes.');
        }

        $mine = $tournament->matches->filter(fn (BadmintonMatch $match): bool => $match->entry_a_id === $entry->id || $match->entry_b_id === $entry->id);
        $label = fn (BadmintonMatch $match): string => $match->round ? $tournament->roundLabel($match->round) : __('next round');
        $last = $mine->filter(fn (BadmintonMatch $match): bool => $match->winning_side !== null)->sortByDesc('round')->first();

        if ($tournament->status === TournamentStatus::InProgress) {
            $next = $mine
                ->filter(fn (BadmintonMatch $match): bool => in_array($match->status, [MatchStatus::Scheduled, MatchStatus::InProgress], true))
                ->sortBy([['round', 'asc'], ['scheduled_at', 'asc']])
                ->first();

            if ($next instanceof BadmintonMatch) {
                $opponent = $next->entry_a_id === $entry->id ? $next->entryB : $next->entryA;

                return __('Next: :round v :opponent', ['round' => $label($next), 'opponent' => $opponent?->name() ?? __('TBD')])
                    .($next->scheduled_at ? ' · '.$next->scheduled_at->format('D j M · H:i') : '');
            }

            if ($tournament->isKnockout()) {
                return $last instanceof BadmintonMatch && $last->round
                    ? __('Knocked out in the :round. Next time.', ['round' => mb_strtolower($label($last))])
                    : __('Your matches are done. Follow the rest of the draw.');
            }

            return __('Your matches are played. Waiting for the others to finish.');
        }

        // Completed.
        if ($champion !== null && $champion->is($entry)) {
            return __('Champion. Well played.');
        }

        if ($tournament->runnerUp()?->is($entry)) {
            return __('Runner-up. So close.');
        }

        if ($tournament->isRoundRobin()) {
            $row = Standings::for($tournament)->first(fn ($row): bool => $row->entry->is($entry));

            return $row
                ? __('Finished :position of :count.', ['position' => $this->ordinal($row->position), 'count' => $tournament->entryCount()])
                : '';
        }

        return $last instanceof BadmintonMatch && $last->round
            ? __('Reached the :round.', ['round' => mb_strtolower($label($last))])
            : '';
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

    // ------------------------------------------------------------------ pick'em & achievements

    /**
     * @return array{row: PredictorRow|null, predictors: int, open: int, pending: int}
     */
    #[Computed]
    public function pickem(): array
    {
        $user = $this->user;
        $rows = PredictionLeaderboard::rows(0);

        $open = BadmintonMatch::query()
            ->with('participants')
            ->upcoming()
            ->where(fn (Builder $query) => $query->whereNull('matches.scheduled_at')->orWhere('matches.scheduled_at', '>', now()))
            ->whereDoesntHave('participants', fn (Builder $participants) => $participants->where('user_id', $user->id))
            ->whereDoesntHave('predictions', fn (Builder $predictions) => $predictions->where('user_id', $user->id))
            ->get()
            ->filter(fn (BadmintonMatch $match): bool => $match->tournament_id !== null
                ? $match->entry_a_id !== null && $match->entry_b_id !== null
                : $match->participants->contains('side', Side::A) && $match->participants->contains('side', Side::B))
            ->count();

        return [
            'row' => $rows->first(fn (PredictorRow $row): bool => $row->user->is($user)),
            'predictors' => $rows->count(),
            'open' => $open,
            'pending' => Prediction::query()->where('user_id', $user->id)->pending()->count(),
        ];
    }

    /**
     * @return array{earned: int, total: int, recent: list<Achievement>, next: list<Achievement>}
     */
    #[Computed]
    public function achievements(): array
    {
        $all = collect(Achievements::for($this->user));
        $initial = (int) config('smash.rating.initial', 1500);

        $closeness = function (Achievement $achievement) use ($initial): float {
            if ($achievement->progress === null) {
                return 0.0;
            }

            [$current, $target] = [$achievement->progress['current'], $achievement->progress['target']];

            // Peak 1700 counts up from the starting rating, not from zero.
            if ($achievement->key === 'peak_1700') {
                [$current, $target] = [max(0, $current - $initial), max(1, $target - $initial)];
            }

            return $target > 0 ? $current / $target : 0.0;
        };

        return [
            'earned' => $all->where('earned', true)->count(),
            'total' => $all->count(),
            'recent' => $all->where('earned', true)
                ->sortByDesc(fn (Achievement $achievement): int => $achievement->earnedAt?->getTimestamp() ?? 0)
                ->take(3)->values()->all(),
            'next' => $all->where('earned', false)
                ->filter(fn (Achievement $achievement): bool => $achievement->progress !== null)
                ->sortByDesc($closeness)
                ->take(3)->values()->all(),
        ];
    }

    // ------------------------------------------------------------------ admin

    /**
     * Things the committee should look at (admins only), see the admin overview.
     *
     * @return array{overdue: int, draws: int, full: int}|null
     */
    #[Computed]
    public function attention(): ?array
    {
        if (! $this->user->isAdmin()) {
            return null;
        }

        return [
            'overdue' => BadmintonMatch::query()
                ->where('status', MatchStatus::Scheduled->value)
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '<', now())
                ->count(),
            'draws' => Tournament::query()
                ->where('status', TournamentStatus::Open->value)
                ->whereDoesntHave('matches')
                ->where(fn (Builder $query) => $query
                    ->where('registration_closes_at', '<', now())
                    ->orWhere(fn (Builder $query) => $query->whereNull('registration_closes_at')->where('starts_at', '<', now())))
                ->count(),
            'full' => ClubNight::query()
                ->upcoming()
                ->where('starts_at', '<=', now()->addDays(7))
                ->withRsvpCounts()
                ->get()
                ->filter(fn (ClubNight $night): bool => $night->isFull())
                ->count(),
        ];
    }

    /**
     * One-line summary under the greeting.
     */
    #[Computed]
    public function summary(): string
    {
        if ($this->newcomer) {
            return __('Welcome to SMASH. Come to a club night, play a few matches and your ratings appear here.');
        }

        $parts = [];
        $s = $this->singles;
        $d = $this->doubles;

        if ($s->rank !== null && $d->rank !== null) {
            $parts[] = __('You\'re #:singles in singles and #:doubles in doubles.', ['singles' => $s->rank, 'doubles' => $d->rank]);
        } elseif ($s->rank !== null) {
            $parts[] = __('You\'re #:rank in the singles rankings.', ['rank' => $s->rank]);
        } elseif ($d->rank !== null) {
            $parts[] = __('You\'re #:rank in the doubles rankings.', ['rank' => $d->rank]);
        }

        $next = $this->upcomingMatches->first();

        if ($next instanceof BadmintonMatch && $next->isLive()) {
            $parts[] = __('Your match is live right now.');
        } elseif ($next instanceof BadmintonMatch && $next->scheduled_at) {
            $parts[] = __('Next match: :when.', ['when' => $next->scheduled_at->isToday()
                ? __('today at :time', ['time' => $next->scheduled_at->format('H:i')])
                : $next->scheduled_at->format('l j F \a\t H:i')]);
        }

        return $parts === [] ? __('Here\'s where you stand this season.') : implode(' ', $parts);
    }
}; ?>

<div>
    @php
        $user = $this->user;
        $night = $this->nextNight;
        $rsvp = $this->myRsvp;
        $attention = $this->attention;
    @endphp

    <x-ui.page-hero
        :breadcrumbs="[__('Dashboard')]"
        :kicker="now()->format('l j F')"
        :title="$this->greeting.', '.$user->firstName()"
        :intro="$this->summary"
    >
        <x-slot:actions>
            <x-ui.button variant="cta" :href="route('matches.create')">{{ __('Log a match') }}</x-ui.button>
            <x-ui.button variant="outline" :href="route('club-nights.index')">{{ __('Club nights') }}</x-ui.button>
            <x-ui.button variant="ghost" icon="scale" :href="route('predictor')">{{ __('Predictor') }}</x-ui.button>
        </x-slot:actions>

        <x-slot:aside>
            @if ($night)
                <x-home.club-night-card
                    :night="$night"
                    :rsvp="$rsvp"
                    :waitlist-position="$rsvp?->isWaitlisted() ? $night->waitlistPosition($user) : null"
                    :kicker="__('Next club night')"
                    data-dashboard-night
                >
                    <x-slot:actions>
                        @if ($rsvp === null)
                            <x-ui.button variant="primary" wire:click="join({{ $night->id }})" data-test="join-night">
                                {{ $night->isFull() ? __('Join the waitlist') : __('I\'m coming') }}
                            </x-ui.button>
                        @else
                            <flux:modal.trigger name="leave-night">
                                <x-ui.button variant="outline" data-test="leave-night">
                                    {{ $rsvp->isGoing() ? __('Can\'t make it') : __('Leave the waitlist') }}
                                </x-ui.button>
                            </flux:modal.trigger>
                        @endif
                        <x-ui.link :href="route('club-nights.show', $night)">{{ __('Details') }}</x-ui.link>
                    </x-slot:actions>

                    <x-slot:footer>
                        @if ($this->otherRsvps->isNotEmpty())
                            <p class="label-caps text-ink-muted">{{ __('Also on your list') }}</p>
                            <ul class="space-y-2" role="list">
                                @foreach ($this->otherRsvps as $other)
                                    @php $otherRsvp = $other->rsvps->first(); @endphp
                                    <li class="flex items-center justify-between gap-3 text-[15px]" wire:key="other-rsvp-{{ $other->id }}">
                                        <a href="{{ route('club-nights.show', $other) }}" wire:navigate class="min-w-0 text-ink hover:underline hover:underline-offset-4">
                                            <span class="font-medium">{{ $other->starts_at->format('D j M') }}</span>
                                            <span class="text-ink-soft">· {{ $other->title }}</span>
                                        </a>
                                        @if ($otherRsvp)
                                            <x-smash.status-badge :status="$otherRsvp->status" variant="soft" size="sm" class="shrink-0" />
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <x-ui.link :href="route('club-nights.index')">{{ __('All club nights') }}</x-ui.link>
                        @endif
                    </x-slot:footer>
                </x-home.club-night-card>

                @if ($rsvp)
                    <flux:modal name="leave-night" class="md:w-[28rem]">
                        <div class="space-y-6">
                            <div>
                                <flux:heading size="lg">{{ $rsvp->isGoing() ? __('Give up your place?') : __('Leave the waitlist?') }}</flux:heading>
                                <flux:text class="mt-2">
                                    @if ($rsvp->isGoing() && $night->waitlistCount() > 0)
                                        {{ __('The first person on the waitlist gets your place straight away.') }}
                                    @elseif ($rsvp->isGoing())
                                        {{ __('You can RSVP again while there are places left.') }}
                                    @else
                                        {{ __('You lose your spot in the queue for :title.', ['title' => $night->title]) }}
                                    @endif
                                </flux:text>
                            </div>
                            <div class="flex justify-end gap-2">
                                <flux:modal.close><x-ui.button variant="ghost" size="sm">{{ __('Keep it') }}</x-ui.button></flux:modal.close>
                                <x-ui.button variant="danger" size="sm" wire:click="leave({{ $night->id }})" data-test="confirm-leave">
                                    {{ $rsvp->isGoing() ? __('Leave club night') : __('Leave waitlist') }}
                                </x-ui.button>
                            </div>
                        </div>
                    </flux:modal>
                @endif
            @else
                <p class="kicker">{{ __('Next club night') }}</p>
                <p class="mt-3 font-serif text-h4 text-heading">{{ __('New dates are on their way') }}</p>
                <p class="mt-3 text-[15px] leading-relaxed text-ink-soft">{{ __('The committee posts club nights a couple of weeks ahead. Check back soon.') }}</p>
                <div class="mt-6 border-t border-line pt-5">
                    <x-ui.link :href="route('club-nights.index')">{{ __('All club nights') }}</x-ui.link>
                </div>
            @endif
        </x-slot:aside>
    </x-ui.page-hero>

    {{-- ===================================================================== RATINGS --}}
    <x-ui.section id="ratings" :kicker="$this->season" :title="$this->newcomer ? __('Get started') : __('Your ratings')">
        <x-slot:actions>
            <x-ui.link :href="route('players.show', $user)">{{ __('Your profile') }}</x-ui.link>
            <x-ui.link :href="route('rankings.index')">{{ __('Full rankings') }}</x-ui.link>
        </x-slot:actions>

        @if ($attention !== null)
            @php $issues = $attention['overdue'] + $attention['draws'] + $attention['full']; @endphp
            <x-ui.card highlight="navy" padding="md" class="mb-8" data-dashboard-admin>
                <div class="flex flex-wrap items-center justify-between gap-x-8 gap-y-4">
                    <div class="min-w-0">
                        <p class="kicker">{{ __('Committee') }}</p>
                        <p class="mt-1.5 font-serif text-h5 text-heading">
                            {{ $issues === 0 ? __('Nothing needs your attention right now.') : trans_choice(':count thing needs your attention.|:count things need your attention.', $issues) }}
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <x-ui.tag :tone="$attention['overdue'] > 0 ? 'orange' : 'grey'" size="sm">{{ trans_choice(':count result missing|:count results missing', $attention['overdue']) }}</x-ui.tag>
                            <x-ui.tag :tone="$attention['draws'] > 0 ? 'orange' : 'grey'" size="sm">{{ trans_choice(':count draw to make|:count draws to make', $attention['draws']) }}</x-ui.tag>
                            <x-ui.tag :tone="$attention['full'] > 0 ? 'smash' : 'grey'" size="sm">{{ trans_choice(':count full club night this week|:count full club nights this week', $attention['full']) }}</x-ui.tag>
                        </div>
                    </div>
                    <x-ui.button variant="primary" :href="route('admin.index')" icon-trailing="arrow-right">{{ __('Admin overview') }}</x-ui.button>
                </div>
            </x-ui.card>
        @endif

        @if ($this->newcomer)
            <div class="grid gap-x-16 gap-y-10 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]" data-dashboard-welcome>
                <div class="min-w-0 max-w-xl">
                    <p class="font-serif text-h3 text-pretty text-heading">{{ __('Welcome to the club, :name.', ['name' => $user->firstName()]) }}</p>
                    <p class="mt-4 text-[17px] leading-relaxed text-ink-soft">
                        {{ __('You start at :rating in singles and doubles. Your first ten matches count extra, so your rating finds your level quickly. Beginners are very welcome: nobody here was born with a racket in hand.', ['rating' => (int) config('smash.rating.initial', 1500)]) }}
                    </p>
                    <div class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-4">
                        <x-ui.button variant="primary" :href="route('club-nights.index')">{{ __('Find a club night') }}</x-ui.button>
                        <x-ui.link :href="route('how-it-works')">{{ __('How ratings work') }}</x-ui.link>
                    </div>
                </div>
                <ol class="grid gap-x-10 gap-y-8 md:grid-cols-3" role="list">
                    <li><x-ui.step :number="1" :title="__('RSVP for a club night')">{{ __('Pick a Tuesday or Thursday and say you\'re coming. Rackets to borrow at the hall.') }}</x-ui.step></li>
                    <li><x-ui.step :number="2" :title="__('Play and log it')">{{ __('After a match, log the score. Both players see it on their profile straight away.') }}</x-ui.step></li>
                    <li><x-ui.step :number="3" :title="__('Watch your rating move')">{{ __('Win against stronger players and you climb faster. Then try a tournament.') }}</x-ui.step></li>
                </ol>
            </div>
        @else
            <div class="grid gap-6 lg:grid-cols-2">
                @foreach (['singles' => $this->singles, 'doubles' => $this->doubles] as $key => $stats)
                    @php $label = $stats->discipline->label(); @endphp
                    <x-ui.card padding="lg" :highlight="$key === 'singles' ? 'navy' : 'sky'" wire:key="rating-{{ $key }}" data-dashboard-rating="{{ $key }}">
                        <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-4">
                            <div class="min-w-0">
                                <p class="kicker">{{ $label }}</p>
                                @if ($stats->played === 0)
                                    <p class="mt-3 font-serif text-h4 text-heading">{{ __('Not rated yet') }}</p>
                                    <p class="mt-2 max-w-sm text-[15px] text-ink-soft">{{ __('Play your first rated :discipline match to get a rating and a place in the table.', ['discipline' => mb_strtolower($label)]) }}</p>
                                @else
                                    <div class="mt-2 flex flex-wrap items-end gap-x-4 gap-y-1">
                                        <x-smash.rating :value="$stats->rating" :provisional="$stats->provisional" size="xl" :label="__(':discipline rating', ['discipline' => $label])" />
                                        <div class="pb-2">
                                            <x-smash.rating-delta :value="$stats->change30d" :label="__('Last 30 days')" />
                                            <p class="label-caps mt-0.5 text-ink-muted">{{ __('30 days') }}</p>
                                        </div>
                                    </div>
                                @endif
                            </div>
                            @if ($stats->played > 0)
                                <x-smash.rank variant="plain" size="lg" :position="$stats->rank" :of="$stats->rankedPlayers" :label="__('Rank')" class="text-end" />
                            @endif
                        </div>

                        @if ($stats->played > 0)
                            @if (count($stats->ratingHistory) > 1)
                                <x-smash.sparkline :points="$stats->ratingHistory" :height="44" class="mt-6 w-full" area :label="__(':discipline rating trend', ['discipline' => $label])" />
                            @endif

                            <dl class="mt-6 grid grid-cols-3 gap-4 border-t border-line pt-5">
                                <div>
                                    <dt class="label-caps text-ink-muted">{{ __('W–L') }}</dt>
                                    <dd class="num mt-1 text-[22px] font-bold text-ink">{{ $stats->wins }}–{{ $stats->losses }}</dd>
                                </div>
                                <div>
                                    <dt class="label-caps text-ink-muted">{{ __('Win rate') }}</dt>
                                    <dd class="num mt-1 text-[22px] font-bold text-ink">{{ $stats->winRate === null ? '–' : (int) round($stats->winRate * 100).'%' }}</dd>
                                </div>
                                <div>
                                    <dt class="label-caps text-ink-muted">{{ __('Peak') }}</dt>
                                    <dd class="num mt-1 text-[22px] font-bold text-ink">{{ $stats->peak }}</dd>
                                </div>
                            </dl>

                            <div class="mt-5 flex flex-wrap items-center justify-between gap-4">
                                <x-smash.form :results="$stats->form" :label="__('Form')" />
                                <x-ui.link :href="route('rankings.index', $key === 'doubles' ? ['discipline' => 'doubles'] : [])">{{ __(':discipline table', ['discipline' => $label]) }}</x-ui.link>
                            </div>
                        @endif
                    </x-ui.card>
                @endforeach
            </div>
        @endif
    </x-ui.section>

    {{-- ===================================================================== MATCHES --}}
    <x-ui.section tone="grey" id="matches" :kicker="__('On court')" :title="__('Your matches')">
        <x-slot:actions>
            <x-ui.link :href="route('matches.index')">{{ __('All matches') }}</x-ui.link>
        </x-slot:actions>

        <div class="grid gap-x-10 gap-y-12 lg:grid-cols-2">
            <div class="min-w-0" data-dashboard-upcoming>
                <h3 class="font-serif text-h4 font-normal text-heading">{{ __('Coming up') }}</h3>
                <div class="mt-5 space-y-5">
                    @forelse ($this->upcomingMatches as $match)
                        @php
                            $prediction = $this->predictions[$match->id] ?? null;
                            $side = $match->sideOf($user);
                            $win = $prediction && $side ? $prediction->stakeFor($user->id, $side) : null;
                            $lose = $prediction && $side ? $prediction->stakeFor($user->id, $side->opposite()) : null;
                        @endphp
                        <div wire:key="upcoming-{{ $match->id }}">
                            <x-smash.match-card :match="$match" :for="$user" :probability="$prediction" />
                            @if ($win !== null && $lose !== null)
                                <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 px-1 text-sm text-ink-soft" data-dashboard-stakes>
                                    <span>{{ __('At stake for you:') }}</span>
                                    <span class="inline-flex items-center gap-1.5">{{ __('win') }} <x-smash.rating-delta :value="$win" size="sm" :label="__('If you win')" /></span>
                                    <span class="inline-flex items-center gap-1.5">{{ __('lose') }} <x-smash.rating-delta :value="$lose" size="sm" :label="__('If you lose')" /></span>
                                    @if ($prediction->favourite === $side)
                                        <span class="text-ink-muted">· {{ __('the model has you as favourite') }}</span>
                                    @elseif ($prediction->favourite !== null)
                                        <span class="text-ink-muted">· {{ __('an upset win pays more') }}</span>
                                    @endif
                                </p>
                            @endif
                        </div>
                    @empty
                        <x-ui.empty variant="plain" class="bg-surface" :title="__('Nothing scheduled')">
                            {{ __('Challenge someone at the next club night, or enter a tournament to get fixtures here.') }}
                            <x-slot:actions>
                                <x-ui.button variant="outline" size="sm" :href="route('tournaments.index')">{{ __('Tournaments') }}</x-ui.button>
                            </x-slot:actions>
                        </x-ui.empty>
                    @endforelse
                </div>
            </div>

            <div class="min-w-0" data-dashboard-recent>
                <h3 class="font-serif text-h4 font-normal text-heading">{{ __('Recent results') }}</h3>
                <div class="mt-5 space-y-3">
                    @forelse ($this->recentResults as $match)
                        <x-smash.match-card :match="$match" :for="$user" variant="compact" wire:key="recent-{{ $match->id }}" />
                    @empty
                        <x-ui.empty variant="plain" class="bg-surface" :title="__('No results yet')">
                            {{ __('Played a match at a club night? Log it and it counts towards your rating.') }}
                            <x-slot:actions>
                                <x-ui.button variant="outline" size="sm" :href="route('matches.create')">{{ __('Log a match') }}</x-ui.button>
                            </x-slot:actions>
                        </x-ui.empty>
                    @endforelse
                </div>
                @if ($this->recentResults->isNotEmpty())
                    <x-ui.link class="mt-5" :href="route('players.show', $user)">{{ __('All your matches') }}</x-ui.link>
                @endif
            </div>
        </div>
    </x-ui.section>

    {{-- ================================================================= TOURNAMENTS --}}
    <x-ui.section id="tournaments" :kicker="__('Compete')" :title="__('Your tournaments')">
        <x-slot:actions>
            <x-ui.link :href="route('tournaments.index')">{{ __('All tournaments') }}</x-ui.link>
        </x-slot:actions>

        @if ($this->tournaments->isEmpty())
            <x-ui.empty :title="__('You haven\'t entered a tournament yet')" data-dashboard-no-tournaments>
                {{ $this->openTournaments->isNotEmpty()
                    ? __(':name is open for registration. Every level is welcome, and tournament matches count a little extra.', ['name' => $this->openTournaments->first()->name])
                    : __('New tournaments are announced here first. Keep an eye on this space.') }}
                @if ($this->openTournaments->isNotEmpty())
                    <x-slot:actions>
                        <x-ui.button variant="primary" :href="route('tournaments.show', $this->openTournaments->first())">{{ __('View tournament') }}</x-ui.button>
                    </x-slot:actions>
                @endif
            </x-ui.empty>
        @else
            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->tournaments as $item)
                    <x-home.tournament-card
                        :tournament="$item['tournament']"
                        :champion="$item['champion']"
                        :note="$item['note'] ?: null"
                        :cta="$item['tournament']->isOpenForRegistration() ? __('View your entry') : null"
                        wire:key="my-tournament-{{ $item['tournament']->id }}"
                    />
                @endforeach
            </div>

            @if ($this->openTournaments->isNotEmpty())
                <p class="mt-8 flex flex-wrap items-center gap-x-3 gap-y-2 text-[15px] text-ink-soft">
                    <x-ui.tag tone="green" size="sm" dot>{{ __('Open') }}</x-ui.tag>
                    <span>{{ __('Still open for registration:') }}</span>
                    @foreach ($this->openTournaments as $open)
                        <a href="{{ route('tournaments.show', $open) }}" wire:navigate class="link" wire:key="open-{{ $open->id }}">{{ $open->name }}</a>
                    @endforeach
                </p>
            @endif
        @endif
    </x-ui.section>

    {{-- ======================================================= PICK'EM + ACHIEVEMENTS --}}
    @php
        $pickem = $this->pickem;
        $row = $pickem['row'];
        $badges = $this->achievements;
    @endphp
    <x-ui.section tone="grey" id="extras" :kicker="__('Beyond the court')" :title="__('Pick\'em and achievements')">
        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
            <x-ui.card variant="navy" padding="lg" class="min-w-0" data-dashboard-pickem>
                <p class="kicker">{{ __('Pick\'em') }}</p>
                <p class="mt-3 font-serif text-h4 text-pretty text-heading">
                    @if ($row && $row->settled > 0)
                        {{ __('You\'re #:position of :count predictors.', ['position' => $row->position, 'count' => $pickem['predictors']]) }}
                    @elseif ($row)
                        {{ __('Your first picks are in. Points arrive when the matches finish.') }}
                    @else
                        {{ __('Think you can call the winners?') }}
                    @endif
                </p>

                @if ($row)
                    <dl class="mt-6 grid grid-cols-3 gap-4 border-t border-line pt-5">
                        <div>
                            <dt class="label-caps text-ink-muted">{{ __('Points') }}</dt>
                            <dd class="num mt-1 text-[28px] leading-none font-extrabold text-ink">{{ $row->points }}</dd>
                        </div>
                        <div>
                            <dt class="label-caps text-ink-muted">{{ __('Correct') }}</dt>
                            <dd class="num mt-1 text-[28px] leading-none font-extrabold text-ink">{{ $row->correct }}<span class="text-[17px] font-semibold text-ink-muted">/{{ $row->settled }}</span></dd>
                        </div>
                        <div>
                            <dt class="label-caps text-ink-muted">{{ __('Accuracy') }}</dt>
                            <dd class="num mt-1 text-[28px] leading-none font-extrabold text-ink">{{ $row->accuracy === null ? '–' : (int) round($row->accuracy * 100).'%' }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="mt-3 text-[15px] leading-relaxed text-ink-soft">{{ __('Call the winner of upcoming matches before they start: 3 points for the right winner, 5 if you also get the number of games.') }}</p>
                @endif

                <p class="mt-6 text-[15px] text-ink-soft">
                    @if ($pickem['open'] > 0)
                        {{ trans_choice(':count match is waiting for your call.|:count matches are waiting for your call.', $pickem['open']) }}
                    @else
                        {{ __('You\'ve called every open match. New fixtures appear as they are scheduled.') }}
                    @endif
                    @if ($pickem['pending'] > 0)
                        {{ trans_choice(':count pick is still to be decided.|:count picks are still to be decided.', $pickem['pending']) }}
                    @endif
                </p>
                <div class="mt-6">
                    <x-ui.button variant="pill-orange" :href="route('predictions.index')" icon-trailing="arrow-right">
                        {{ $pickem['open'] > 0 ? __('Make your picks') : __('See the leaderboard') }}
                    </x-ui.button>
                </div>
            </x-ui.card>

            <x-ui.card padding="lg" class="min-w-0" data-dashboard-achievements>
                <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2">
                    <h3 class="font-serif text-h4 font-normal text-heading">{{ __('Achievements') }}</h3>
                    <p class="label-caps text-ink-muted">{{ __(':earned of :total unlocked', ['earned' => $badges['earned'], 'total' => $badges['total']]) }}</p>
                </div>

                @if ($badges['recent'] !== [])
                    <p class="label-caps mt-6 text-ink-muted">{{ __('Recently unlocked') }}</p>
                    <div class="mt-3 grid gap-3 md:grid-cols-3">
                        @foreach ($badges['recent'] as $achievement)
                            <x-players.achievement :achievement="$achievement" class="md:flex-col md:gap-3" wire:key="badge-recent-{{ $achievement->key }}" />
                        @endforeach
                    </div>
                @endif

                @if ($badges['next'] !== [])
                    <p class="label-caps mt-6 text-ink-muted">{{ __('Closest to unlock') }}</p>
                    <div class="mt-3 grid gap-3 md:grid-cols-3">
                        @foreach ($badges['next'] as $achievement)
                            <x-players.achievement :achievement="$achievement" class="md:flex-col md:gap-3" wire:key="badge-next-{{ $achievement->key }}" />
                        @endforeach
                    </div>
                @endif

                <x-ui.link class="mt-6" :href="route('players.show', $user).'#achievements'">{{ __('All achievements') }}</x-ui.link>
            </x-ui.card>
        </div>
    </x-ui.section>
</div>
