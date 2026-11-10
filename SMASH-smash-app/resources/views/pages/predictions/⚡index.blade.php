<?php

use App\Actions\Predictions\PlacePrediction;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\Prediction;
use App\Models\User;
use App\Services\Prediction\MatchPrediction;
use App\Services\Prediction\MatchPredictor;
use App\Services\Predictions\PredictionLeaderboard;
use App\Services\Predictions\PredictorRow;
use App\Support\Badminton\Scoring;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Pick’em')] class extends Component {
    /** Fixtures listed at most. */
    private const FIXTURES = 30;

    /** Rows in the leaderboard table (the viewer's own row is added below when further down). */
    private const TABLE_ROWS = 25;

    /** Recently settled picks in "Your record". */
    private const RECENT = 5;

    /** Show the SMASH model's win chances on every fixture. */
    #[Url(as: 'model', except: false)]
    public bool $model = false;

    /**
     * Make or change a pick: $side wins, the loser taking $games games (null = not called).
     * Authorised server-side by PlacePrediction (policy "predict": not playing, not started).
     */
    public function pick(mixed $match = null, mixed $side = null, mixed $games = null): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            session()->put('url.intended', route('predictions.index'));
            $this->redirectRoute('login');

            return;
        }

        if (! $user->hasVerifiedEmail()) {
            $this->fail('prediction', __('Verify your email address first, then you can play pick’em.'));

            return;
        }

        $data = Validator::make(
            ['match' => $match, 'side' => $side, 'games' => $games],
            [
                'match' => ['required', 'integer'],
                'side' => ['required', Rule::in(Side::values())],
                'games' => ['nullable', 'integer', 'min:0', 'max:4'],
            ],
            ['side.in' => __('Pick one of the two sides.')],
        )->validate();

        $fixture = BadmintonMatch::query()->withDetails()->find((int) $data['match']);

        if ($fixture === null) {
            $this->fail('prediction', __('That match no longer exists.'));

            return;
        }

        $pickedSide = Side::from((string) $data['side']);
        // A single game has no game count to call.
        $loserGames = $fixture->best_of > 1 && $data['games'] !== null ? (int) $data['games'] : null;

        try {
            PlacePrediction::handle($user, $fixture, $pickedSide, $loserGames);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? __('That pick could not be saved.');
            $this->fail(array_key_first($exception->errors()) ?? 'prediction', (string) $message);

            return;
        }

        unset($this->fixtures, $this->myPicks, $this->table, $this->pending);

        $this->resetErrorBag();

        Flux::toast(variant: 'success', text: __('Pick saved: :pick. You can change it until kick-off.', [
            'pick' => $this->pickLabel($fixture, $pickedSide, $loserGames),
        ]));
    }

    /**
     * Scheduled and live matches, live first, then soonest first.
     *
     * @return EloquentCollection<int, BadmintonMatch>
     */
    #[Computed]
    public function fixtures(): EloquentCollection
    {
        return BadmintonMatch::query()
            ->withDetails()
            ->withCount([
                'predictions as picks_a' => fn ($query) => $query->where('predicted_side', Side::A->value),
                'predictions as picks_b' => fn ($query) => $query->where('predicted_side', Side::B->value),
            ])
            ->whereIn('matches.status', [MatchStatus::Scheduled->value, MatchStatus::InProgress->value])
            ->orderByRaw('case when matches.status = ? then 0 else 1 end', [MatchStatus::InProgress->value])
            ->orderByRaw('matches.scheduled_at is null')
            ->orderBy('matches.scheduled_at')
            ->orderBy('matches.id')
            ->limit(self::FIXTURES)
            ->get();
    }

    /**
     * The viewer's picks on the listed fixtures (match id => prediction).
     *
     * @return array<int, Prediction>
     */
    #[Computed]
    public function myPicks(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return Prediction::query()
            ->where('user_id', $user->id)
            ->whereIn('match_id', $this->fixtures->modelKeys())
            ->get()
            ->keyBy('match_id')
            ->all();
    }

    /**
     * The SMASH model's call for each fixture with both sides known (only when switched on).
     *
     * @return array<int, MatchPrediction>
     */
    #[Computed]
    public function models(): array
    {
        if (! $this->model) {
            return [];
        }

        return $this->fixtures
            ->filter(fn (BadmintonMatch $match): bool => $this->sidesKnown($match))
            ->mapWithKeys(fn (BadmintonMatch $match): array => [$match->id => MatchPredictor::forMatch($match)])
            ->all();
    }

    /**
     * The whole pick'em table (everyone with a pick).
     *
     * @return Collection<int, PredictorRow>
     */
    #[Computed]
    public function table(): Collection
    {
        return PredictionLeaderboard::rows(0);
    }

    #[Computed]
    public function me(): ?PredictorRow
    {
        $user = auth()->user();

        return $user instanceof User
            ? $this->table->first(fn (PredictorRow $row): bool => $row->user->id === $user->id)
            : null;
    }

    /**
     * The viewer's picks on matches still to be played.
     */
    #[Computed]
    public function pending(): int
    {
        $user = auth()->user();

        return $user instanceof User ? Prediction::query()->where('user_id', $user->id)->pending()->count() : 0;
    }

    /**
     * The viewer's most recently settled picks, newest result first.
     *
     * @return EloquentCollection<int, Prediction>
     */
    #[Computed]
    public function recent(): EloquentCollection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return new EloquentCollection;
        }

        return Prediction::query()
            ->where('user_id', $user->id)
            ->settled()
            ->with(['match' => fn ($query) => $query->withDetails()])
            ->orderByDesc(BadmintonMatch::query()->select('played_at')->whereColumn('matches.id', 'predictions.match_id'))
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get();
    }

    /**
     * Can the viewer pick this fixture, and if not, why not?
     * open · guest · unverified · playing · live · started · tbd
     */
    public function stateFor(BadmintonMatch $match): string
    {
        $user = auth()->user();

        return match (true) {
            $match->status === MatchStatus::InProgress => 'live',
            $match->scheduled_at !== null && ! $match->scheduled_at->isFuture() => 'started',
            ! $this->sidesKnown($match) => 'tbd',
            ! $user instanceof User => 'guest',
            $match->hasParticipant($user) => 'playing',
            ! $user->hasVerifiedEmail() => 'unverified',
            default => 'open',
        };
    }

    /**
     * Short name of one side: the player in singles, family names for pairs.
     */
    public function shortName(BadmintonMatch $match, Side $side): string
    {
        $players = $match->playersOn($side);

        return match ($players->count()) {
            0 => $match->sideLabel($side),
            1 => $players->first()->name,
            default => $players->map(fn (User $user): string => Str::ucfirst($user->lastName()))->join(' & '),
        };
    }

    /**
     * "Wei Chen to win 2–1" (or "to win" when the game count is not called).
     */
    public function pickLabel(BadmintonMatch $match, Side $side, ?int $loserGames): string
    {
        $name = $this->shortName($match, $side);

        return $loserGames === null
            ? __(':name to win', ['name' => $name])
            : __(':name to win :score', ['name' => $name, 'score' => Scoring::gamesToWin($match->best_of).'–'.$loserGames]);
    }

    /**
     * Every pick on offer for one side: [loser games (null = winner only), label].
     *
     * @return list<array{games: int|null, label: string}>
     */
    public function choicesFor(BadmintonMatch $match): array
    {
        if ($match->best_of <= 1) {
            return [['games' => null, 'label' => __('Win')]];
        }

        $toWin = Scoring::gamesToWin($match->best_of);

        return array_map(fn (int $games): array => ['games' => $games, 'label' => $toWin.'–'.$games], range(0, $toWin - 1));
    }

    private function sidesKnown(BadmintonMatch $match): bool
    {
        return $match->playersOn(Side::A)->isNotEmpty() && $match->playersOn(Side::B)->isNotEmpty();
    }

    private function fail(string $key, string $message): void
    {
        $this->addError($key, $message);
        Flux::toast(variant: 'danger', text: $message);
    }
}; ?>

