<?php

use App\Actions\Tournaments\RegisterForTournament;
use App\Actions\Tournaments\ResetDraw;
use App\Actions\Tournaments\WithdrawFromTournament;
use App\Enums\MatchStatus;
use App\Enums\TournamentStatus;
use App\Models\BadmintonMatch;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use App\Services\Prediction\MatchPredictor;
use App\Services\Tournament\Bracket;
use App\Services\Tournament\DrawGenerator;
use App\Services\Tournament\Standings;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public Tournament $tournament;

    /** overview · draw (knockout) / standings (round robin) · entries · matches */
    #[Url(except: 'overview')]
    public string $tab = 'overview';

    /** Doubles registration: the chosen partner's user id. */
    public string $partnerId = '';

    /** Admin: add an entry on behalf of players. */
    public string $addPlayerId = '';

    public string $addPartnerId = '';

    /**
     * Admin: seeds being edited before the draw, keyed "e{entry id}" ('' = unseeded).
     *
     * @var array<string, string>
     */
    public array $seeds = [];

    /** Admin: the entry waiting for the remove confirmation. */
    public ?int $removeId = null;

    /** Admin: reset the draw and clear the seeds too. */
    public bool $clearSeeds = false;

    public function mount(Tournament $tournament): void
    {
        $this->authorize('view', $tournament);

        $this->tournament = $tournament;
        $this->normaliseTab();
        $this->fillSeeds();
    }

    public function updatedTab(): void
    {
        $this->normaliseTab();
    }

    public function render()
    {
        $t = $this->tournament;

        return $this->view()
            ->title($t->name)
            ->layoutData(['description' => __(':name: :discipline :format at the :venue, :date.', [
                'name' => $t->name,
                'discipline' => strtolower($t->discipline->label()),
                'format' => strtolower($t->format->label()),
                'venue' => $t->venue ?: config('smash.club.venue'),
                'date' => $t->starts_at->format('l j F Y'),
            ])]);
    }

    // ------------------------------------------------------------------
    // Data
    // ------------------------------------------------------------------

    /**
     * The draw by round (knockout rounds or round robin matchdays), everything eager-loaded.
     * Also sets $tournament->matches, so champion(), progress() and roundLabel() are free.
     *
     * @return list<array{round: int, label: string, matches: Collection<int, BadmintonMatch>}>
     */
    #[Computed]
    public function rounds(): array
    {
        return Bracket::for($this->tournament);
    }

    /**
     * Entries in seed order (unseeded in registration order) with player and partner.
     *
     * @return Collection<int, TournamentEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        $entries = $this->tournament->entries()->with(['player', 'partner'])->seeded()->get();
        $entries->each(fn (TournamentEntry $entry) => $entry->setRelation('tournament', $this->tournament));

        // entryCount(), spotsLeft(), isFull() and entryFor() then need no queries.
        $this->tournament->setRelation('entries', $entries);

        return $entries;
    }

    /**
     * The viewer's entry (as player or partner).
     */
    #[Computed]
    public function myEntry(): ?TournamentEntry
    {
        $user = Auth::user();

        return $user ? $this->entries->first(fn (TournamentEntry $entry) => $entry->hasPlayer($user)) : null;
    }

    /**
     * Round robin table (only once a draw exists).
     *
     * @return \Illuminate\Support\Collection<int, \App\Services\Tournament\StandingRow>
     */
    #[Computed]
    public function standings(): \Illuminate\Support\Collection
    {
        return $this->tournament->isRoundRobin() && $this->rounds !== []
            ? Standings::for($this->tournament)
            : collect();
    }

    /**
     * Active players who are not entered yet: doubles partners for the viewer, or players the
     * committee can add. Only loaded when somebody can use them.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function available(): Collection
    {
        $entered = $this->entries->flatMap(fn (TournamentEntry $entry) => [$entry->player_id, $entry->partner_id])->filter()->all();

        return User::query()
            ->active()
            ->whereNotIn('id', $entered)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active', 'singles_rating', 'doubles_rating', 'singles_played', 'doubles_played']);
    }

    /**
     * Draw matches that count (no byes, no cancelled ones).
     *
     * @return \Illuminate\Support\Collection<int, BadmintonMatch>
     */
    #[Computed]
    public function playable(): \Illuminate\Support\Collection
    {
        return collect($this->rounds)
            ->flatMap(fn (array $round) => $round['matches'])
            ->reject(fn (BadmintonMatch $match) => $match->isBye() || $match->status === MatchStatus::Cancelled)
            ->values();
    }

    /**
     * Matches on court right now.
     *
     * @return \Illuminate\Support\Collection<int, BadmintonMatch>
     */
    #[Computed]
    public function live(): \Illuminate\Support\Collection
    {
        return $this->playable->filter(fn (BadmintonMatch $match) => $match->isLive())->values();
    }

    /**
     * The next scheduled matches whose line-up is known, soonest first.
     *
     * @return \Illuminate\Support\Collection<int, BadmintonMatch>
     */
    #[Computed]
    public function fixtures(): \Illuminate\Support\Collection
    {
        return $this->playable
            ->filter(fn (BadmintonMatch $match) => $match->status === MatchStatus::Scheduled && $match->entry_a_id !== null && $match->entry_b_id !== null)
            ->sortBy([
                fn (BadmintonMatch $a, BadmintonMatch $b) => ($a->scheduled_at?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->scheduled_at?->getTimestamp() ?? PHP_INT_MAX),
                fn (BadmintonMatch $a, BadmintonMatch $b) => [$a->round, $a->bracket_position] <=> [$b->round, $b->bracket_position],
            ])
            ->take(4)
            ->values();
    }

    /**
     * Latest results, newest first.
     *
     * @return \Illuminate\Support\Collection<int, BadmintonMatch>
     */
    #[Computed]
    public function results(): \Illuminate\Support\Collection
    {
        return $this->playable
            ->filter(fn (BadmintonMatch $match) => $match->isFinished())
            ->sortByDesc(fn (BadmintonMatch $match) => [$match->played_at?->getTimestamp() ?? 0, $match->round, $match->id])
            ->take(4)
            ->values();
    }

    /**
     * Win chances for the fixtures shown (a few queries each, so only for these).
     *
     * @return array<int, \App\Services\Prediction\MatchPrediction>
     */
    #[Computed]
    public function predictions(): array
    {
        return $this->fixtures->mapWithKeys(fn (BadmintonMatch $match) => [$match->id => MatchPredictor::forMatch($match)])->all();
    }

    /**
     * The first round (or matchday) that still has matches to play, with its first start time.
     *
     * @return array{round: int, label: string, at: ?\Carbon\CarbonImmutable}|null
     */
    #[Computed]
    public function nextRound(): ?array
    {
        $open = $this->playable->reject(fn (BadmintonMatch $match) => $match->isFinished());

        if ($open->isEmpty()) {
            return null;
        }

        $round = (int) $open->min('round');
        $at = $open->where('round', $round)->filter(fn (BadmintonMatch $match) => $match->scheduled_at !== null)->min('scheduled_at');

        return ['round' => $round, 'label' => $this->tournament->roundLabel($round, plural: true), 'at' => $at];
    }

    // ------------------------------------------------------------------
    // Registration (members)
    // ------------------------------------------------------------------

    public function logInToRegister(): void
    {
        session()->put('url.intended', route('tournaments.show', $this->tournament));

        $this->redirectRoute('login', navigate: true);
    }

    public function register(): void
    {
        $user = Auth::user();

        if ($user === null) {
            $this->logInToRegister();

            return;
        }

        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['tournament' => __('Verify your email address first, then register.')]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['tournament' => __('Your player profile is inactive. Ask the committee to reactivate it before you register.')]);
        }

        $partner = null;

        if ($this->tournament->isDoubles()) {
            $partner = $this->findActivePlayer($this->partnerId, 'partner', __('Choose your partner.'));
        }

        RegisterForTournament::handle($this->tournament, $user, $partner);

        $this->partnerId = '';
        Flux::modal('register-pair')->close();
        $this->refreshTournament();

        Flux::toast(
            variant: 'success',
            heading: __("You're in"),
            text: $partner
                ? __(':partner and you are registered for :name.', ['partner' => $partner->name, 'name' => $this->tournament->name])
                : __('You are registered for :name. See you on court.', ['name' => $this->tournament->name]),
        );
    }

    public function withdraw(): void
    {
        $user = Auth::user();
        abort_if($user === null, 403);

        $entry = $this->tournament->entries()->forPlayer($user)->first();

        if ($entry === null) {
            throw ValidationException::withMessages(['tournament' => __('You are not registered for this tournament.')]);
        }

        $entry->setRelation('tournament', $this->tournament);
        WithdrawFromTournament::handle($entry);

        Flux::modal('withdraw-entry')->close();
        $this->refreshTournament();

        Flux::toast(text: __('You have withdrawn from :name.', ['name' => $this->tournament->name]));
    }

    // ------------------------------------------------------------------
    // Committee desk (admins)
    // ------------------------------------------------------------------

    public function openRegistration(): void
    {
        $this->authorize('update', $this->tournament);

        if ($this->tournament->status !== TournamentStatus::Draft) {
            throw ValidationException::withMessages(['status' => __('Registration can only be opened for a draft tournament.')]);
        }

        $this->tournament->update(['status' => TournamentStatus::Open]);
        $this->refreshTournament();

        Flux::toast(variant: 'success', text: __('Registration is open. Members can now see :name and sign up.', ['name' => $this->tournament->name]));
    }

    public function generateDraw(): void
    {
        $this->authorize('manageDraw', $this->tournament);

        try {
            DrawGenerator::generate($this->tournament);
        } finally {
            Flux::modal('make-draw')->close();
        }

        $this->refreshTournament();
        $this->tab = $this->drawTab();
        $count = $this->playable->count();

        Flux::toast(variant: 'success', heading: __('The draw is out'), text: trans_choice(':n match is ready to play.|:n matches are ready to play.', $count, ['n' => $count]));
    }

    public function resetDraw(): void
    {
        $this->authorize('manageDraw', $this->tournament);

        if (! $this->tournament->matches()->exists()) {
            Flux::modal('reset-draw')->close();

            throw ValidationException::withMessages(['draw' => __('There is no draw to reset.')]);
        }

        ResetDraw::handle($this->tournament, $this->clearSeeds);

        $this->clearSeeds = false;
        Flux::modal('reset-draw')->close();
        $this->refreshTournament();
        $this->tab = 'entries';

        Flux::toast(text: __('The draw has been reset and registration is open again.'));
    }

    public function addEntry(): void
    {
        $this->authorize('update', $this->tournament);

        $player = $this->findActivePlayer($this->addPlayerId, 'add.player', __('Choose a player.'));
        $partner = $this->tournament->isDoubles()
            ? $this->findActivePlayer($this->addPartnerId, 'add.partner', __('Choose a partner.'))
            : null;

        try {
            $entry = RegisterForTournament::handle($this->tournament, $player, $partner);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $key) => ['add.'.$key => $messages])->all()
            );
        }

        $this->addPlayerId = '';
        $this->addPartnerId = '';
        $this->refreshTournament();

        Flux::toast(variant: 'success', text: __(':name added.', ['name' => $entry->load(['player', 'partner'])->name()]));
    }

    public function confirmRemove(int $entryId): void
    {
        $this->authorize('update', $this->tournament);

        $this->removeId = $this->tournament->entries()->whereKey($entryId)->value('id');

        if ($this->removeId !== null) {
            Flux::modal('remove-entry')->show();
        }
    }

    public function removeEntry(): void
    {
        $this->authorize('update', $this->tournament);

        $entry = $this->tournament->entries()->with(['player', 'partner'])->find($this->removeId);
        Flux::modal('remove-entry')->close();
        $this->removeId = null;

        if ($entry === null) {
            return;
        }

        $entry->setRelation('tournament', $this->tournament);
        WithdrawFromTournament::handle($entry);
        $this->refreshTournament();

        Flux::toast(text: __(':name removed from the tournament.', ['name' => $entry->name()]));
    }

    public function saveSeeds(): void
    {
        $this->authorize('update', $this->tournament);

        if ($this->tournament->matches()->exists()) {
            throw ValidationException::withMessages(['seeds' => __('The draw is made, so seeds are fixed. Reset the draw to change them.')]);
        }

        $entries = $this->tournament->entries()->get(['id', 'seed']);
        $count = $entries->count();
        $rules = [];
        $attributes = [];

        foreach ($entries as $entry) {
            $rules['seeds.e'.$entry->id] = ['nullable', 'integer', 'min:1', 'max:'.$count];
            $attributes['seeds.e'.$entry->id] = __('seed');
        }

        $this->validate($rules, [], $attributes);

        $given = $entries->mapWithKeys(fn (TournamentEntry $entry) => [
            $entry->id => filled($this->seeds['e'.$entry->id] ?? null) ? (int) $this->seeds['e'.$entry->id] : null,
        ]);
        $duplicates = $given->filter()->duplicates()->unique()->sort()->values();

        if ($duplicates->isNotEmpty()) {
            throw ValidationException::withMessages(['seeds' => trans_choice('Seed :seeds is given more than once.|Seeds :seeds are given more than once.', $duplicates->count(), ['seeds' => $duplicates->join(', ', ' and ')])]);
        }

        foreach ($entries as $entry) {
            if ($entry->seed !== $given[$entry->id]) {
                $entry->update(['seed' => $given[$entry->id]]);
            }
        }

        $this->refreshTournament();

        Flux::toast(variant: 'success', text: __('Seeds saved. Unseeded entries are seeded by rating when the draw is made.'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * The tab key of the draw: "draw" for knockouts, "standings" for round robins.
     */
    protected function drawTab(): string
    {
        return $this->tournament->isRoundRobin() ? 'standings' : 'draw';
    }

    protected function normaliseTab(): void
    {
        $tab = in_array($this->tab, ['draw', 'standings'], true) ? $this->drawTab() : $this->tab;

        $this->tab = in_array($tab, ['overview', $this->drawTab(), 'entries', 'matches'], true) ? $tab : 'overview';
    }

    protected function fillSeeds(): void
    {
        $this->seeds = $this->entries
            ->mapWithKeys(fn (TournamentEntry $entry) => ['e'.$entry->id => $entry->seed === null ? '' : (string) $entry->seed])
            ->all();
    }

    /**
     * Reload the tournament and forget every computed value after a change.
     */
    protected function refreshTournament(): void
    {
        $this->tournament->unsetRelations()->refresh();

        foreach (['rounds', 'entries', 'myEntry', 'standings', 'available', 'playable', 'live', 'fixtures', 'results', 'predictions', 'nextRound'] as $property) {
            unset($this->{$property});
        }

        $this->fillSeeds();
    }

    /**
     * An active player by the id from a select, or a validation error on $key.
     */
    protected function findActivePlayer(string $id, string $key, string $missing): User
    {
        $player = ctype_digit($id) ? User::query()->find((int) $id) : null;

        if ($player === null) {
            throw ValidationException::withMessages([$key => $missing]);
        }

        if (! $player->is_active) {
            throw ValidationException::withMessages([$key => __(':name is not an active player.', ['name' => $player->name])]);
        }

        return $player;
    }
}; ?>

<div>
@php
    $t = $this->tournament;
    $rounds = $this->rounds;
    $entries = $this->entries;
    $myEntry = $this->myEntry;
    $user = auth()->user();
    $isAdmin = $user?->can('manage') ?? false;
    $hasDraw = $rounds !== [];
    $isDoubles = $t->isDoubles();
    $drawTab = $t->isRoundRobin() ? 'standings' : 'draw';
    $count = $entries->count();
    $playable = $this->playable;
    $played = $playable->filter(fn ($m) => $m->isFinished())->count();
    $total = $playable->count();
    $champion = $t->status === \App\Enums\TournamentStatus::Completed ? $t->champion() : null;
    $runnerUp = $champion ? $t->runnerUp() : null;
    $final = $champion && $t->isKnockout() ? $t->finalMatch() : null;
    $nextRound = $this->nextRound;

    $unit = fn (int $n): string => $isDoubles
        ? trans_choice(':n pair|:n pairs', $n, ['n' => $n])
        : trans_choice(':n player|:n players', $n, ['n' => $n]);
    $day = fn ($date): string => $date->format($date->isCurrentYear() ? 'D j M' : 'D j M Y');
    $starts = $t->starts_at;
    $ends = $t->ends_at;
    $dates = $ends === null || $ends->isSameDay($starts)
        ? $day($starts)
        : $day($starts).' – '.$day($ends);
    $times = $ends !== null && $ends->isSameDay($starts) ? $starts->format('H:i').' – '.$ends->format('H:i') : __('First serve :time', ['time' => $starts->format('H:i')]);
    $venue = $t->venue ?: config('smash.club.venue');
    $deadline = $t->registrationDeadline();

    $finalScore = $final && $final->status === \App\Enums\MatchStatus::Completed && $final->winning_side
        ? $final->gamesWon($final->winning_side).'–'.$final->gamesWon($final->winning_side->opposite())
        : null;

    // One-sentence status under the title.
    $summary = match (true) {
        $t->status === \App\Enums\TournamentStatus::Draft => __('Draft: only the committee can see this tournament until registration opens.'),
        $champion !== null && $runnerUp !== null => $t->isKnockout()
            ? ($finalScore
                ? __(':champion won the title, beating :runner :score in the final.', ['champion' => $champion->name(), 'runner' => $runnerUp->name(), 'score' => $finalScore])
                : __(':champion won the title after :runner gave a walkover in the final.', ['champion' => $champion->name(), 'runner' => $runnerUp->name()]))
            : __(':champion topped the table ahead of :runner.', ['champion' => $champion->name(), 'runner' => $runnerUp->name()]),
        $hasDraw && $nextRound !== null => $this->live->isNotEmpty()
            ? trans_choice(':label under way: :n match on court now.|:label under way: :n matches on court now.', $this->live->count(), ['label' => $nextRound['label'], 'n' => $this->live->count()])
            : ($nextRound['at']
                ? __(':label next, from :day at :time.', ['label' => $nextRound['label'], 'day' => $day($nextRound['at']), 'time' => $nextRound['at']->format('H:i')])
                : __(':label next. Times follow soon.', ['label' => $nextRound['label']])),
        $t->isOpenForRegistration() => $t->max_entries
            ? __('Registration is open until :day at :time, with :left of :max places left.', ['day' => $day($deadline), 'time' => $deadline->format('H:i'), 'left' => $t->spotsLeft(), 'max' => $t->max_entries])
            : __('Registration is open until :day at :time.', ['day' => $day($deadline), 'time' => $deadline->format('H:i')]),
        $t->status === \App\Enums\TournamentStatus::Open => __('Registration has closed with :entries. The draw follows before the first serve.', ['entries' => $unit($count)]),
        default => $t->format->description(),
    };

    // Admins: draw boxes open live scoring (both sides known, not finished) or the result editor.
    $boxHref = $isAdmin && Route::has('matches.score') && Route::has('matches.edit')
        ? function ($match) {
            if ($match->isBye()) {
                return false;
            }
            if ($match->isFinished()) {
                return route('matches.edit', $match);
            }

            return $match->entry_a_id && $match->entry_b_id && $match->status !== \App\Enums\MatchStatus::Cancelled
                ? route('matches.score', $match)
                : null;
        }
        : null;

    $tabs = [
        'overview' => [__('Overview'), null],
        $drawTab => [$t->isRoundRobin() ? __('Standings') : __('Draw'), null],
        'entries' => [__('Entries'), $count],
        'matches' => [__('Matches'), $total ?: null],
    ];
@endphp
    <x-ui.page-hero
        :breadcrumbs="[['Tournaments', route('tournaments.index')], $t->name]"
        :kicker="$t->discipline->label().' · '.$t->format->label()"
        :title="$t->name"
        :intro="$summary"
    >
        <x-slot:meta>
            <x-smash.status-badge :status="$t->status" />
            <x-smash.discipline-badge :discipline="$t->discipline" icon size="md" />
            <x-ui.tag tone="grey">{{ $t->best_of === 1 ? __('One game to :p', ['p' => $t->points_per_game]) : __('Best of :n · to :p', ['n' => $t->best_of, 'p' => $t->points_per_game]) }}</x-ui.tag>
            @unless ($t->is_rated)
                <x-ui.tag tone="grey">{{ __('Unrated') }}</x-ui.tag>
            @endunless
        </x-slot:meta>

        @if ($isAdmin)
            <x-slot:actions>
                <x-ui.button variant="outline" icon="pencil-square" :href="route('tournaments.edit', $t)">{{ __('Edit tournament') }}</x-ui.button>
            </x-slot:actions>
        @endif

        <x-slot:aside>
            {{-- @container: long pair names must not widen the hero grid on phones. --}}
            <div class="@container">
            <div class="flex items-start gap-5">
                <x-ui.date-badge :date="$starts" weekday />
                <div class="min-w-0 space-y-1.5 pt-1">
                    <x-ui.meta icon="calendar-days" class="font-semibold text-ink">{{ $dates }}</x-ui.meta>
                    <x-ui.meta icon="clock">{{ $times }}</x-ui.meta>
                    <x-ui.meta icon="map-pin">{{ $venue }}</x-ui.meta>
                </div>
            </div>

            <div class="mt-6 space-y-5 border-t border-line pt-6">
                @if ($champion)
                    <div class="tone-orange">
                        <p class="flex items-center gap-2 font-display text-3xl leading-none font-extrabold text-[var(--tone-ink)] uppercase italic">
                            <flux:icon.trophy class="size-6" />
                            {{ __('Champion') }}
                        </p>
                        <x-smash.player :users="$champion->members()" :discipline="$t->discipline" size="md" class="mt-4 min-w-0" :meta="false" />
                        @if ($runnerUp)
                            <div class="mt-4 flex items-center justify-between gap-3 border-t border-line pt-4">
                                <x-smash.player :users="$runnerUp->members()" :discipline="$t->discipline" size="sm" class="min-w-0 flex-1" :meta="false" />
                                <span class="label-caps shrink-0 text-ink-muted">{{ __('Runner-up') }}</span>
                            </div>
                        @endif
                        @if ($final)
                            <x-ui.link :href="route('matches.show', $final)" variant="promo" class="mt-4">{{ $finalScore ? __('The final, :score', ['score' => $finalScore]) : __('The final') }}</x-ui.link>
                        @endif
                    </div>
                @elseif ($hasDraw)
                    <x-ui.progress
                        :value="$played"
                        :max="max(1, $total)"
                        tone="orange"
                        :label="__('Matches played')"
                        :value-label="$played.' / '.$total"
                    />
                    @if ($nextRound)
                        <x-ui.meta icon="trophy">
                            <span class="font-semibold text-ink">{{ __(':label next', ['label' => $nextRound['label']]) }}</span>
                            @if ($nextRound['at'])
                                · {{ $day($nextRound['at']) }}, {{ $nextRound['at']->format('H:i') }}
                            @endif
                        </x-ui.meta>
                    @endif
                    @if ($myEntry)
                        <x-ui.tag tone="navy" variant="solid" size="sm">{{ __("You're in the draw") }}</x-ui.tag>
                    @endif
                @else
                    @if ($t->max_entries)
                        <x-ui.progress
                            :value="$count"
                            :max="$t->max_entries"
                            capacity
                            :label="__(':count of :max', ['count' => $count, 'max' => $unit($t->max_entries)])"
                            :value-label="$t->isFull() ? __('Full') : trans_choice(':n place left|:n places left', $t->spotsLeft(), ['n' => $t->spotsLeft()])"
                        />
                    @else
                        <x-ui.meta icon="user-group">{{ __(':entries registered · no limit', ['entries' => $unit($count)]) }}</x-ui.meta>
                    @endif
                    @if ($deadline && $t->status !== \App\Enums\TournamentStatus::Draft)
                        <x-ui.meta icon="calendar-days">
                            {{ $deadline->isFuture() ? __('Registration closes :day at :time', ['day' => $day($deadline), 'time' => $deadline->format('H:i')]) : __('Registration closed :day', ['day' => $day($deadline)]) }}
                        </x-ui.meta>
                    @endif

                    @if ($t->status === \App\Enums\TournamentStatus::Draft)
                        <p class="text-sm text-ink-soft">{{ __('Members cannot see this yet. Open registration from the committee desk when the details are final.') }}</p>
                    @else
                        <x-tournaments.registration
                            :tournament="$t"
                            :entry="$myEntry"
                            :partners="$user && $isDoubles && ! $myEntry && $t->isOpenForRegistration() ? $this->available->reject(fn ($u) => $u->is($user)) : null"
                        />
                    @endif
                @endif
            </div>
            </div>
        </x-slot:aside>
    </x-ui.page-hero>

    <x-ui.section spacing="md">
        @if ($isAdmin)
            <x-tournaments.admin-desk
                id="committee-desk"
                class="mb-10 scroll-mt-6"
                :tournament="$t"
                :entry-count="$count"
                :players="! $hasDraw && $t->isOpenForRegistration() ? $this->available : null"
                :played="$played"
                :total="$total"
            />
        @endif

        <x-ui.tabs :label="__('Tournament sections')" class="mb-8">
            @foreach ($tabs as $key => [$label, $badge])
                <x-ui.tabs.item wire:click="$set('tab', '{{ $key }}')" :current="$tab === $key" :count="$badge" wire:key="tab-{{ $key }}">{{ $label }}</x-ui.tabs.item>
            @endforeach
        </x-ui.tabs>

        <div wire:loading.class="opacity-60" wire:target="tab" class="transition-opacity">
            @switch ($tab)
                {{-- ============================ DRAW / STANDINGS ============================ --}}
                @case('draw')
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <x-ui.heading :level="2" size="h3">{{ __('The draw') }}</x-ui.heading>
                        @if ($hasDraw)
                            <p class="text-sm text-ink-muted">
                                {{ __(':entries · seeded by rating', ['entries' => $unit($count)]) }}
                                @php($byes = collect($rounds[0]['matches'] ?? [])->filter(fn ($m) => $m->isBye())->count())
                                @if ($byes > 0)
                                    · {{ trans_choice(':n bye|:n byes', $byes, ['n' => $byes]) }}
                                @endif
                            </p>
                        @endif
                    </div>

                    <x-smash.bracket
                        class="mt-6"
                        :rounds="$rounds"
                        :for="$user"
                        :champion="$champion"
                        :href="$boxHref"
                        :label="__(':name draw', ['name' => $t->name])"
                        :empty="__('The draw is made when registration closes. Seeds go by rating, so the strongest only meet late.')"
                    />

                    @if ($hasDraw)
                        <p class="mt-4 text-sm text-ink-muted">
                            @if ($boxHref)
                                {{ __('Admin view: boxes with both players known open live scoring; finished boxes open the result editor.') }}
                            @else
                                {{ __('Open a box for the match report. Scroll sideways to follow the rounds.') }}
                            @endif
                        </p>
                    @endif
                    @break

                @case('standings')
                    <x-ui.heading :level="2" size="h3">{{ __('Table') }}</x-ui.heading>
                    @if ($hasDraw)
                        <x-ui.table class="mt-6">
                            <table>
                                <thead>
                                    <tr>
                                        <th class="w-14">#</th>
                                        <th>{{ $isDoubles ? __('Pair') : __('Player') }}</th>
                                        <th class="text-end">{{ __('P') }}</th>
                                        <th class="text-end">{{ __('W–L') }}</th>
                                        <th class="text-end max-sm:hidden">{{ __('Games') }}</th>
                                        <th class="text-end max-md:hidden">{{ __('Points') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->standings as $row)
                                        <tr wire:key="s-{{ $row->entry->id }}" @if ($user && $row->entry->hasPlayer($user)) data-current @endif>
                                            <td><x-smash.rank :position="$row->position" size="sm" /></td>
                                            <td class="min-w-0"><x-smash.entry :entry="$row->entry" :seed="false" :rating="false" size="sm" :place="$champion && $row->entry->is($champion) ? 1 : null" /></td>
                                            <td class="num text-end text-[17px] font-semibold text-ink">{{ $row->played }}</td>
                                            <td class="num text-end text-[17px] font-semibold text-ink">{{ $row->won }}–{{ $row->lost }}</td>
                                            <td class="text-end max-sm:hidden">
                                                <span class="num text-[17px] font-semibold text-ink">{{ $row->gamesFor }}–{{ $row->gamesAgainst }}</span>
                                                <x-ui.delta :value="$row->gameDifference()" :arrow="false" size="sm" class="ms-2" />
                                            </td>
                                            <td class="text-end max-md:hidden"><x-ui.delta :value="$row->pointDifference()" :arrow="false" size="sm" /></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </x-ui.table>
                        <p class="mt-3 text-sm text-ink-muted">{{ __('Ranked on wins, then game difference, then point difference. Walkovers count as a win without games.') }}</p>

                        @foreach ($rounds as $round)
                            <div wire:key="md-{{ $round['round'] }}">
                                <x-ui.heading :level="3" size="h4" class="mt-10">{{ $round['label'] }}</x-ui.heading>
                                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                    @foreach ($round['matches'] as $match)
                                        <x-smash.match-card :match="$match" :for="$user" variant="compact" :href="$boxHref ? $boxHref($match) : null" wire:key="mdm-{{ $match->id }}" />
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    @else
                        <x-ui.empty :title="__('No fixtures yet')" variant="bordered" class="mt-6">
                            {{ __('The fixtures are made when registration closes: everybody plays everybody, one matchday per round.') }}
                        </x-ui.empty>
                    @endif
                    @break

                {{-- ================================ ENTRIES ================================ --}}
                @case('entries')
                    @php($canSeed = $isAdmin && ! $hasDraw && $count > 0)
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <x-ui.heading :level="2" size="h3">{{ __('Entries') }}</x-ui.heading>
                            <p class="mt-1 text-sm text-ink-muted">
                                @if ($hasDraw)
                                    {{ __('In seed order. Seeds were set by rating when the draw was made.') }}
                                @else
                                    {{ __('In registration order. Seeds are set by rating when the draw is made.') }}
                                @endif
                            </p>
                        </div>
                        @if ($canSeed)
                            <x-ui.button variant="outline" size="sm" icon="check" wire:click="saveSeeds">{{ __('Save seeds') }}</x-ui.button>
                        @endif
                    </div>

                    @error('seeds')
                        <x-ui.alert tone="danger" class="mt-4">{{ $message }}</x-ui.alert>
                    @enderror
                    @error('tournament')
                        @if ($isAdmin)
                            <x-ui.alert tone="danger" class="mt-4">{{ $message }}</x-ui.alert>
                        @endif
                    @enderror

                    @if ($entries->isEmpty())
                        <x-ui.empty :title="__('No entries yet')" variant="bordered" class="mt-6">
                            {{ $t->isOpenForRegistration() ? __('Be the first to sign up: registration is open.') : __('Entries appear here once registration opens.') }}
                        </x-ui.empty>
                    @else
                        <x-ui.table class="mt-6">
                            <table>
                                <thead>
                                    <tr>
                                        <th>{{ $isDoubles ? __('Seed · pair') : __('Seed · player') }}</th>
                                        <th class="text-end">{{ $isDoubles ? __('Pair rating') : __('Rating') }}</th>
                                        <th class="text-end max-sm:hidden">{{ __('Registered') }}</th>
                                        @if ($canSeed)
                                            <th class="w-28">{{ __('Seed') }}</th>
                                            <th class="w-12"><span class="sr-only">{{ __('Actions') }}</span></th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($entries as $entry)
                                        <tr wire:key="e-{{ $entry->id }}" @if ($user && $entry->hasPlayer($user)) data-current @endif>
                                            <td class="min-w-0">
                                                <x-smash.entry
                                                    :entry="$entry"
                                                    :rating="false"
                                                    size="sm"
                                                    :place="$champion && $entry->is($champion) ? 1 : ($runnerUp && $entry->is($runnerUp) ? 2 : null)"
                                                />
                                            </td>
                                            <td class="text-end">
                                                <x-smash.rating :value="$entry->rating()" :provisional="$entry->members()->contains(fn ($m) => $m->isProvisional($t->discipline))" size="sm" />
                                            </td>
                                            <td class="text-end text-sm whitespace-nowrap text-ink-muted max-sm:hidden">{{ $entry->created_at?->format('j M, H:i') }}</td>
                                            @if ($canSeed)
                                                <td>
                                                    <flux:input
                                                        type="number"
                                                        min="1"
                                                        :max="$count"
                                                        size="sm"
                                                        class="w-20"
                                                        wire:model="seeds.e{{ $entry->id }}"
                                                        :aria-label="__('Seed for :name', ['name' => $entry->name()])"
                                                        placeholder="–"
                                                    />
                                                </td>
                                                <td class="text-end">
                                                    <x-ui.button
                                                        variant="ghost"
                                                        size="sm"
                                                        square
                                                        icon="trash"
                                                        wire:click="confirmRemove({{ $entry->id }})"
                                                        :aria-label="__('Remove :name', ['name' => $entry->name()])"
                                                    />
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </x-ui.table>
                        @if ($canSeed)
                            @foreach ($entries as $entry)
                                @error('seeds.e'.$entry->id)
                                    <flux:error :message="$entry->name().': '.$message" class="mt-2" wire:key="se-{{ $entry->id }}" />
                                @enderror
                            @endforeach
                            <p class="mt-3 text-sm text-ink-muted">{{ __('Leave a seed empty to let the draw seed that entry by rating. Fixed seeds keep their place.') }}</p>
                        @endif
                    @endif
                    @break

                {{-- ================================ MATCHES ================================ --}}
                @case('matches')
                    <x-ui.heading :level="2" size="h3">{{ __('Matches') }}</x-ui.heading>
                    @if (! $hasDraw)
                        <x-ui.empty :title="__('No matches yet')" variant="bordered" class="mt-6">
                            {{ __('Matches appear here as soon as the draw is made.') }}
                        </x-ui.empty>
                    @else
                        @foreach (array_reverse($rounds) as $round)
                            @php($roundMatches = $round['matches']->reject(fn ($m) => $m->isBye()))
                            @php($roundByes = $round['matches']->count() - $roundMatches->count())
                            <div wire:key="mr-{{ $round['round'] }}" class="mt-8 first-of-type:mt-6">
                                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-line pb-2">
                                    <h3 class="font-serif text-h4 text-heading">{{ $round['label'] }}</h3>
                                    <span class="text-sm text-ink-muted">
                                        {{ trans_choice(':n match|:n matches', $roundMatches->count(), ['n' => $roundMatches->count()]) }}
                                        @if ($roundByes > 0)
                                            · {{ trans_choice(':n bye|:n byes', $roundByes, ['n' => $roundByes]) }}
                                        @endif
                                    </span>
                                </div>
                                @if ($roundMatches->isEmpty())
                                    <p class="mt-4 text-sm text-ink-soft">{{ __('Only byes in this round.') }}</p>
                                @else
                                    <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                        @foreach ($roundMatches as $match)
                                            <div class="flex min-w-0 flex-col" wire:key="mm-{{ $match->id }}">
                                                <x-smash.match-card :match="$match" :for="$user" class="flex-1" />
                                                @if ($isAdmin)
                                                    <div class="flex flex-wrap items-center justify-end gap-x-5 gap-y-1 border-x border-b border-line bg-surface-muted px-4 py-2">
                                                        @if (! $match->isFinished() && $match->entry_a_id && $match->entry_b_id && $match->status !== \App\Enums\MatchStatus::Cancelled)
                                                            <x-ui.link :href="route('matches.score', $match)">{{ __('Score live') }}</x-ui.link>
                                                        @endif
                                                        <x-ui.link :href="route('matches.edit', $match)">{{ $match->isFinished() ? __('Edit result') : __('Edit match') }}</x-ui.link>
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    @endif
                    @break

                {{-- ================================ OVERVIEW =============================== --}}
                @default
                    <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,24rem)] lg:gap-14">
                        <div class="min-w-0 space-y-12">
                            @if ($this->live->isNotEmpty())
                                <div>
                                    <div class="flex items-center gap-3">
                                        <x-ui.heading :level="2" size="h3">{{ __('On court now') }}</x-ui.heading>
                                        <x-ui.live />
                                    </div>
                                    <div class="mt-6 grid gap-4 md:grid-cols-2">
                                        @foreach ($this->live as $match)
                                            <x-smash.match-card :match="$match" :for="$user" wire:key="live-{{ $match->id }}" />
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <div>
                                <div class="flex flex-wrap items-end justify-between gap-4">
                                    <x-ui.heading :level="2" size="h3">{{ __('Next matches') }}</x-ui.heading>
                                    @if ($hasDraw)
                                        <x-ui.button variant="link" size="sm" wire:click="$set('tab', 'matches')">{{ __('All matches') }}</x-ui.button>
                                    @endif
                                </div>
                                @if ($this->fixtures->isNotEmpty())
                                    <div class="mt-6 grid gap-4 md:grid-cols-2">
                                        @foreach ($this->fixtures as $match)
                                            <x-smash.match-card :match="$match" :for="$user" :probability="$this->predictions[$match->id] ?? null" wire:key="fx-{{ $match->id }}" />
                                        @endforeach
                                    </div>
                                @else
                                    <x-ui.empty :title="$hasDraw ? __('No matches waiting') : __('No fixtures yet')" variant="bordered" align="start" class="mt-6">
                                        @if (! $hasDraw)
                                            {{ __('Fixtures appear here once the draw is made, with courts, start times and win chances.') }}
                                        @elseif ($t->status === \App\Enums\TournamentStatus::Completed)
                                            {{ __('Every match has been played. See the results below.') }}
                                        @else
                                            {{ __('The next matches are waiting for the current round to finish.') }}
                                        @endif
                                    </x-ui.empty>
                                @endif
                            </div>

                            @if ($this->results->isNotEmpty())
                                <div>
                                    <x-ui.heading :level="2" size="h3">{{ __('Latest results') }}</x-ui.heading>
                                    <div class="mt-6 grid gap-4 md:grid-cols-2">
                                        @foreach ($this->results as $match)
                                            <x-smash.match-card :match="$match" :for="$user" wire:key="rs-{{ $match->id }}" />
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div class="min-w-0 space-y-8">
                            <x-ui.card padding="lg" highlight="navy">
                                <p class="font-serif text-h4 text-heading">{{ __('About') }}</p>
                                @if ($t->description)
                                    <p class="mt-3 text-[15px] leading-relaxed text-ink-soft">{{ $t->description }}</p>
                                @endif
                                <x-ui.facts :columns="1" class="mt-5">
                                    <x-ui.facts.item :label="__('Format')">{{ $t->format->description() }}</x-ui.facts.item>
                                    <x-ui.facts.item :label="__('Scoring')">
                                        {{ $t->best_of === 1 ? __('One game to :p', ['p' => $t->points_per_game]) : __('Best of :n games to :p', ['n' => $t->best_of, 'p' => $t->points_per_game]) }}
                                    </x-ui.facts.item>
                                    <x-ui.facts.item :label="__('Rankings')">
                                        {{ $t->is_rated
                                            ? __('Rated: results count towards the :discipline rankings, weighted :k× a friendly.', ['discipline' => strtolower($t->discipline->label()), 'k' => rtrim(rtrim(number_format((float) config('smash.rating.tournament_multiplier', 1.25), 2), '0'), '.')])
                                            : __('Unrated: results do not change anybody’s rating.') }}
                                    </x-ui.facts.item>
                                    <x-ui.facts.item :label="__('Entries')">
                                        {{ $t->max_entries ? __(':count of :max', ['count' => $count, 'max' => $unit($t->max_entries)]) : $unit($count) }}
                                    </x-ui.facts.item>
                                    <x-ui.facts.item :label="__('Venue')">{{ $venue }}</x-ui.facts.item>
                                </x-ui.facts>
                            </x-ui.card>

                            <div>
                                <p class="font-serif text-h4 text-heading">{{ __('Schedule') }}</p>
                                <x-tournaments.schedule :tournament="$t" :rounds="$rounds" class="mt-4" />
                            </div>
                        </div>
                    </div>
            @endswitch
        </div>
    </x-ui.section>

    @if ($isAdmin)
        <flux:modal name="remove-entry" class="md:w-[28rem]">
            @php($removing = $this->removeId ? $entries->firstWhere('id', $this->removeId) : null)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Remove this entry?') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ $removing
                            ? __(':name will be taken out of :tournament. They can register again while registration is open.', ['name' => $removing->name(), 'tournament' => $t->name])
                            : __('The entry will be taken out of the tournament.') }}
                    </flux:text>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><x-ui.button variant="ghost" size="sm">{{ __('Cancel') }}</x-ui.button></flux:modal.close>
                    <x-ui.button variant="danger" size="sm" icon="trash" wire:click="removeEntry">{{ __('Remove entry') }}</x-ui.button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
