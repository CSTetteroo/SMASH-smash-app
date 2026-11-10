<?php

use App\Actions\Matches\DeleteMatch;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\MatchGame;
use App\Models\Prediction;
use App\Services\Prediction\MatchPrediction;
use App\Services\Prediction\MatchPredictor;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    public const RELATIONS = [
        'participants.user',
        'games',
        'tournament',
        'entryA.player',
        'entryA.partner',
        'entryB.player',
        'entryB.partner',
        'creator',
    ];

    #[Locked]
    public BadmintonMatch $match;

    public function mount(BadmintonMatch $match): void
    {
        $this->match = $match->load(self::RELATIONS);
    }

    public function render()
    {
        $this->match->loadMissing(self::RELATIONS);

        return $this->view()
            ->title($this->title())
            ->layoutData(['description' => $this->summary()]);
    }

    /**
     * Delete a friendly (admins only; tournament matches belong to the draw).
     */
    public function delete(): void
    {
        Gate::authorize('delete', $this->match);

        try {
            DeleteMatch::handle($this->match);
        } catch (ValidationException $exception) {
            Flux::modal('delete-match')->close();
            Flux::toast(variant: 'danger', text: collect($exception->errors())->flatten()->first() ?? __('This match cannot be deleted.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Match deleted. Ratings have been recalculated.'));

        $this->redirectRoute('matches.index', navigate: true);
    }

    // ------------------------------------------------------------------
    // Labels
    // ------------------------------------------------------------------

    /**
     * "Lucas Janssen vs Jonas Weber" · doubles: "Van Dijk & De Boer vs Tan & Nguyen".
     */
    public function title(): string
    {
        return $this->sideName(Side::A).' '.__('vs').' '.$this->sideName(Side::B);
    }

    public function sideName(Side $side, bool $short = false): string
    {
        $players = $this->playersOn($side);

        if ($players->isEmpty()) {
            return $this->match->sideLabel($side);
        }

        if ($players->count() > 1) {
            return $players->map(fn ($user) => Str::ucfirst($user->lastName()))->join(' & ');
        }

        return $short ? $players->first()->firstName() : $players->first()->name;
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\User>
     */
    public function playersOn(Side $side): \Illuminate\Support\Collection
    {
        $entry = $this->match->tournament_id !== null ? $this->match->entryOn($side) : null;

        return ($entry !== null ? $entry->members() : $this->match->playersOn($side))->values();
    }

    public function lineUpKnown(): bool
    {
        $perSide = $this->match->discipline->playersPerSide();

        return $this->playersOn(Side::A)->count() === $perSide && $this->playersOn(Side::B)->count() === $perSide;
    }

    /**
     * One sentence about the match, for the hero intro and the meta description.
     */
    public function summary(): string
    {
        $match = $this->match;
        $where = collect([
            $match->court ? __('court :n', ['n' => $match->court]) : null,
            $match->venue,
        ])->filter()->join(', ');

        if ($match->status === MatchStatus::Completed && $match->winning_side !== null) {
            $winner = $match->winning_side;
            $how = match (true) {
                $match->best_of === 1 => __('in a single game'),
                $match->gamesWon($winner->opposite()) === 0 => __('in straight games'),
                default => __('after a deciding game'),
            };

            return __(':winner beat :loser :score :how.', [
                'winner' => $this->sideName($winner),
                'loser' => $this->sideName($winner->opposite()),
                'score' => $match->gamesWon($winner).'–'.$match->gamesWon($winner->opposite()),
                'how' => $how,
            ]);
        }

        return match ($match->status) {
            MatchStatus::Walkover => $match->winning_side !== null
                ? __(':winner went through on a walkover. No games were played.', ['winner' => $this->sideName($match->winning_side)])
                : __('Decided by a walkover. No games were played.'),
            MatchStatus::InProgress => $where !== ''
                ? __('On court now (:where). The score updates by itself.', ['where' => $where])
                : __('On court now. The score updates by itself.'),
            MatchStatus::Cancelled => __('This match was called off and will not be played.'),
            default => $match->scheduled_at !== null
                ? __(':when at :time:where.', [
                    'when' => $match->scheduled_at->format('l j F'),
                    'time' => $match->scheduled_at->format('H:i'),
                    'where' => $where !== '' ? ', '.$where : '',
                ])
                : __('The date for this match is still to be confirmed.'),
        };
    }

    // ------------------------------------------------------------------
    // Numbers
    // ------------------------------------------------------------------

    /**
     * Pre-match model view: before the match for finished ones, current form for the rest.
     */
    #[Computed]
    public function prediction(): ?MatchPrediction
    {
        if ($this->match->status === MatchStatus::Cancelled || $this->match->status === MatchStatus::Walkover || ! $this->lineUpKnown()) {
            return null;
        }

        return MatchPredictor::forMatch($this->match);
    }

    /**
     * Side A's chance from the current score (live matches only).
     */
    #[Computed]
    public function liveChance(): ?float
    {
        return $this->match->isLive() && $this->lineUpKnown() ? MatchPredictor::liveProbability($this->match) : null;
    }

    /**
     * The pick'em community: how many backed each side, the viewer's own pick, and how many got it right.
     *
     * @return array{total: int, a: int, b: int, share: float|null, mine: Prediction|null, correct: int|null}
     */
    #[Computed]
    public function pickem(): array
    {
        $counts = $this->match->predictions()
            ->toBase()
            ->selectRaw('predicted_side, count(*) as aggregate')
            ->groupBy('predicted_side')
            ->pluck('aggregate', 'predicted_side');

        $a = (int) ($counts[Side::A->value] ?? 0);
        $b = (int) ($counts[Side::B->value] ?? 0);
        $total = $a + $b;
        $winner = $this->match->status === MatchStatus::Completed ? $this->match->winning_side : null;

        return [
            'total' => $total,
            'a' => $a,
            'b' => $b,
            'share' => $total > 0 ? $a / $total : null,
            'mine' => Auth::check() ? $this->match->predictions()->where('user_id', Auth::id())->first() : null,
            'correct' => $winner === null ? null : ($winner === Side::A ? $a : $b),
        ];
    }

    /**
     * Quick facts for a finished match with games.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function facts(): array
    {
        $match = $this->match;
        $games = $match->games;

        if ($games->isEmpty()) {
            return [];
        }

        $target = (int) $match->points_per_game;
        $pointsA = (int) $games->sum('side_a_points');
        $pointsB = (int) $games->sum('side_b_points');

        /** @var MatchGame $longest */
        $longest = $games->sortByDesc(fn (MatchGame $game) => [$game->side_a_points + $game->side_b_points, -$game->number])->first();
        /** @var MatchGame $closest */
        $closest = $games->sortBy(fn (MatchGame $game) => [abs($game->side_a_points - $game->side_b_points), $game->number])->first();
        $extended = $games->filter(fn (MatchGame $game) => max($game->side_a_points, $game->side_b_points) > $target)->count();

        $facts = [
            __('Total points') => __(':total points · :a–:b', ['total' => $pointsA + $pointsB, 'a' => $pointsA, 'b' => $pointsB]),
            __('Longest game') => __('Game :n · :score (:total points)', ['n' => $longest->number, 'score' => $longest->score(), 'total' => $longest->side_a_points + $longest->side_b_points]),
            __('Closest game') => __('Game :n · :score', ['n' => $closest->number, 'score' => $closest->score()]),
        ];

        if ($match->best_of > 1 && $match->isFinished()) {
            $decider = $games->count() === $match->best_of && $match->best_of > 1;
            $facts[__('Decider')] = $decider
                ? __('Yes · game :n went :score', ['n' => $games->last()->number, 'score' => $games->last()->score()])
                : __('No · won in straight games');
        }

        $facts[__('Extra points')] = $extended === 0
            ? __('No game went past :target', ['target' => $target])
            : trans_choice(':count game went past :target|:count games went past :target', $extended, ['count' => $extended, 'target' => $target]);

        if ($match->winning_side !== null && $games->count() > 1 && $games->first()->winner() !== $match->winning_side) {
            $facts[__('Comeback')] = __(':name lost game one and still won', ['name' => $this->sideName($match->winning_side)]);
        }

        return $facts;
    }
}; ?>

