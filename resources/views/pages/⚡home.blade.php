<?php

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Enums\TournamentStatus;
use App\Models\BadmintonMatch;
use App\Models\ClubNight;
use App\Models\ClubNightRsvp;
use App\Models\MatchPlayer;
use App\Models\Tournament;
use App\Services\Prediction\MatchPredictor;
use App\Services\Rating\Leaderboard;
use App\Services\Stats\ClubStats;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * The club's front door (public). Everything on it is live data: the next club night, live and
 * latest results, the top of both rankings, tournaments, club numbers and a pick'em matchup.
 */
new class extends Component {
    /**
     * Club nights that have not ended yet, soonest first (the first one is the hero card).
     *
     * @return EloquentCollection<int, ClubNight>
     */
    #[Computed]
    public function nights(): EloquentCollection
    {
        return ClubNight::query()->upcoming()->withRsvpCounts()->limit(4)->get();
    }

    #[Computed]
    public function nextNight(): ?ClubNight
    {
        return $this->nights->first();
    }

    #[Computed]
    public function myRsvp(): ?ClubNightRsvp
    {
        $user = auth()->user();

        return $user !== null && $this->nextNight !== null ? $this->nextNight->rsvpFor($user) : null;
    }

    /**
     * @return EloquentCollection<int, BadmintonMatch>
     */
    #[Computed]
    public function liveMatches(): EloquentCollection
    {
        return BadmintonMatch::query()
            ->withDetails()
            ->live()
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit(3)
            ->get();
    }

    /**
     * Completed matches, newest first: together with the live ones, two rows of three cards.
     *
     * @return EloquentCollection<int, BadmintonMatch>
     */
    #[Computed]
    public function latestResults(): EloquentCollection
    {
        return BadmintonMatch::query()
            ->withDetails()
            ->where('status', MatchStatus::Completed->value)
            ->latestFirst()
            ->limit(6 - $this->liveMatches->count())
            ->get();
    }

    #[Computed]
    public function singlesTop(): Collection
    {
        return Leaderboard::for(Discipline::Singles)->take(5);
    }

    #[Computed]
    public function doublesTop(): Collection
    {
        return Leaderboard::for(Discipline::Doubles)->take(5);
    }

    /**
     * Open tournaments (registration still open first), then the ones being played, topped up with
     * the latest completed one so the row never looks empty. Drafts never appear here.
     *
     * @return Collection<int, Tournament>
     */
    #[Computed]
    public function tournaments(): Collection
    {
        $active = Tournament::query()
            ->whereIn('status', [TournamentStatus::Open->value, TournamentStatus::InProgress->value])
            ->withCount('entries')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->sortBy(fn (Tournament $tournament): int => match (true) {
                $tournament->isOpenForRegistration() => 0,
                $tournament->status === TournamentStatus::Open => 1,
                default => 2,
            })
            ->values();

        if ($active->count() < 3) {
            $active = $active->concat(
                Tournament::query()->past()->withCount('entries')->limit(3 - $active->count())->get()
            );
        }

        return $active->take(3)->values();
    }

    /**
     * Per tournament id: the next scheduled match (in progress) or the champion (completed).
     *
     * @return array<int, array{next: BadmintonMatch|null, champion: \App\Models\TournamentEntry|null}>
     */
    #[Computed]
    public function tournamentDetails(): array
    {
        $details = [];

        foreach ($this->tournaments as $tournament) {
            $details[$tournament->id] = [
                'next' => $tournament->status === TournamentStatus::InProgress
                    ? $tournament->matches()
                        ->whereIn('status', [MatchStatus::Scheduled->value, MatchStatus::InProgress->value])
                        ->whereNotNull('scheduled_at')
                        ->orderBy('scheduled_at')
                        ->orderBy('id')
                        ->first()
                    : null,
                'champion' => $tournament->status === TournamentStatus::Completed ? $tournament->champion() : null,
            ];
        }

        return $details;
    }

    /**
     * @return array{members: int, matches: int, games: int, points: int, tournaments: int, club_nights: int, biggest_upset: BadmintonMatch|null, longest_match: BadmintonMatch|null, upsets: int}
     */
    #[Computed]
    public function club(): array
    {
        return ClubStats::summary();
    }

    /**
     * The biggest upset so far, with the rating gap the winner overcame.
     *
     * @return array{match: BadmintonMatch, gap: int, winner: string, loser: string}|null
     */
    #[Computed]
    public function upset(): ?array
    {
        $match = $this->club['biggest_upset'];

        if (! $match instanceof BadmintonMatch || $match->winning_side === null) {
            return null;
        }

        $teamRating = fn (Side $side): float => (float) $match->participants
            ->filter(fn (MatchPlayer $player): bool => $player->side === $side)
            ->avg('rating_before');

        return [
            'match' => $match,
            'gap' => (int) round($teamRating($match->winning_side->opposite()) - $teamRating($match->winning_side)),
            'winner' => $match->sideLabel($match->winning_side),
            'loser' => $match->sideLabel($match->winning_side->opposite()),
        ];
    }

    /**
     * Pick'em teaser: of the next few fixtures between known sides, the closest call.
     *
     * @return array{match: BadmintonMatch, prediction: \App\Services\Prediction\MatchPrediction}|null
     */
    #[Computed]
    public function teaser(): ?array
    {
        $viewer = auth()->user();

        $fixtures = BadmintonMatch::query()
            ->withDetails()
            ->upcoming()
            ->where('scheduled_at', '>', now())
            ->limit(10)
            ->get()
            ->filter(fn (BadmintonMatch $match): bool => $this->sidesKnown($match)
                && ($viewer === null || ! $match->hasParticipant($viewer)))
            ->take(5);

        $best = null;

        foreach ($fixtures as $match) {
            $prediction = MatchPredictor::forMatch($match);

            if ($best === null || abs($prediction->probabilityA - 0.5) < abs($best['prediction']->probabilityA - 0.5)) {
                $best = ['match' => $match, 'prediction' => $prediction];
            }
        }

        return $best;
    }

    /**
     * "Season 2026–27" (seasons start in September).
     */
    #[Computed]
    public function season(): string
    {
        $now = now();
        $start = $now->month >= 9 ? $now->year : $now->year - 1;

        return __('Season :from–:to', ['from' => $start, 'to' => substr((string) ($start + 1), -2)]);
    }

    private function sidesKnown(BadmintonMatch $match): bool
    {
        if ($match->tournament_id !== null) {
            return $match->entry_a_id !== null && $match->entry_b_id !== null;
        }

        return $match->playersOn(Side::A)->isNotEmpty() && $match->playersOn(Side::B)->isNotEmpty();
    }
}; ?>