@php
    $viewer = auth()->user();
    $toWinPoints = \App\Models\Prediction::POINTS_CORRECT_WINNER;
    $exactBonus = \App\Models\Prediction::POINTS_EXACT_BONUS;
    $fixtures = $this->fixtures;
    $picks = $this->myPicks;
    $models = $this->models;
    $openCount = $fixtures->filter(fn ($m) => in_array($this->stateFor($m), ['open', 'guest', 'unverified'], true))->count();
@endphp

<div>
    <x-ui.page-hero
        :breadcrumbs="[__('Pick’em')]"
        :kicker="__('Prediction game')"
        :title="__('Pick’em')"
        :intro="__('Call the result of every club fixture before it starts: :three points for the right winner, :two more if you also get the number of games right. Top of the table at the end of the season takes the bragging rights.', ['three' => $toWinPoints, 'two' => $exactBonus])"
    >
        <x-slot:actions>
            @guest
                <x-ui.button variant="cta" :href="route('login')">{{ __('Log in to play') }}</x-ui.button>
            @else
                <x-ui.button variant="primary" :href="'#fixtures'" :navigate="false" icon="arrow-down">{{ __('Make your picks') }}</x-ui.button>
            @endguest
            <x-ui.link :href="route('predictor')">{{ __('Ask the predictor') }}</x-ui.link>
        </x-slot:actions>

        <x-slot:aside>
            <p class="kicker">{{ __('How scoring works') }}</p>
            <dl class="mt-4 divide-y divide-line">
                <div class="flex items-baseline gap-4 py-2.5">
                    <dt class="num w-12 shrink-0 text-[30px] font-extrabold leading-none text-heading">{{ $toWinPoints }}</dt>
                    <dd class="text-[15px] text-ink-soft">{{ __('Right winner') }}</dd>
                </div>
                <div class="flex items-baseline gap-4 py-2.5">
                    <dt class="num w-12 shrink-0 text-[30px] font-extrabold leading-none text-cta dark:text-green-400">+{{ $exactBonus }}</dt>
                    <dd class="text-[15px] text-ink-soft">{{ __('Right number of games too (2–0 or 2–1)') }}</dd>
                </div>
                <div class="flex items-baseline gap-4 py-2.5">
                    <dt class="num w-12 shrink-0 text-[30px] font-extrabold leading-none text-ink-muted">0</dt>
                    <dd class="text-[15px] text-ink-soft">{{ __('Wrong winner. Walkovers and cancelled matches don’t count.') }}</dd>
                </div>
            </dl>
            <p class="mt-3 text-sm text-ink-muted">{{ __('Picks lock at kick-off. You can’t pick a match you’re playing in.') }}</p>
        </x-slot:aside>
    </x-ui.page-hero>

    {{-- Fixtures --}}
    <x-ui.section
        id="fixtures"
        :kicker="trans_choice('{0} Nothing open right now|{1} :n match open for picks|[2,*] :n matches open for picks', $openCount, ['n' => $openCount])"
        :title="__('Upcoming fixtures')"
    >
        <x-slot:actions>
            <flux:switch wire:model.live="model" :label="__('Show the SMASH model')" align="left" />
        </x-slot:actions>

        @error('prediction')
            <x-ui.alert tone="danger" class="mb-6" :title="__('Pick not saved')">{{ $message }}</x-ui.alert>
        @enderror
        @error('loser_games')
            <x-ui.alert tone="danger" class="mb-6" :title="__('Pick not saved')">{{ $message }}</x-ui.alert>
        @enderror

        @if ($fixtures->isEmpty())
            <x-ui.empty :title="__('No fixtures to call yet')">
                {{ __('Challenge matches and tournament draws show up here as soon as they’re scheduled. Check back after the next club night.') }}
                <x-slot:actions>
                    <x-ui.button variant="outline" size="sm" :href="route('matches.index')">{{ __('Recent results') }}</x-ui.button>
                </x-slot:actions>
            </x-ui.empty>
        @else
            <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3" wire:loading.class="opacity-90" wire:target="model">
                @foreach ($fixtures as $match)
                    @php
                        $state = $this->stateFor($match);
                        $mine = $picks[$match->id] ?? null;
                        $nameA = $this->shortName($match, \App\Enums\Side::A);
                        $nameB = $this->shortName($match, \App\Enums\Side::B);
                        $choices = $this->choicesFor($match);
                        $locked = in_array($state, ['live', 'started'], true);
                        $when = $match->scheduled_at === null
                            ? __('Date to be confirmed')
                            : ($match->scheduled_at->isToday() ? __('Today') : ($match->scheduled_at->isTomorrow() ? __('Tomorrow') : $match->scheduled_at->format('D j M'))).' · '.$match->scheduled_at->format('H:i');
                    @endphp

                    <x-ui.card
                        as="article"
                        padding="none"
                        id="match-{{ $match->id }}"
                        :highlight="$state === 'live' ? 'smash' : ($mine ? 'navy' : null)"
                        class="scroll-mt-28 target:outline-2 target:outline-offset-4 target:outline-focus"
                        wire:key="fixture-{{ $match->id }}"
                        :aria-label="__(':a against :b', ['a' => $nameA, 'b' => $nameB])"
                    >
                        {{-- Header: competition, kick-off, status --}}
                        <div class="flex items-start justify-between gap-3 px-5 pt-5">
                            <div class="min-w-0">
                                <p class="kicker truncate">
                                    @if ($match->tournament)
                                        {{ $match->tournament->name }}@if ($match->roundLabel()) · {{ $match->roundLabel() }}@endif
                                    @else
                                        {{ __('Friendly') }}
                                    @endif
                                </p>
                                <p class="mt-1 text-sm text-ink-muted">
                                    {{ $when }}@if ($match->court) · {{ __('Court :n', ['n' => $match->court]) }}@endif
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-wrap justify-end gap-1.5">
                                @if ($state === 'live')
                                    <x-smash.status-badge :match="$match" size="sm" />
                                @elseif ($state === 'started')
                                    <x-ui.tag tone="grey" size="sm" icon="lock-closed">{{ __('Closed') }}</x-ui.tag>
                                @endif
                                <x-smash.discipline-badge :discipline="$match->discipline" />
                            </div>
                        </div>

                        {{-- Sides with their picks --}}
                        <div class="mt-4 divide-y divide-line border-y border-line">
                            @foreach ([\App\Enums\Side::A, \App\Enums\Side::B] as $side)
                                <div class="flex items-center justify-between gap-3 px-5 py-3" wire:key="fixture-{{ $match->id }}-{{ $side->value }}">
                                    <x-smash.player :match="$match" :side="$side" size="sm" :rating="$state !== 'tbd'" :meta="false" :short="$match->discipline === \App\Enums\Discipline::Doubles" class="min-w-0" />

                                    @if (in_array($state, ['open', 'guest', 'unverified', 'live', 'started'], true))
                                        <div class="flex shrink-0 gap-1.5" role="group" aria-label="{{ __('Pick :name to win', ['name' => $side === \App\Enums\Side::A ? $nameA : $nameB]) }}">
                                            @foreach ($choices as $choice)
                                                @php
                                                    $selected = $mine !== null
                                                        && $mine->predicted_side === $side
                                                        && ($choice['games'] === null || $mine->predicted_loser_games === $choice['games']);
                                                    $spoken = $choice['games'] === null
                                                        ? __(':name to win', ['name' => $side === \App\Enums\Side::A ? $nameA : $nameB])
                                                        : __(':name to win :score', ['name' => $side === \App\Enums\Side::A ? $nameA : $nameB, 'score' => $choice['label']]);
                                                @endphp
                                                <x-predictions.pick-button
                                                    :label="$choice['label']"
                                                    :side="$side->value"
                                                    :selected="$selected"
                                                    :sr="$spoken"
                                                    :disabled="$locked || $state === 'unverified'"
                                                    :href="$state === 'guest' ? route('login') : null"
                                                    wire:key="pick-{{ $match->id }}-{{ $side->value }}-{{ $choice['games'] ?? 'w' }}"
                                                    :action="$state === 'open' ? 'pick('.$match->id.', \''.$side->value.'\', '.($choice['games'] ?? 'null').')' : null"
                                                />
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        {{-- Club split, model, your pick --}}
                        <div class="space-y-4 px-5 py-4">
                            @if ($state !== 'tbd')
                                <x-predictions.split :a="$match->picks_a" :b="$match->picks_b" :name-a="$nameA" :name-b="$nameB" />
                            @endif

                            @if (isset($models[$match->id]))
                                <div data-pick-model>
                                    <p class="label-caps mb-2 text-ink-muted">{{ __('SMASH model') }}</p>
                                    <x-smash.win-probability :prediction="$models[$match->id]" :label-a="$nameA" :label-b="$nameB" variant="compact" names />
                                </div>
                            @endif
                        </div>

                        <div class="mt-auto border-t border-line bg-surface-muted px-5 py-3 text-sm">
                            @switch($state)
                                @case('tbd')
                                    <p class="text-ink-soft">{{ __('Picks open as soon as both sides are known.') }}</p>
                                    @break
                                @case('guest')
                                    <p class="text-ink-soft"><x-ui.link :href="route('login')" variant="promo">{{ __('Log in to pick') }}</x-ui.link></p>
                                    @break
                                @case('unverified')
                                    <p class="text-ink-soft">{{ __('Verify your email address to start picking.') }}</p>
                                    @break
                                @case('playing')
                                    <p class="text-ink-soft"><span class="font-semibold text-ink">{{ __('You’re playing in this one.') }}</span> {{ __('No picks for players, good luck out there.') }}</p>
                                    @break
                                @default
                                    @if ($mine)
                                        <p class="text-ink-soft">
                                            <span class="label-caps me-1 text-ink-muted">{{ __('Your pick') }}</span>
                                            <span class="font-semibold text-ink">{{ $this->pickLabel($match, $mine->predicted_side, $mine->predicted_loser_games) }}</span>
                                            @if ($locked)
                                                · {{ __('locked in') }}
                                            @endif
                                        </p>
                                    @elseif ($locked)
                                        <p class="text-ink-soft">{{ $state === 'live' ? __('Picks closed: this one is live.') : __('Picks closed at kick-off.') }}</p>
                                    @else
                                        <p class="text-ink-soft">{{ __('Pick the winner and the score, worth up to :n points.', ['n' => $toWinPoints + $exactBonus]) }}</p>
                                    @endif
                            @endswitch
                        </div>
                    </x-ui.card>
                @endforeach
            </div>
        @endif
    </x-ui.section>

    {{-- Leaderboard + your record --}}
    <x-ui.section
        tone="grey"
        id="leaderboard"
        :kicker="__('Season 2026–27')"
        :title="__('Leaderboard')"
        :intro="__('Ranked by points, then correct picks, exact scores and accuracy.')"
    >
        @php
            $rows = $this->table;
            $top = $rows->take(25);
            $shown = $top->count();
            $me = $this->me;
            $meBelow = $me !== null && $me->position > $shown;
        @endphp

        <div class="grid gap-6 lg:grid-cols-12">
            <div class="min-w-0 lg:col-span-8">
                @if ($rows->isEmpty())
                    <x-ui.empty :title="__('The table is empty')" variant="bordered" class="bg-surface">
                        {{ __('Nobody has made a pick yet. Get yours in and you’re top of the table.') }}
                    </x-ui.empty>
                @else
                    <x-ui.table>
                        <table data-pickem-leaderboard>
                            <caption class="sr-only">{{ __('Pick’em leaderboard') }}</caption>
                            <thead>
                                <tr>
                                    <th class="w-16">#</th>
                                    <th>{{ __('Member') }}</th>
                                    <th class="text-end">{{ __('Points') }}</th>
                                    <th class="text-end">{{ __('Correct') }}</th>
                                    <th class="text-end max-sm:hidden">{{ __('Exact') }}</th>
                                    <th class="text-end max-sm:hidden">{{ __('Accuracy') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($meBelow ? $top->concat([$me]) : $top as $row)
                                    <tr
                                        wire:key="lb-{{ $row->user->id }}"
                                        @if ($viewer && $row->user->id === $viewer->id) data-current @endif
                                        @class(['border-t-2 border-t-line-strong' => $meBelow && $loop->last])
                                    >
                                        <td><x-smash.rank :position="$row->position" size="sm" /></td>
                                        <td class="min-w-0 max-w-[16rem]">
                                            <x-smash.player :user="$row->user" size="sm" :meta="false" />
                                        </td>
                                        <td class="num text-end text-[19px] font-extrabold text-ink">{{ $row->points }}</td>
                                        <td class="num text-end text-[17px] font-semibold text-ink">{{ $row->correct }}<span class="text-ink-muted">/{{ $row->settled }}</span></td>
                                        <td class="num text-end text-[17px] font-semibold text-ink max-sm:hidden">{{ $row->exact }}</td>
                                        <td class="num text-end text-[17px] font-semibold text-ink max-sm:hidden">
                                            {{ $row->accuracy === null ? '–' : (int) round($row->accuracy * 100).'%' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <x-slot:footer>
                            <p class="px-5 py-3 text-sm text-ink-muted">
                                {{ trans_choice('{1} :n member has made a pick this season.|[2,*] :n members have made picks this season.', $rows->count(), ['n' => $rows->count()]) }}
                                @if ($rows->count() > $shown)
                                    {{ __('Showing the top :n.', ['n' => $shown]) }}
                                @endif
                            </p>
                        </x-slot:footer>
                    </x-ui.table>
                @endif
            </div>

            <aside class="min-w-0 lg:col-span-4" aria-labelledby="record-title">
                @auth
                    <x-ui.card padding="lg" highlight="navy" data-pickem-record>
                        <x-ui.heading :level="3" size="h4" id="record-title">{{ __('Your record') }}</x-ui.heading>

                        @if ($me === null)
                            <p class="mt-3 text-[15px] leading-relaxed text-ink-soft">
                                {{ __('No picks yet. Your first pick puts you on the table, and the first settled one gets you points.') }}
                            </p>
                            @if ($this->pending > 0)
                                <p class="mt-2 text-sm text-ink-muted">{{ trans_choice('{1} :n pick waiting for a result.|[2,*] :n picks waiting for a result.', $this->pending, ['n' => $this->pending]) }}</p>
                            @endif
                        @else
                            <dl class="mt-5 grid grid-cols-2 gap-px bg-line">
                                <div class="bg-surface py-3 pe-3">
                                    <dt class="label-caps text-ink-muted">{{ __('Points') }}</dt>
                                    <dd class="num mt-1 text-[40px] font-extrabold leading-none text-heading">{{ $me->points }}</dd>
                                </div>
                                <div class="bg-surface py-3 ps-4">
                                    <dt class="label-caps text-ink-muted">{{ __('Position') }}</dt>
                                    <dd class="num mt-1 text-[40px] font-extrabold leading-none text-ink">#{{ $me->position }}</dd>
                                    <dd class="text-[13px] text-ink-muted">{{ __('of :n', ['n' => $rows->count()]) }}</dd>
                                </div>
                                <div class="bg-surface py-3 pe-3">
                                    <dt class="label-caps text-ink-muted">{{ __('Correct') }}</dt>
                                    <dd class="num mt-1 text-[26px] font-bold leading-none text-ink">{{ $me->correct }}<span class="text-ink-muted">/{{ $me->settled }}</span></dd>
                                    <dd class="text-[13px] text-ink-muted">{{ $me->accuracy === null ? __('Nothing settled yet') : __(':p% accuracy', ['p' => (int) round($me->accuracy * 100)]) }}</dd>
                                </div>
                                <div class="bg-surface py-3 ps-4">
                                    <dt class="label-caps text-ink-muted">{{ __('Exact scores') }}</dt>
                                    <dd class="num mt-1 text-[26px] font-bold leading-none text-ink">{{ $me->exact }}</dd>
                                    <dd class="text-[13px] text-ink-muted">{{ trans_choice('{0} none pending|{1} :n pick pending|[2,*] :n picks pending', $this->pending, ['n' => $this->pending]) }}</dd>
                                </div>
                            </dl>
                        @endif

                        @if ($this->recent->isNotEmpty())
                            <h4 class="label-caps mt-7 text-ink-muted">{{ __('Recently settled') }}</h4>
                            <ul class="mt-2 divide-y divide-line">
                                @foreach ($this->recent as $prediction)
                                    @php
                                        $played = $prediction->match;
                                        $points = $prediction->points();
                                        $winner = $played->winning_side;
                                    @endphp
                                    <li class="flex items-center gap-3 py-3" wire:key="recent-{{ $prediction->id }}">
                                        <div class="min-w-0 flex-1">
                                            <a href="{{ route('matches.show', $played) }}" wire:navigate class="block truncate text-[15px] font-semibold text-ink hover:text-link hover:underline">
                                                {{ $this->shortName($played, \App\Enums\Side::A) }} {{ __('v') }} {{ $this->shortName($played, \App\Enums\Side::B) }}
                                            </a>
                                            <p class="mt-0.5 truncate text-[13px] text-ink-muted">
                                                {{ __('You: :pick', ['pick' => $this->pickLabel($played, $prediction->predicted_side, $prediction->predicted_loser_games)]) }}
                                                @if ($winner)
                                                    · {{ __('Result :score', ['score' => $played->gamesWon($winner).'–'.$played->gamesWon($winner->opposite())]) }}
                                                @endif
                                            </p>
                                        </div>
                                        <span @class([
                                            'num inline-flex min-w-11 shrink-0 justify-center px-2 py-1 text-[17px] font-extrabold leading-none',
                                            'bg-cta text-white' => $points >= $toWinPoints + $exactBonus,
                                            'bg-green-50 text-cta dark:bg-green-400/10 dark:text-green-400' => $points > 0 && $points < $toWinPoints + $exactBonus,
                                            'bg-canvas-muted text-ink-muted' => ! $points,
                                        ])>
                                            <span class="sr-only">{{ __('Points:') }}</span>{{ $points > 0 ? '+'.$points : '0' }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-ui.card>
                @else
                    <x-ui.card variant="navy" padding="lg" data-pickem-guest>
                        <p class="kicker">{{ __('Members only') }}</p>
                        <p id="record-title" class="mt-3 font-serif text-h4 text-heading">{{ __('Think you can beat the table?') }}</p>
                        <p class="mt-3 text-[15px] leading-relaxed text-ink-soft">{{ __('Pick’em is free for every SMASH member. Log in to call the next fixtures and track your record.') }}</p>
                        <div class="mt-6 flex flex-wrap items-center gap-x-6 gap-y-3">
                            <x-ui.button variant="pill-orange" :href="route('login')">{{ __('Log in') }}</x-ui.button>
                            @if (Route::has('register'))
                                <x-ui.link :href="route('register')">{{ __('Join SMASH') }}</x-ui.link>
                            @endif
                        </div>
                    </x-ui.card>
                @endauth

                <p class="mt-6 text-sm leading-relaxed text-ink-soft">
                    {{ __('Want a second opinion before you pick?') }}
                    <a href="{{ route('how-it-works') }}#accuracy" wire:navigate class="link">{{ __('See how often the SMASH model gets it right') }}</a>.
                </p>
            </aside>
        </div>
    </x-ui.section>
</div>