@php
    $match = $this->match;
    $status = $match->status;
    $finished = $match->isFinished();
    $completed = $status === MatchStatus::Completed;
    $live = $match->isLive();
    $scheduled = $status === MatchStatus::Scheduled;
    $isTournament = $match->tournament_id !== null;
    $prediction = $this->prediction;
    $moment = $finished ? ($match->played_at ?? $match->scheduled_at) : ($match->scheduled_at ?? $match->played_at);
    $names = ['a' => $this->sideName(Side::A), 'b' => $this->sideName(Side::B)];
    $firstNames = ['a' => $this->sideName(Side::A, true), 'b' => $this->sideName(Side::B, true)];

    $kicker = $isTournament
        ? collect([$match->tournament?->name, $match->roundLabel()])->filter()->join(' · ')
        : __('Friendly').' · '.$match->discipline->label();

    $highlight = match ($status) {
        MatchStatus::InProgress => 'smash',
        MatchStatus::Completed => 'navy',
        MatchStatus::Walkover => 'orange',
        MatchStatus::Cancelled => 'grey',
        default => 'sky',
    };

    $canScore = ($scheduled || $live) && $this->lineUpKnown() && Gate::allows('score', $match);

    // Upset: the model's underdog won.
    $winnerChance = $completed && $prediction && $match->winning_side ? $prediction->probabilityFor($match->winning_side) : null;
    $upset = $winnerChance !== null && $prediction->favourite !== null && $prediction->favourite !== $match->winning_side;