<div>
    @php
        $viewer = auth()->user();
        $night = $this->nextNight;
        $questionnaire = config('smash.club.questionnaire_url');
        $venue = config('smash.club.venue') ?? 'BUas Sports Hall';
        $results = $this->liveMatches->concat($this->latestResults);
    @endphp

    {{-- ======================================================================== HERO: the poster --}}
    <section class="theme-navy relative isolate overflow-hidden" aria-labelledby="home-title" data-home-hero>
        <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
            <x-smash.court-pattern
                class="absolute top-1/2 right-[-14rem] h-[125%] w-auto -translate-y-1/2 text-white max-md:top-auto max-md:right-[-11rem] max-md:bottom-[-5rem] max-md:h-[70%] max-md:translate-y-0 max-md:opacity-50 lg:right-[-4rem] xl:right-[2%]"
            />
        </div>

        <div class="container-site pt-12 pb-14 lg:pt-14 lg:pb-28">
            <div class="max-w-3xl">
                <p class="kicker">{{ __('BUas Badminton Club') }}</p>

                <h1 id="home-title" class="mt-4 font-display text-[clamp(4rem,1.6rem+7.6vw,8.5rem)] leading-[0.84] font-extrabold tracking-[-0.005em] text-white uppercase italic">
                    <span class="block">SMASH it</span>
                    <span class="block">forward<span class="text-smash-500">.</span></span>
                </h1>

                <p class="mt-7 max-w-2xl text-intro text-ink-soft">
                    {{ __('The first official badminton club of Breda University of Applied Sciences. Tuesday and Thursday evenings, every level welcome, ELO rankings that move after every match and tournaments all season.') }}
                </p>
            </div>
        </div>
    </section>

    {{-- ====================================================== HERO: actions + the next club night --}}
    <div class="bg-canvas">
        <div class="container-site grid items-start gap-x-16 gap-y-10 pt-8 pb-12 lg:grid-cols-[minmax(0,1fr)_minmax(22rem,27rem)] lg:pt-10 lg:pb-16">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    @auth
                        <x-ui.button variant="cta" size="lg" :href="route('dashboard')">{{ __('Your dashboard') }}</x-ui.button>
                    @else
                        <x-ui.button variant="cta" size="lg" :href="route('register')">{{ __('Join the club') }}</x-ui.button>
                    @endauth
                    <x-ui.button variant="outline" size="lg" :href="route('rankings.index')">{{ __('See the rankings') }}</x-ui.button>
                </div>

                <ul class="mt-9 grid max-w-2xl gap-x-8 gap-y-3 sm:grid-cols-2" role="list">
                    <x-ui.meta as="li" icon="calendar-days">{{ __('Tuesday & Thursday evenings') }}</x-ui.meta>
                    <x-ui.meta as="li" icon="map-pin">{{ $venue }}</x-ui.meta>
                    <x-ui.meta as="li" icon="user-group">{{ __('Every level, from first rally to competition') }}</x-ui.meta>
                    <x-ui.meta as="li" icon="chart-bar">{{ __('Separate singles and doubles ratings') }}</x-ui.meta>
                </ul>

                @guest
                    <p class="mt-8 text-[15px] text-ink-soft">
                        {{ __('Already a member?') }}
                        <a href="{{ route('login') }}" wire:navigate class="link">{{ __('Log in') }}</a>
                    </p>
                @endguest
            </div>

            <aside class="relative z-10 min-w-0 bg-surface p-6 shadow-card sm:p-8 lg:-mt-36 dark:border dark:border-line" aria-label="{{ __('Next club night') }}">
                @if ($night)
                    <x-home.club-night-card
                        :night="$night"
                        :rsvp="$this->myRsvp"
                        :waitlist-position="$this->myRsvp?->isWaitlisted() ? $night->waitlistPosition($viewer) : null"
                        :kicker="__('Next club night')"
                    >
                        <x-slot:actions>
                            @if ($this->myRsvp)
                                <x-ui.button variant="outline" :href="route('club-nights.show', $night)">{{ __('Manage RSVP') }}</x-ui.button>
                            @else
                                <x-ui.button variant="primary" :href="route('club-nights.show', $night)" icon-trailing="arrow-right">
                                    {{ $night->isFull() ? __('Join the waitlist') : __('RSVP for this night') }}
                                </x-ui.button>
                            @endif
                        </x-slot:actions>
                        <x-slot:footer>
                            <x-ui.link variant="serif" :href="route('how-it-works')">{{ __('First time? Here is how SMASH works') }}</x-ui.link>
                            <x-ui.link :href="route('club-nights.index')" class="flex">{{ __('All club nights') }}</x-ui.link>
                        </x-slot:footer>
                    </x-home.club-night-card>
                @else
                    <p class="kicker">{{ __('Next club night') }}</p>
                    <p class="mt-3 font-serif text-h4 text-heading">{{ __('New dates are on their way') }}</p>
                    <p class="mt-3 text-[15px] leading-relaxed text-ink-soft">{{ __('The committee posts club nights a couple of weeks ahead. We play on Tuesday and Thursday evenings in the :venue.', ['venue' => $venue]) }}</p>
                    <div class="mt-6 border-t border-line pt-5">
                        <x-ui.link :href="route('club-nights.index')">{{ __('All club nights') }}</x-ui.link>
                    </div>
                @endif
            </aside>
        </div>
    </div>

    {{-- ================================================================== LIVE + LATEST RESULTS --}}
    <x-ui.section tone="grey" id="results" :kicker="__('From the courts')" :title="__('Latest results')">
        <x-slot:actions>
            <x-ui.link :href="route('matches.index')">{{ __('All matches') }}</x-ui.link>
        </x-slot:actions>

        @if ($this->liveMatches->isNotEmpty())
            <p class="mb-5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[15px] text-ink-soft" data-home-live>
                <x-ui.live :label="__('Live now')" />
                <span>{{ trans_choice(':count match is being played right now.|:count matches are being played right now.', $this->liveMatches->count()) }}</span>
            </p>
        @endif

        @if ($results->isEmpty())
            <x-ui.empty :title="__('The first rally is still to come')" variant="plain" class="bg-surface">
                {{ __('Results appear here as soon as the first match is played. Come to a club night and make history.') }}
            </x-ui.empty>
        @else
            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($results as $match)
                    {{-- Phones get four cards; the rest is one tap away under "All matches". --}}
                    <x-smash.match-card :match="$match" :for="$viewer" @class(['max-md:hidden' => $loop->index >= 4]) wire:key="result-{{ $match->id }}" />
                @endforeach
            </div>
        @endif
    </x-ui.section>

    {{-- ================================================================================ RANKINGS --}}
    <x-ui.section
        id="rankings"
        :kicker="$this->season"
        :title="__('Top of the rankings')"
        :intro="__('Separate ELO ratings for singles and doubles, updated after every rated match. Beat a higher-rated player and you climb faster.')"
    >
        <x-slot:actions>
            <x-ui.link :href="route('head-to-head')">{{ __('Head-to-head') }}</x-ui.link>
            <x-ui.button variant="outline" :href="route('rankings.index')">{{ __('Full rankings') }}</x-ui.button>
        </x-slot:actions>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-home.ranking-card :title="__('Singles')" :rows="$this->singlesTop" :href="route('rankings.index')" :for="$viewer" />
            <x-home.ranking-card :title="__('Doubles')" :rows="$this->doublesTop" :href="route('rankings.index', ['discipline' => 'doubles'])" :for="$viewer" />
        </div>
    </x-ui.section>

    {{-- ============================================================================= TOURNAMENTS --}}
    <x-ui.section
        tone="grey"
        id="tournaments"
        :kicker="__('Compete')"
        :title="__('Tournaments')"
        :intro="__('Knockouts and round robins for every level. Enter on your own, or find a partner for the doubles.')"
    >
        <x-slot:actions>
            <x-ui.link :href="route('tournaments.index')">{{ __('All tournaments') }}</x-ui.link>
        </x-slot:actions>

        @if ($this->tournaments->isEmpty())
            <x-ui.empty :title="__('No tournaments on the calendar yet')" variant="plain" class="bg-surface">
                {{ __('The committee announces new tournaments here first. Keep an eye on this space.') }}
            </x-ui.empty>
        @else
            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->tournaments as $tournament)
                    <x-home.tournament-card
                        :tournament="$tournament"
                        :next="$this->tournamentDetails[$tournament->id]['next'] ?? null"
                        :champion="$this->tournamentDetails[$tournament->id]['champion'] ?? null"
                        wire:key="tournament-{{ $tournament->id }}"
                    />
                @endforeach
            </div>
        @endif
    </x-ui.section>

    {{-- ========================================================================== HOW IT WORKS --}}
    <x-ui.section id="how" :kicker="__('Three steps')" :title="__('How SMASH works')">
        <x-slot:actions>
            <x-ui.link :href="route('how-it-works')">{{ __('How ratings work') }}</x-ui.link>
        </x-slot:actions>

        <ol class="grid gap-x-10 gap-y-10 md:grid-cols-3" role="list">
            <li>
                <x-ui.step :number="1" :title="__('Come to a club night')">
                    {{ __('Tuesday and Thursday evenings in the :venue. RSVP so we know you are coming, then rotate on and off court with players of every level.', ['venue' => $venue]) }}
                </x-ui.step>
            </li>
            <li>
                <x-ui.step :number="2" :title="__('Play and get rated')">
                    {{ __('Log the matches you play. Every rated result moves your ELO rating, with separate ratings for singles and doubles. Your first ten matches settle your level quickly.') }}
                </x-ui.step>
            </li>
            <li>
                <x-ui.step :number="3" :title="__('Climb the rankings and enter tournaments')">
                    {{ __('Chase the top of the table, settle scores in a head-to-head and enter club tournaments, from Welcome Week to the winter doubles.') }}
                </x-ui.step>
            </li>
        </ol>
    </x-ui.section>

    {{-- ======================================================================== CLUB IN NUMBERS --}}
    <x-ui.section tone="grey" id="numbers" :kicker="__('So far this season')" :title="__('The club in numbers')">
        <x-smash.stat-row
            :items="[
                ['value' => number_format($this->club['members']), 'label' => __('Members')],
                ['value' => number_format($this->club['matches']), 'label' => __('Matches played')],
                ['value' => number_format($this->club['games']), 'label' => __('Games')],
                ['value' => number_format($this->club['points']), 'label' => __('Points played')],
            ]"
            size="lg"
        />

        @if ($this->upset)
            <div class="mt-10 grid items-center gap-x-12 gap-y-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                <div class="min-w-0">
                    <p class="kicker">{{ __('Biggest upset so far') }}</p>
                    <p class="mt-3 font-serif text-h3 text-pretty text-heading">
                        {{ __(':winner beat :loser despite a rating gap of :gap points.', ['winner' => $this->upset['winner'], 'loser' => $this->upset['loser'], 'gap' => $this->upset['gap']]) }}
                    </p>
                    <p class="mt-3 text-[15px] leading-relaxed text-ink-soft">
                        {{ trans_choice('That is one of :count upsets this season. On a good night, anyone can beat anyone.|That is one of :count upsets this season. On a good night, anyone can beat anyone.', $this->club['upsets']) }}
                    </p>
                </div>
                <x-smash.match-card :match="$this->upset['match']" wire:key="upset-{{ $this->upset['match']->id }}" />
            </div>
        @endif
    </x-ui.section>

    {{-- ================================================================================ PICK'EM --}}
    <x-ui.section id="pickem" :kicker="__('Pick\'em')" :title="__('Who wins?')">
        <div class="grid items-start gap-x-16 gap-y-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]">
            <div class="min-w-0 max-w-xl">
                <p class="text-[17px] leading-relaxed text-ink-soft">
                    {{ __('Call the winner of upcoming matches before they start. A right winner scores 3 points, and 5 if you also call the number of games. Our model puts a number on every matchup, from ratings, recent form and head-to-heads.') }}
                </p>
                <div class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-4">
                    <x-ui.button variant="primary" :href="route('predictions.index')">{{ __("Play pick'em") }}</x-ui.button>
                    <x-ui.link :href="route('predictor')">{{ __('Try the predictor') }}</x-ui.link>
                </div>
            </div>

            @if ($this->teaser)
                @php
                    $teaserMatch = $this->teaser['match'];
                    $teaserPrediction = $this->teaser['prediction'];
                @endphp
                <x-ui.card variant="navy" padding="lg" class="min-w-0" data-home-teaser>
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                        <p class="kicker">
                            {{ $teaserMatch->tournament ? $teaserMatch->tournament->name.($teaserMatch->roundLabel() ? ' · '.$teaserMatch->roundLabel() : '') : __('Challenge match') }}
                        </p>
                        <x-smash.discipline-badge :discipline="$teaserMatch->discipline" />
                    </div>
                    <p class="mt-3 font-serif text-h4 text-heading">
                        {{ $teaserMatch->scheduled_at?->isToday() ? __('Today') : $teaserMatch->scheduled_at?->format('l j F') }}
                        <span class="text-ink-muted">· {{ $teaserMatch->scheduled_at?->format('H:i') }}{{ $teaserMatch->court ? ' · '.__('Court :court', ['court' => $teaserMatch->court]) : '' }}</span>
                    </p>

                    <div class="mt-6 grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-3 sm:gap-5">
                        <x-smash.player :match="$teaserMatch" side="a" rating :meta="false" align="end" class="min-w-0" />
                        <span class="num text-lg font-bold text-ink-muted uppercase">vs</span>
                        <x-smash.player :match="$teaserMatch" side="b" rating :meta="false" class="min-w-0" />
                    </div>

                    <x-smash.win-probability class="mt-7" :prediction="$teaserPrediction" :match="$teaserMatch" :caption="__('Model forecast')" />

                    <div class="mt-7 flex flex-wrap items-center justify-between gap-4 border-t border-line pt-5">
                        <p class="text-[15px] text-ink-soft">{{ __('Think the model is wrong? Prove it.') }}</p>
                        <x-ui.button variant="pill-orange" :href="route('predictions.index').'#match-'.$teaserMatch->id" icon-trailing="arrow-right">{{ __('Make your pick') }}</x-ui.button>
                    </div>
                </x-ui.card>
            @else
                <x-ui.card variant="navy" padding="lg" class="min-w-0">
                    <p class="kicker">{{ __('Pick\'em') }}</p>
                    <p class="mt-3 font-serif text-h4 text-heading">{{ __('No fixtures to call right now') }}</p>
                    <p class="mt-3 text-[15px] leading-relaxed text-ink-soft">{{ __('When the next challenge or tournament matches are scheduled, you can call the winners here. Meanwhile, ask the predictor who would win between any two players.') }}</p>
                    <div class="mt-6">
                        <x-ui.button variant="pill-orange" :href="route('predictor')" icon-trailing="arrow-right">{{ __('Open the predictor') }}</x-ui.button>
                    </div>
                </x-ui.card>
            @endif
        </div>
    </x-ui.section>

    {{-- ============================================================ QUESTIONNAIRE (when configured) --}}
    @if ($questionnaire)
        <x-ui.section tone="grey" id="questionnaire" spacing="sm">
            <div class="flex flex-wrap items-center justify-between gap-x-12 gap-y-6">
                <div class="min-w-0 max-w-2xl">
                    <p class="kicker">{{ __('Help shape the club') }}</p>
                    <h2 class="ornament mt-2.5 font-serif text-h3 text-heading">{{ __('Tell us what you want from SMASH') }}</h2>
                    <p class="mt-5 text-[17px] leading-relaxed text-ink-soft">{{ __('A few quick questions about when you can play, your level and what you would like to see. The committee uses the answers to plan club nights and tournaments.') }}</p>
                </div>
                <x-ui.button variant="outline" :href="$questionnaire" external icon-trailing="arrow-up-right">{{ __('Answer the questionnaire') }}</x-ui.button>
            </div>
        </x-ui.section>
    @endif

    {{-- ================================================================================== JOIN --}}
    <x-ui.section :tone="$questionnaire ? 'white' : 'grey'" id="join" spacing="lg">
        <div class="grid items-start gap-x-16 gap-y-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div class="min-w-0 max-w-xl">
                @auth
                    <p class="kicker">{{ __('See you on court') }}</p>
                    <x-ui.heading :level="2" class="mt-2.5">{{ __('Keep smashing it forward') }}</x-ui.heading>
                    <p class="mt-6 text-[17px] leading-relaxed text-ink-soft">{{ __('Played a match? Log it so the ratings stay right. Know someone who plays? Bring them to the next club night.') }}</p>
                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        <x-ui.button variant="cta" :href="route('matches.create')">{{ __('Log a match') }}</x-ui.button>
                        <x-ui.button variant="outline" :href="route('club-nights.index')">{{ __('Club nights') }}</x-ui.button>
                    </div>
                @else
                    <p class="kicker">{{ __('Join SMASH') }}</p>
                    <x-ui.heading :level="2" class="mt-2.5">{{ __('Your first club night is one click away') }}</x-ui.heading>
                    <p class="mt-6 text-[17px] leading-relaxed text-ink-soft">{{ __('Create an account, RSVP for a club night and play your first rated match this week. Complete beginners, returners and competition players all welcome.') }}</p>
                    <div class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-4">
                        <x-ui.button variant="cta" :href="route('register')">{{ __('Join the club') }}</x-ui.button>
                        <x-ui.link :href="route('login')">{{ __('I already have an account') }}</x-ui.link>
                    </div>
                @endauth
            </div>

            <div class="min-w-0">
                <p class="label-caps text-ink-muted">{{ __('Coming up') }}</p>
                @if ($this->nights->isEmpty())
                    <p class="mt-4 text-[15px] text-ink-soft">{{ __('New club nights are announced soon.') }}</p>
                @else
                    <ul class="mt-3 divide-y divide-line border-y border-line" role="list">
                        @foreach ($this->nights->take(3) as $upcoming)
                            <li wire:key="night-{{ $upcoming->id }}">
                                <a href="{{ route('club-nights.show', $upcoming) }}" wire:navigate class="group/night flex items-center gap-5 py-4 transition-colors hover:bg-surface-muted sm:px-2">
                                    <x-ui.date-badge :date="$upcoming->starts_at" size="sm" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block font-serif text-[19px] leading-snug text-heading group-hover/night:underline group-hover/night:decoration-1 group-hover/night:underline-offset-4">{{ $upcoming->title }}</span>
                                        <span class="mt-0.5 block text-sm text-ink-muted">{{ $upcoming->starts_at->format('l · H:i') }} – {{ $upcoming->ends_at->format('H:i') }}</span>
                                    </span>
                                    <span @class([
                                        'shrink-0 text-end text-sm font-semibold',
                                        'text-loss' => $upcoming->isFull(),
                                        'text-ink' => ! $upcoming->isFull(),
                                    ])>
                                        {{ $upcoming->isFull() ? __('Full') : trans_choice(':count place left|:count places left', $upcoming->spotsLeft()) }}
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </x-ui.section>
</div>