@endphp

<div @if ($live) wire:poll.5s @endif>
    <x-ui.page-hero
        :breadcrumbs="[[__('Matches'), route('matches.index')], $this->title()]"
        :kicker="$kicker"
        :title="$this->title()"
        :intro="$this->summary()"
    >
        <x-slot:meta>
            <x-smash.status-badge :match="$match" />
            <x-smash.discipline-badge :discipline="$match->discipline" size="md" />
            @unless ($match->is_rated)
                <x-ui.tag tone="grey" icon="academic-cap">{{ __('Practice match') }}</x-ui.tag>
            @endunless
            @if ($upset)
                <x-ui.tag tone="smash" icon="bolt">{{ __('Upset') }}</x-ui.tag>
            @endif
        </x-slot:meta>

        <x-slot:actions>
            @if ($canScore)
                <x-ui.button variant="cta" :href="route('matches.score', $match)" data-test="score-button">{{ $live ? __('Score this match') : __('Start scoring') }}</x-ui.button>
            @endif
            @can('update', $match)
                <x-ui.button variant="outline" icon="pencil-square" :href="route('matches.edit', $match)" data-test="edit-button">{{ __('Edit') }}</x-ui.button>
            @endcan
            @can('delete', $match)
                <flux:modal.trigger name="delete-match">
                    <x-ui.button variant="danger-outline" icon="trash" data-test="delete-button">{{ __('Delete') }}</x-ui.button>
                </flux:modal.trigger>
            @elsecan('manage')
                @if ($isTournament && $match->tournament)
                    <p class="flex items-center gap-2 text-sm text-ink-soft" data-test="managed-note">
                        <flux:icon.lock-closed variant="micro" class="size-4 text-ink-muted" />
                        <span>{{ __('Managed by the tournament:') }} <a href="{{ route('tournaments.show', $match->tournament) }}" class="link" wire:navigate>{{ $match->tournament->name }}</a></span>
                    </p>
                @endif
            @endcan
        </x-slot:actions>

        <x-slot:aside>
            <div class="flex gap-5">
                @if ($moment)
                    <x-ui.date-badge :date="$moment" weekday />
                @else
                    <div class="flex w-16 shrink-0 items-center justify-center border border-line py-5 label-caps text-ink-muted">{{ __('TBC') }}</div>
                @endif
                <div class="min-w-0 space-y-2">
                    <p class="kicker">{{ $finished ? __('Played') : ($live ? __('On court') : __('Starts')) }}</p>
                    @if ($moment)
                        <x-ui.meta icon="clock">{{ $moment->format('l j F · H:i') }}</x-ui.meta>
                    @else
                        <x-ui.meta icon="clock">{{ __('Date to be confirmed') }}</x-ui.meta>
                    @endif
                    <x-ui.meta icon="map-pin">{{ collect([$match->court ? __('Court :n', ['n' => $match->court]) : null, $match->venue ?? config('smash.club.venue')])->filter()->join(' · ') }}</x-ui.meta>
                </div>
            </div>
            <dl class="mt-6 grid grid-cols-2 gap-x-6 gap-y-4 border-t border-line pt-5 text-[15px]">
                <div class="col-span-2 min-w-0">
                    <dt class="label-caps text-ink-muted">{{ $isTournament ? __('Tournament') : __('Type') }}</dt>
                    <dd class="mt-1 font-medium text-ink">
                        @if ($isTournament && $match->tournament)
                            <a href="{{ route('tournaments.show', $match->tournament) }}" class="link" wire:navigate>{{ $match->tournament->name }}</a>
                            @if ($match->roundLabel())<span class="text-ink-soft"> · {{ $match->roundLabel() }}</span>@endif
                        @else
                            {{ __('Friendly') }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="label-caps text-ink-muted">{{ __('Format') }}</dt>
                    <dd class="mt-1 font-medium text-ink">{{ trans_choice('Best of :count|Best of :count', $match->best_of, ['count' => $match->best_of]) }} · {{ __('to :n', ['n' => $match->points_per_game]) }}</dd>
                </div>
                <div>
                    <dt class="label-caps text-ink-muted">{{ __('Rankings') }}</dt>
                    <dd class="mt-1 font-medium text-ink">{{ $match->is_rated ? __('Rated') : __('Not rated') }}</dd>
                </div>
            </dl>
        </x-slot:aside>
    </x-ui.page-hero>

    {{-- ============================================================== SCOREBOARD --}}
    <x-ui.section spacing="md" id="scoreboard">
        <x-ui.card variant="shadow" padding="lg" :highlight="$highlight" @class(['opacity-70' => $status === MatchStatus::Cancelled])>
            <x-matches.scoreboard :match="$match" />
        </x-ui.card>

        @if ($scheduled && $isTournament && ! $this->lineUpKnown() && $match->tournament)
            <x-ui.alert tone="info" icon="arrows-pointing-in" :title="__('Line-up to be decided')" class="mt-6" data-test="tbd-note">
                {{ __('The players come through from the earlier rounds of :tournament.', ['tournament' => $match->tournament->name]) }}
                <a href="{{ route('tournaments.show', $match->tournament) }}" class="link" wire:navigate>{{ __('See the draw') }}</a>
            </x-ui.alert>
        @endif

        @if ($status === MatchStatus::Walkover)
            <x-ui.alert tone="orange" icon="flag" :title="__('Walkover')" class="mt-6">
                {{ __('No games were played, and walkovers never change anyone\'s rating.') }}
            </x-ui.alert>
        @elseif ($status === MatchStatus::Cancelled)
            <x-ui.alert tone="grey" icon="no-symbol" :title="__('Cancelled')" class="mt-6">
                {{ __('This match was called off. Fancy a rematch? Log a new one when you play it.') }}
            </x-ui.alert>
        @endif

        @if ($match->notes)
            <figure class="mt-6 border-s-4 border-line-strong bg-surface-muted px-5 py-4">
                <figcaption class="label-caps text-ink-muted">{{ __('Notes') }}</figcaption>
                <p class="mt-2 whitespace-pre-line text-[15px] leading-relaxed text-ink-soft">{{ $match->notes }}</p>
            </figure>
        @endif
    </x-ui.section>

    {{-- ============================================================== FINISHED: RATINGS & ODDS --}}
    @if ($completed)
        <x-ui.section tone="grey" :kicker="__('After the match')" :title="__('Ratings and odds')" id="ratings">
            <div class="grid items-start gap-6 lg:grid-cols-2">
                <x-ui.card padding="none" data-test="rating-changes">
                    <div class="border-b border-line px-6 py-5">
                        <p class="font-serif text-h4 text-heading">{{ __('Rating changes') }}</p>
                        <p class="mt-1 text-sm text-ink-muted">
                            {{ $match->is_rated
                                ? __(':discipline rating before and after this match.', ['discipline' => $match->discipline->label()])
                                : __('Practice match — doesn\'t count for ELO.') }}
                        </p>
                    </div>
                    @if ($match->is_rated)
                        <x-ui.table flush :hover="false">
                            <table>
                                <thead>
                                    <tr>
                                        <th>{{ __('Player') }}</th>
                                        <th class="text-end">{{ __('Before') }}</th>
                                        <th class="text-end">{{ __('After') }}</th>
                                        <th class="text-end">{{ __('Change') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($match->participants->sortBy(fn ($mp) => [$mp->side->value, $mp->id]) as $mp)
                                        <tr wire:key="mp-{{ $mp->id }}" @if (auth()->id() === $mp->user_id) data-current @endif>
                                            <td class="min-w-0"><x-smash.player :user="$mp->user" size="sm" :meta="false" /></td>
                                            <td class="text-end"><x-smash.rating :value="$mp->rating_before" size="sm" muted /></td>
                                            <td class="text-end"><x-smash.rating :value="$mp->rating_after" size="sm" /></td>
                                            <td class="text-end"><x-smash.rating-delta :participant="$mp" variant="chip" /></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </x-ui.table>
                    @else
                        <div class="px-6 py-6 text-[15px] text-ink-soft">
                            {{ __('The result counts for the record books and profiles, but nobody\'s rating moved.') }}
                        </div>
                    @endif
                </x-ui.card>

                <x-ui.card padding="lg" data-test="pre-match">
                    <p class="font-serif text-h4 text-heading">{{ __('Before the match') }}</p>
                    @if ($prediction)
                        <x-smash.win-probability class="mt-5" :prediction="$prediction" :match="$match" :caption="__('Pre-match win chance')" />

                        @if ($upset)
                            <div class="tone-smash mt-6 border-s-4 border-[var(--tone)] bg-[var(--tone-soft)] px-5 py-4" data-test="upset">
                                <p class="font-display text-3xl font-extrabold uppercase italic leading-none text-[var(--tone-ink)]">{{ __('Upset') }}</p>
                                <p class="mt-2 text-[15px] text-ink-soft">
                                    {{ __(':name won with a pre-match win chance of just :percent %.', ['name' => $names[$match->winning_side->value], 'percent' => $prediction->percentFor($match->winning_side)]) }}
                                </p>
                            </div>
                        @elseif ($prediction->favourite === null)
                            <p class="mt-6 text-[15px] text-ink-soft">{{ __('A coin flip on paper: the model had it at 50–50.') }}</p>
                        @else
                            <p class="mt-6 text-[15px] text-ink-soft">
                                {{ __('The favourite delivered: the model gave :name a win chance of :percent % before the match.', ['name' => $names[$match->winning_side->value], 'percent' => $prediction->percentFor($match->winning_side)]) }}
                            </p>
                        @endif
                    @else
                        <p class="mt-3 text-[15px] text-ink-soft">{{ __('No pre-match odds for this one.') }}</p>
                    @endif

                    @if ($this->pickem['total'] > 0)
                        <p class="mt-5 flex items-start gap-2 border-t border-line pt-4 text-sm text-ink-soft" data-test="pickem-result">
                            <flux:icon.hand-raised variant="mini" class="mt-px size-5 shrink-0 text-orange-500 dark:text-orange-400" />
                            <span>
                                {{ trans_choice('Pick\'em: :count player made a pick|Pick\'em: :count players made a pick', $this->pickem['total'], ['count' => $this->pickem['total']]) }}
                                · {{ trans_choice(':count got it right|:count got it right', (int) $this->pickem['correct'], ['count' => (int) $this->pickem['correct']]) }}
                            </span>
                        </p>
                    @endif
                </x-ui.card>
            </div>
        </x-ui.section>
    @endif

    {{-- ============================================================== GAMES (finished or live) --}}
    @if (($completed || $live) && $match->games->isNotEmpty())
        <x-ui.section :tone="$completed ? 'white' : 'grey'" :kicker="$live ? __('Live') : __('Breakdown')" :title="__('Game by game')" id="games">
            <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <x-ui.card padding="lg">
                    <x-matches.game-breakdown :match="$match" />
                </x-ui.card>

                @if ($live)
                    <x-ui.card padding="lg" data-test="live-chance">
                        <p class="font-serif text-h4 text-heading">{{ __('Win chance right now') }}</p>
                        @if ($this->liveChance !== null)
                            <x-smash.win-probability class="mt-5" :a="$this->liveChance" :match="$match" :confidence="false" :caption="__('From the current score')" />
                        @endif
                        @if ($prediction)
                            <p class="mt-5 border-t border-line pt-4 text-sm text-ink-soft">
                                {{ __('Before the match: :a :pa % · :b :pb %', ['a' => $firstNames['a'], 'pa' => $prediction->percentFor(Side::A), 'b' => $firstNames['b'], 'pb' => $prediction->percentFor(Side::B)]) }}
                            </p>
                        @endif
                    </x-ui.card>
                @else
                    <x-ui.card padding="lg" data-test="match-facts">
                        <p class="font-serif text-h4 text-heading">{{ __('Match facts') }}</p>
                        <x-ui.facts class="mt-5" :items="$this->facts" :columns="1" />
                    </x-ui.card>
                @endif
            </div>
        </x-ui.section>
    @endif

    {{-- ============================================================== SCHEDULED: PREVIEW --}}
    @if ($scheduled && $prediction)
        <x-ui.section tone="grey" :kicker="__('Match preview')" :title="__('Who wins?')" id="preview">
            <div class="grid items-start gap-6 lg:grid-cols-2">
                <x-ui.card padding="lg" data-test="prediction">
                    <x-smash.win-probability :prediction="$prediction" :match="$match" :caption="__('Win chance')" />
                    <dl class="mt-6 divide-y divide-line border-t border-line">
                        @foreach ($prediction->factors as $factor)
                            <div class="grid gap-1 py-3 sm:grid-cols-[9rem_minmax(0,1fr)]" wire:key="factor-{{ $factor['key'] }}">
                                <dt class="label-caps text-ink-muted">{{ $factor['label'] }}</dt>
                                <dd class="text-[15px] text-ink-soft">{{ $factor['detail'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="mt-4 text-sm text-ink-muted">
                        {{ __('Typical game for the favourite:') }}
                        <span class="num text-[17px] font-bold text-ink">{{ $prediction->expectedGameScore[0] }}–{{ $prediction->expectedGameScore[1] }}</span>
                    </p>
                    <div class="mt-4 flex flex-wrap gap-x-6 gap-y-3">
                        @if ($this->lineUpKnown())
                            @php
                                $sideA = $this->playersOn(Side::A);
                                $sideB = $this->playersOn(Side::B);
                                $predictorUrl = route('predictor', array_filter([
                                    'd' => $match->discipline->value,
                                    'a1' => $sideA->get(0)?->id,
                                    'a2' => $sideA->get(1)?->id,
                                    'b1' => $sideB->get(0)?->id,
                                    'b2' => $sideB->get(1)?->id,
                                    'bo' => $match->best_of,
                                    'ppg' => $match->points_per_game,
                                ]));
                            @endphp
                            <x-ui.link variant="promo" :href="$predictorUrl" data-test="open-predictor">{{ __('Open in the predictor') }}</x-ui.link>
                        @endif
                        <x-ui.link variant="promo" :href="route('how-it-works')">{{ __('How the predictor works') }}</x-ui.link>
                    </div>
                </x-ui.card>

                <x-ui.card padding="lg" data-test="score-distribution">
                    <p class="font-serif text-h4 text-heading">{{ __('Likely scores') }}</p>
                    <p class="mt-1 text-sm text-ink-muted">{{ __('How often each scoreline comes up when the model plays this match out.') }}</p>
                    <x-matches.score-distribution class="mt-6" :prediction="$prediction" :names="$names" />
                </x-ui.card>
            </div>
        </x-ui.section>

        <x-ui.section :kicker="__('Ratings')" :title="__('What\'s at stake')" id="stakes">
            <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                @if ($match->is_rated)
                    <x-ui.table :hover="false" data-test="stakes">
                        <table>
                            <thead>
                                <tr>
                                    <th>{{ __('Player') }}</th>
                                    <th class="text-end max-sm:hidden">{{ __('Rating') }}</th>
                                    <th class="text-end">{{ __('If they win') }}</th>
                                    <th class="text-end">{{ __('If they lose') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ([Side::A, Side::B] as $side)
                                    @foreach ($this->playersOn($side) as $player)
                                        <tr wire:key="stake-{{ $player->id }}" @if (auth()->id() === $player->id) data-current @endif>
                                            <td class="min-w-0"><x-smash.player :user="$player" size="sm" :meta="false" /></td>
                                            <td class="text-end max-sm:hidden"><x-smash.rating :user="$player" :discipline="$match->discipline" size="sm" /></td>
                                            <td class="text-end"><x-smash.rating-delta :value="$prediction->stakeFor($player->id, $side)" size="sm" /></td>
                                            <td class="text-end"><x-smash.rating-delta :value="$prediction->stakeFor($player->id, $side->opposite())" size="sm" /></td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                    </x-ui.table>
                @else
                    <x-ui.alert tone="grey" icon="academic-cap" :title="__('Practice match — doesn\'t count for ELO')">
                        {{ __('Nobody\'s rating changes, whatever the score.') }}
                    </x-ui.alert>
                @endif

                <x-ui.card variant="navy" padding="lg" data-test="pickem">
                    <x-ui.kicker>{{ __('Pick\'em') }}</x-ui.kicker>
                    <p class="mt-2 font-serif text-h4">{{ __('Who\'s your money on?') }}</p>
                    @if ($this->pickem['total'] > 0)
                        <x-smash.win-probability
                            class="mt-5"
                            :a="$this->pickem['share']"
                            :match="$match"
                            variant="compact"
                            names
                            :confidence="false"
                        />
                        <p class="mt-3 text-sm text-ink-soft">
                            {{ trans_choice(':count member has made a pick|:count members have made a pick', $this->pickem['total'], ['count' => $this->pickem['total']]) }}
                        </p>
                    @else
                        <p class="mt-3 text-[15px] text-ink-soft">{{ __('No picks yet. Call it first and bank the points if you\'re right.') }}</p>
                    @endif

                    @if ($this->pickem['mine'])
                        <p class="mt-4 text-sm font-semibold text-ink" data-test="my-pick">
                            {{ __('Your pick: :name', ['name' => $names[$this->pickem['mine']->predicted_side->value]]) }}
                        </p>
                    @endif

                    @if (auth()->check() && $match->hasParticipant(auth()->user()))
                        <p class="mt-6 text-sm text-ink-soft" data-test="playing-note">{{ __('You are playing in this one, so the pick is up to everyone else. Good luck.') }}</p>
                    @elseif (! auth()->check() || Gate::allows('predict', $match))
                        <div class="mt-6">
                            <x-ui.button variant="pill-orange" :href="route('predictions.index').'#match-'.$match->id" data-test="make-pick">
                                {{ $this->pickem['mine'] ? __('Change your pick') : __('Make your pick') }}
                            </x-ui.button>
                        </div>
                    @else
                        <p class="mt-6 text-sm text-ink-soft">{{ __('Picks are closed for this match.') }}</p>
                    @endif
                </x-ui.card>
            </div>
        </x-ui.section>
    @endif

    @can('delete', $match)
        <flux:modal name="delete-match" class="md:w-[28rem]">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete this match?') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('The result disappears from results and profiles, and everyone\'s ratings are recalculated. This cannot be undone.') }}</flux:text>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><x-ui.button variant="ghost" size="sm">{{ __('Cancel') }}</x-ui.button></flux:modal.close>
                    <x-ui.button variant="danger" size="sm" wire:click="delete" data-test="confirm-delete">{{ __('Delete match') }}</x-ui.button>
                </div>
            </div>
        </flux:modal>
    @endcan
</div>
