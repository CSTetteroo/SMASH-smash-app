<?php

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\User;
use App\Services\Prediction\MatchPrediction;
use App\Services\Prediction\MatchPredictor;
use App\Services\Stats\PlayerStats;
use App\Support\SmashConfig;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Who wins?')] class extends Component {
    /** Player slots: side A (a1 + partner a2) against side B (b1 + partner b2). */
    private const SLOTS = ['a1', 'a2', 'b1', 'b2'];

    /** Formats on offer: best of three or a single game, to 21, 15 or 11 points. */
    private const BEST_OF = [3, 1];

    private const POINTS = [21, 15, 11];

    /** Discipline: singles or doubles. */
    #[Url]
    public string $d = 'singles';

    #[Url]
    public ?int $a1 = null;

    #[Url]
    public ?int $a2 = null;

    #[Url]
    public ?int $b1 = null;

    #[Url]
    public ?int $b2 = null;

    /** Best of 3 or 1. */
    #[Url]
    public int $bo = 3;

    /** Points per game. */
    #[Url]
    public int $ppg = 21;

    public function mount(): void
    {
        $this->normalise();

        // Nothing picked yet: open on the battle at the top of the rankings, with every
        // choice in the address bar so the page can be shared as it is.
        if ($this->a1 === null && $this->a2 === null && $this->b1 === null && $this->b2 === null) {
            $featured = $this->featuredMatchup();

            if ($featured !== null) {
                $this->redirectRoute('predictor', $featured, navigate: true);
            }
        }
    }

    public function updated(string $property): void
    {
        // Picking somebody who already plays elsewhere moves them: clear the other slot.
        if (in_array($property, self::SLOTS, true) && $this->{$property} !== null) {
            foreach (self::SLOTS as $slot) {
                if ($slot !== $property && $this->{$slot} === $this->{$property}) {
                    $this->{$slot} = null;
                }
            }
        }

        $this->normalise();
    }

    /**
     * A random matchup between players with at least one rated match (anyone active as a fallback).
     */
    public function surprise(): void
    {
        $discipline = $this->discipline();
        $needed = $discipline->playersPerSide() * 2;

        $pool = User::query()->active()->where($discipline->playedColumn(), '>=', 1)->inRandomOrder()->limit($needed)->pluck('id')->all();

        if (count($pool) < $needed) {
            $pool = User::query()->active()->inRandomOrder()->limit($needed)->pluck('id')->all();
        }

        if (count($pool) < $needed) {
            Flux::toast(variant: 'warning', text: __('We need a few more players before we can surprise you.'));

            return;
        }

        $pool = array_map('intval', $pool);
        $doubles = $discipline === Discipline::Doubles;

        $this->a1 = $pool[0];
        $this->b1 = $pool[1];
        $this->a2 = $doubles ? $pool[2] : null;
        $this->b2 = $doubles ? $pool[3] : null;
    }

    public function swap(): void
    {
        [$this->a1, $this->b1] = [$this->b1, $this->a1];
        [$this->a2, $this->b2] = [$this->b2, $this->a2];
    }

    /**
     * Active players for the selectors, by name (keyed by id). Emails never leave the server.
     *
     * @return EloquentCollection<int, User>
     */
    #[Computed]
    public function players(): EloquentCollection
    {
        return User::query()->active()->orderBy('name')->orderBy('id')->get()->keyBy('id');
    }

    /**
     * Ranking positions in the chosen discipline (user id => position).
     *
     * @return array<int, int>
     */
    #[Computed]
    public function ranks(): array
    {
        $ranks = [];

        foreach (User::query()->ranked($this->discipline())->pluck('id') as $index => $id) {
            $ranks[(int) $id] = $index + 1;
        }

        return $ranks;
    }

    /**
     * The chosen players per side (unknown or inactive ids are dropped).
     *
     * @return array{a: list<User>, b: list<User>}
     */
    #[Computed]
    public function lineup(): array
    {
        $doubles = $this->discipline() === Discipline::Doubles;
        $pick = fn (array $slots): array => array_values(array_filter(array_map(
            fn (string $slot): ?User => $this->{$slot} === null ? null : $this->players->get($this->{$slot}),
            $slots,
        )));

        return [
            'a' => $pick($doubles ? ['a1', 'a2'] : ['a1']),
            'b' => $pick($doubles ? ['b1', 'b2'] : ['b1']),
        ];
    }

    #[Computed]
    public function ready(): bool
    {
        $perSide = $this->discipline()->playersPerSide();

        return count($this->lineup['a']) === $perSide && count($this->lineup['b']) === $perSide;
    }

    #[Computed]
    public function prediction(): ?MatchPrediction
    {
        if (! $this->ready) {
            return null;
        }

        return MatchPredictor::predict($this->lineup['a'], $this->lineup['b'], $this->discipline(), $this->bo, $this->ppg);
    }

    /**
     * Names for copy: first names ("Wei", "Wei & Sam"), full names if two players share one.
     *
     * @return array{a: string, b: string, pairA: bool, pairB: bool}
     */
    #[Computed]
    public function names(): array
    {
        $everyone = [...$this->lineup['a'], ...$this->lineup['b']];
        $firsts = array_map(fn (User $user): string => mb_strtolower($user->firstName()), $everyone);
        $useFull = count($firsts) !== count(array_unique($firsts));
        $name = fn (array $users): string => implode(' & ', array_map(fn (User $user): string => $useFull ? $user->name : $user->firstName(), $users));

        return [
            'a' => $this->lineup['a'] === [] ? __('Side A') : $name($this->lineup['a']),
            'b' => $this->lineup['b'] === [] ? __('Side B') : $name($this->lineup['b']),
            'pairA' => count($this->lineup['a']) > 1,
            'pairB' => count($this->lineup['b']) > 1,
        ];
    }

    /**
     * What to ask for while the line-up is incomplete.
     *
     * @return array{title: string, text: string}|null
     */
    #[Computed]
    public function missing(): ?array
    {
        if ($this->ready) {
            return null;
        }

        $doubles = $this->discipline() === Discipline::Doubles;
        $a = $this->lineup['a'];
        $b = $this->lineup['b'];

        return match (true) {
            $a === [] && $b === [] => [
                'title' => $doubles ? __('Pick two pairs') : __('Pick two players'),
                'text' => __('Choose who plays on each side and SMASH will call it. Or let us surprise you.'),
            ],
            $doubles && count($a) + count($b) === 3 => [
                'title' => __('One more partner'),
                'text' => __(':name still needs a partner.', ['name' => (count($a) === 1 ? $a : $b)[0]->name]),
            ],
            $doubles => [
                'title' => __('Doubles needs two a side'),
                'text' => __('Pick a player and a partner for both sides.'),
            ],
            default => [
                'title' => __('Who are they playing?'),
                'text' => __('Pick an opponent for :name.', ['name' => ($a === [] ? $b : $a)[0]->name]),
            ],
        };
    }

    /**
     * Previous rated meetings between exactly these sides.
     *
     * @return array{matches: Collection<int, BadmintonMatch>, winsA: int, winsB: int, gamesA: int, gamesB: int}|null
     */
    #[Computed]
    public function meetings(): ?array
    {
        if (! $this->ready) {
            return null;
        }

        [$a, $b] = [$this->lineup['a'], $this->lineup['b']];

        if ($this->discipline() === Discipline::Singles) {
            $h2h = PlayerStats::headToHead($a[0], $b[0], Discipline::Singles);

            return ['matches' => $h2h->matches, 'winsA' => $h2h->winsA, 'winsB' => $h2h->winsB, 'gamesA' => $h2h->gamesA, 'gamesB' => $h2h->gamesB];
        }

        $query = BadmintonMatch::query()
            ->withDetails()
            ->where('matches.status', MatchStatus::Completed->value)
            ->whereNotNull('matches.winning_side')
            ->forDiscipline(Discipline::Doubles);

        foreach ([...$a, ...$b] as $player) {
            $query->forPlayer($player);
        }

        $matches = $query->latestFirst()->get()->filter(function (BadmintonMatch $match) use ($a, $b): bool {
            $side = $match->sideOf($a[0]);

            return $side !== null
                && $match->sideOf($a[1]) === $side
                && $match->sideOf($b[0]) === $side->opposite()
                && $match->sideOf($b[1]) === $side->opposite();
        })->values();

        $totals = ['winsA' => 0, 'winsB' => 0, 'gamesA' => 0, 'gamesB' => 0];

        foreach ($matches as $match) {
            $sideA = $match->sideOf($a[0]) ?? Side::A;
            $totals[$match->winning_side === $sideA ? 'winsA' : 'winsB']++;
            $totals['gamesA'] += $match->gamesWon($sideA);
            $totals['gamesB'] += $match->gamesWon($sideA->opposite());
        }

        return ['matches' => $matches, ...$totals];
    }

    /**
     * The headline sentence, e.g. "SMASH rates Sam a strong favourite — 78%".
     */
    #[Computed]
    public function verdict(): ?string
    {
        $p = $this->prediction;

        if ($p === null) {
            return null;
        }

        if ($p->favourite === null) {
            return __('Dead level: SMASH can’t split them at 50–50');
        }

        $favourite = $p->favourite === Side::A ? $this->names['a'] : $this->names['b'];
        $pair = $p->favourite === Side::A ? $this->names['pairA'] : $this->names['pairB'];
        $percent = $p->percentFor($p->favourite);

        return match ($p->confidence) {
            'Strong favourite' => $pair
                ? __('SMASH rates :name strong favourites — :p%', ['name' => $favourite, 'p' => $percent])
                : __('SMASH rates :name a strong favourite — :p%', ['name' => $favourite, 'p' => $percent]),
            'Favourite' => $pair
                ? __('SMASH makes :name the favourites — :p%', ['name' => $favourite, 'p' => $percent])
                : __('SMASH makes :name the favourite — :p%', ['name' => $favourite, 'p' => $percent]),
            'Slight edge' => __('SMASH gives :name a slight edge — :p%', ['name' => $favourite, 'p' => $percent]),
            default => $pair
                ? __('Too close to call — :name just edge it at :p%', ['name' => $favourite, 'p' => $percent])
                : __('Too close to call — :name just edges it at :p%', ['name' => $favourite, 'p' => $percent]),
        };
    }

    /**
     * Scheduled matches between known sides, closest to 50–50 first (at most four).
     *
     * @return Collection<int, array{match: BadmintonMatch, prediction: MatchPrediction, url: string}>
     */
    #[Computed]
    public function closest(): Collection
    {
        return BadmintonMatch::query()
            ->withDetails()
            ->where('matches.status', MatchStatus::Scheduled->value)
            ->where(fn ($query) => $query->whereNull('matches.scheduled_at')->orWhere('matches.scheduled_at', '>', now()))
            ->whereHas('participants', fn ($query) => $query->where('side', Side::A->value))
            ->whereHas('participants', fn ($query) => $query->where('side', Side::B->value))
            ->orderByRaw('matches.scheduled_at is null')
            ->orderBy('matches.scheduled_at')
            ->orderBy('matches.id')
            ->limit(24)
            ->get()
            ->map(fn (BadmintonMatch $match): array => [
                'match' => $match,
                'prediction' => MatchPredictor::forMatch($match),
                'url' => $this->urlFor($match),
            ])
            ->sort(fn (array $x, array $y): int => [abs($x['prediction']->probabilityA - 0.5), $x['match']->scheduled_at?->getTimestamp() ?? PHP_INT_MAX, $x['match']->id]
                <=> [abs($y['prediction']->probabilityA - 0.5), $y['match']->scheduled_at?->getTimestamp() ?? PHP_INT_MAX, $y['match']->id])
            ->take(4)
            ->values();
    }

    /**
     * This exact prediction as a link (also what the address bar shows).
     */
    #[Computed]
    public function shareUrl(): string
    {
        return route('predictor', array_filter([
            'd' => $this->d,
            'a1' => $this->a1,
            'a2' => $this->a2,
            'b1' => $this->b1,
            'b2' => $this->b2,
            'bo' => $this->bo,
            'ppg' => $this->ppg,
        ], fn ($value) => $value !== null));
    }

    private function discipline(): Discipline
    {
        return Discipline::tryFrom($this->d) ?? Discipline::Singles;
    }

    /**
     * Keep the state valid whatever arrives from the address bar or the browser.
     */
    private function normalise(): void
    {
        $discipline = $this->discipline();
        $this->d = $discipline->value;
        $this->bo = in_array($this->bo, self::BEST_OF, true) ? $this->bo : 3;
        $this->ppg = in_array($this->ppg, self::POINTS, true) ? $this->ppg : 21;

        if ($discipline === Discipline::Singles) {
            $this->a2 = null;
            $this->b2 = null;
        }

        $seen = [];

        foreach (['a1', 'a2', 'b1', 'b2'] as $slot) {
            $id = $this->{$slot};

            if ($id !== null && (! $this->players->has($id) || in_array($id, $seen, true))) {
                $this->{$slot} = null;

                continue;
            }

            if ($id !== null) {
                $seen[] = $id;
            }
        }
    }

    /**
     * The top of the current rankings: #1 v #2 in singles, #1 & #4 v #2 & #3 in doubles.
     *
     * @return array<string, int|string>|null
     */
    private function featuredMatchup(): ?array
    {
        $discipline = $this->discipline();
        $top = array_map('intval', User::query()->ranked($discipline)->limit(4)->pluck('id')->all());
        $format = ['bo' => $this->bo, 'ppg' => $this->ppg];

        if ($discipline === Discipline::Doubles) {
            return count($top) < 4 ? null : ['d' => 'doubles', 'a1' => $top[0], 'a2' => $top[3], 'b1' => $top[1], 'b2' => $top[2], ...$format];
        }

        return count($top) < 2 ? null : ['d' => 'singles', 'a1' => $top[0], 'b1' => $top[1], ...$format];
    }

    /**
     * The predictor URL for a stored match's line-up and format.
     */
    private function urlFor(BadmintonMatch $match): string
    {
        $a = $match->playersOn(Side::A)->pluck('id')->values();
        $b = $match->playersOn(Side::B)->pluck('id')->values();

        return route('predictor', array_filter([
            'd' => $match->discipline->value,
            'a1' => $a->get(0),
            'a2' => $a->get(1),
            'b1' => $b->get(0),
            'b2' => $b->get(1),
            'bo' => in_array($match->best_of, self::BEST_OF, true) ? $match->best_of : 3,
            'ppg' => in_array($match->points_per_game, self::POINTS, true) ? $match->points_per_game : 21,
        ], fn ($value) => $value !== null));
    }
}; ?>

@php
    $discipline = \App\Enums\Discipline::tryFrom($d) ?? \App\Enums\Discipline::Singles;
    $doubles = $discipline === \App\Enums\Discipline::Doubles;
    $p = $this->prediction;
    $lineup = $this->lineup;
    $names = $this->names;
    $taken = array_values(array_filter([$a1, $a2, $b1, $b2]));
    $formatLabel = $bo === 1
        ? __('One game to :p', ['p' => $ppg])
        : __('Best of :n to :p', ['n' => $bo, 'p' => $ppg]);
@endphp

<div>
    <x-ui.page-hero
        :breadcrumbs="[__('Who wins?')]"
        :kicker="__('Match predictor')"
        :title="__('Who wins?')"
        :intro="__('Pick any two players, or two doubles pairs, and SMASH works out the odds from their ratings, recent form and every previous meeting.')"
        court
    >
        <x-slot:actions>
            <x-ui.button variant="primary" icon="sparkles" wire:click="surprise">{{ __('Surprise me') }}</x-ui.button>
            <x-ui.link :href="route('how-it-works').'#predictor'">{{ __('How the predictor works') }}</x-ui.link>
        </x-slot:actions>
    </x-ui.page-hero>

    <x-ui.section spacing="md" :title="__('Build a matchup')" :kicker="$discipline->label().' · '.$formatLabel" level="2">
        <x-slot:actions>
            <div x-data="{
                copied: false,
                copy(text) {
                    const done = () => { this.copied = true; setTimeout(() => this.copied = false, 2000) };
                    if (navigator.clipboard && window.isSecureContext) { return navigator.clipboard.writeText(text).then(done) }
                    const field = document.createElement('textarea');
                    field.value = text; field.setAttribute('readonly', ''); field.style.position = 'fixed'; field.style.opacity = '0';
                    document.body.appendChild(field); field.select(); document.execCommand('copy'); field.remove(); done();
                },
            }">
                <x-ui.button variant="outline" size="sm" icon="link" x-on:click="copy({{ \Illuminate\Support\Js::from($this->shareUrl) }})">
                    <span x-show="! copied">{{ __('Copy link') }}</span>
                    <span x-show="copied" x-cloak>{{ __('Link copied') }}</span>
                </x-ui.button>
            </div>
        </x-slot:actions>

        {{-- The matchup builder --}}
        <x-ui.card padding="none" class="shadow-card" id="matchup">
            <div class="flex flex-wrap items-end gap-x-8 gap-y-5 border-b border-line bg-surface-muted px-5 py-4 sm:px-6">
                <flux:radio.group wire:model.live="d" :label="__('Discipline')" variant="segmented" size="sm">
                    <flux:radio value="singles" :label="__('Singles')" icon="user" />
                    <flux:radio value="doubles" :label="__('Doubles')" icon="user-group" />
                </flux:radio.group>

                <flux:radio.group wire:model.live="bo" :label="__('Match')" variant="segmented" size="sm">
                    <flux:radio value="3" :label="__('Best of 3')" />
                    <flux:radio value="1" :label="__('One game')" />
                </flux:radio.group>

                <flux:radio.group wire:model.live="ppg" :label="__('Points per game')" variant="segmented" size="sm">
                    <flux:radio value="21" label="21" />
                    <flux:radio value="15" label="15" />
                    <flux:radio value="11" label="11" />
                </flux:radio.group>

                <x-ui.button variant="ghost" size="sm" icon="arrows-right-left" wire:click="swap" class="sm:ms-auto">{{ __('Swap sides') }}</x-ui.button>
            </div>

            <div class="grid md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
                @foreach (['a' => ['a1', 'a2'], 'b' => ['b1', 'b2']] as $side => $slots)
                    @if ($side === 'b')
                        <div class="relative flex items-center justify-center md:px-2" aria-hidden="true">
                            <span class="absolute inset-x-0 top-1/2 h-px bg-line md:hidden"></span>
                            <span class="num relative bg-surface px-3 text-[28px] font-extrabold italic leading-none text-ink-muted md:px-0">{{ __('vs') }}</span>
                        </div>
                    @endif

                    <div
                        role="group"
                        aria-labelledby="side-{{ $side }}-label"
                        @class([
                            'min-w-0 border-t-4 px-5 pt-5 pb-6 sm:px-6',
                            'border-heading' => $side === 'a',
                            'border-orange-400' => $side === 'b',
                        ])
                        wire:key="side-{{ $side }}"
                    >
                        <p id="side-{{ $side }}-label" @class([
                            'label-caps',
                            'text-heading' => $side === 'a',
                            'text-orange-700 dark:text-orange-300' => $side === 'b',
                        ])>{{ $side === 'a' ? __('Side A') : __('Side B') }}</p>

                        <div @class(['mt-3 grid gap-4', 'sm:grid-cols-2' => $doubles])>
                            @foreach ($doubles ? $slots : [$slots[0]] as $slot)
                                <flux:select
                                    wire:model.live="{{ $slot }}"
                                    :label="$loop->first ? __('Player') : __('Partner')"
                                    :placeholder="$loop->first ? __('Choose a player') : __('Choose a partner')"
                                    wire:key="select-{{ $slot }}-{{ $discipline->value }}"
                                >
                                    @foreach ($this->players as $player)
                                        <option
                                            value="{{ $player->id }}"
                                            @disabled(in_array($player->id, $taken, true) && $player->id !== $this->{$slot})
                                            wire:key="o-{{ $slot }}-{{ $player->id }}"
                                        >{{ $player->name }} · {{ $player->rating($discipline) }}</option>
                                    @endforeach
                                </flux:select>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        {{-- The result --}}
        <div class="mt-8" wire:loading.delay.class="opacity-60" wire:target="d,a1,a2,b1,b2,bo,ppg,surprise,swap">
            @if ($p === null)
                <x-ui.empty :title="$this->missing['title'] ?? __('Pick two players')" variant="bordered">
                    {{ $this->missing['text'] ?? '' }}
                    <x-slot:actions>
                        <x-ui.button variant="primary" size="sm" icon="sparkles" wire:click="surprise">{{ __('Surprise me') }}</x-ui.button>
                    </x-slot:actions>
                </x-ui.empty>
            @else
                @php
                    $favourite = $p->favourite;
                    $favName = $favourite === \App\Enums\Side::B ? $names['b'] : $names['a'];
                    $gameFav = $favourite === \App\Enums\Side::B ? 1 - $p->gameProbabilityA : $p->gameProbabilityA;
                    $rallyFav = $favourite === \App\Enums\Side::B ? 1 - $p->rallyProbabilityA : $p->rallyProbabilityA;
                    $eloFav = $favourite === \App\Enums\Side::B ? 1 - $p->eloProbabilityA : $p->eloProbabilityA;
                    $gap = $p->ratingA - $p->ratingB;
                    $factors = collect($p->factors)->reject(fn ($f) => $f['key'] === 'provisional')->values();
                    $notes = collect($p->factors)->where('key', 'provisional')->values();
                    $scale = max(100.0, abs($p->effectiveDiff), ...$factors->map(fn ($f) => abs($f['shift']))->all());
                    $meetings = $this->meetings;
                    $ranks = $this->ranks;
                    $typicalWinner = $favourite === \App\Enums\Side::B ? $names['b'] : $names['a'];
                    $stakeRows = collect([\App\Enums\Side::A, \App\Enums\Side::B])->flatMap(fn ($side) => collect($lineup[$side->value])->map(fn ($user) => [
                        'side' => $side,
                        'user' => $user,
                        'win' => $p->stakeFor($user->id, $side),
                        'lose' => $p->stakeFor($user->id, $side->opposite()),
                    ]));
                    $straight = SmashConfig::float('rating.straight_games_bonus', 0.10);
                    $pointBonus = SmashConfig::float('rating.max_point_bonus', 0.20);
                    $decider = SmashConfig::float('rating.decider_factor', 0.90);
                    $tournament = SmashConfig::float('rating.tournament_multiplier', 1.25);
                @endphp

                <div class="space-y-6">
                    {{-- Verdict --}}
                    <x-ui.card variant="navy" padding="lg" class="overflow-hidden" data-prediction-verdict>
                        <div class="pointer-events-none absolute inset-0 -z-0 overflow-hidden" aria-hidden="true">
                            <x-ui.court-lines orientation="horizontal" :stroke="1.5" class="absolute top-1/2 -right-24 h-[140%] w-auto -translate-y-1/2 text-white/[0.06] max-lg:hidden" />
                        </div>
                        <div class="relative">
                            <p class="kicker">{{ $discipline->label() }} · {{ $formatLabel }}</p>
                            <p class="mt-3 max-w-4xl font-serif text-h3 text-heading">{{ $this->verdict }}</p>

                            <x-smash.win-probability
                                class="mt-8"
                                :prediction="$p"
                                :label-a="$names['a']"
                                :label-b="$names['b']"
                                variant="large"
                            />

                            <dl class="mt-10 grid grid-cols-2 gap-x-6 gap-y-6 border-t border-line pt-6 md:grid-cols-4">
                                <div>
                                    <dt class="label-caps text-ink-muted">{{ __('Rating gap') }}</dt>
                                    <dd class="mt-2 num text-[34px] font-extrabold leading-none text-ink">{{ (int) round(abs($gap)) }}</dd>
                                    <dd class="mt-1.5 text-sm text-ink-soft">{{ round(abs($gap)) < 1 ? __('Dead level') : __('In favour of :name', ['name' => $gap > 0 ? $names['a'] : $names['b']]) }}</dd>
                                </div>
                                <div>
                                    <dt class="label-caps text-ink-muted">{{ __('On rating alone') }}</dt>
                                    <dd class="mt-2 num text-[34px] font-extrabold leading-none text-ink">{{ (int) round($eloFav * 100) }}<span class="text-[0.55em]">%</span></dd>
                                    <dd class="mt-1.5 text-sm text-ink-soft">{{ __(':name, before form and head-to-head', ['name' => $favName]) }}</dd>
                                </div>
                                <div>
                                    <dt class="label-caps text-ink-muted">{{ __('Wins a game') }}</dt>
                                    <dd class="mt-2 num text-[34px] font-extrabold leading-none text-ink">{{ (int) round($gameFav * 100) }}<span class="text-[0.55em]">%</span></dd>
                                    <dd class="mt-1.5 text-sm text-ink-soft">{{ __(':name, any single game', ['name' => $favName]) }}</dd>
                                </div>
                                <div>
                                    <dt class="label-caps text-ink-muted">{{ __('Wins a rally') }}</dt>
                                    <dd class="mt-2 num text-[34px] font-extrabold leading-none text-ink">{{ number_format($rallyFav * 100, 1) }}<span class="text-[0.55em]">%</span></dd>
                                    <dd class="mt-1.5 text-sm text-ink-soft">{{ __('The edge on every point') }}</dd>
                                </div>
                            </dl>
                        </div>
                    </x-ui.card>

                    <div class="grid gap-6 lg:grid-cols-12">
                        {{-- Factors --}}
                        <x-ui.card padding="lg" class="lg:col-span-7" data-prediction-factors>
                            <x-ui.heading :level="3" size="h4">{{ __('What moves the needle') }}</x-ui.heading>
                            <p class="mt-1.5 text-sm text-ink-muted">{{ __('Each factor shifts the gap in Elo points, towards the side it helps.') }}</p>

                            <div class="mt-4 divide-y divide-line">
                                @foreach ($factors as $factor)
                                    <x-predictions.factor
                                        wire:key="factor-{{ $factor['key'] }}"
                                        :label="$factor['label']"
                                        :detail="$factor['detail']"
                                        :shift="$factor['shift']"
                                        :scale="$scale"
                                        :name-a="$names['a']"
                                        :name-b="$names['b']"
                                    />
                                @endforeach
                                <x-predictions.factor
                                    class="border-t-2 !border-t-ink"
                                    total
                                    :label="__('Effective gap')"
                                    :detail="__('All three added up, then turned into a win chance on the Elo curve: :p% for :name.', ['p' => $p->percentFor($favourite ?? \App\Enums\Side::A), 'name' => $favourite === \App\Enums\Side::B ? $names['b'] : $names['a']])"
                                    :shift="$p->effectiveDiff"
                                    :scale="$scale"
                                    :name-a="$names['a']"
                                    :name-b="$names['b']"
                                />
                            </div>

                            @foreach ($notes as $note)
                                <x-ui.alert tone="warning" class="mt-4" :title="__('Provisional rating')">{{ $note['detail'] }}.</x-ui.alert>
                            @endforeach
                        </x-ui.card>

                        {{-- Scorelines --}}
                        <x-ui.card padding="lg" class="lg:col-span-5" data-prediction-scorelines-card>
                            <x-ui.heading :level="3" size="h4">{{ __('Likely scorelines') }}</x-ui.heading>
                            <p class="mt-1.5 text-sm text-ink-muted">
                                @if ($bo === 1)
                                    {{ __('One game decides it.') }}
                                @else
                                    {{ __('How the :n games are likely to fall.', ['n' => $bo]) }}
                                @endif
                            </p>

                            <x-predictions.scorelines class="mt-6" :distribution="$p->scoreDistribution" :name-a="$names['a']" :name-b="$names['b']" />

                            <div class="mt-6 flex items-end justify-between gap-4 border-t border-line pt-4">
                                <div>
                                    <p class="label-caps text-ink-muted">{{ __('Typical game') }}</p>
                                    <p class="mt-1 text-sm text-ink-soft">{{ ($favourite === \App\Enums\Side::B ? $names['pairB'] : $names['pairA']) ? __('When :name win one', ['name' => $typicalWinner]) : __('When :name wins one', ['name' => $typicalWinner]) }}</p>
                                </div>
                                <p class="num text-[34px] font-extrabold leading-none text-ink">{{ $p->expectedGameScore[0] }}–{{ $p->expectedGameScore[1] }}</p>
                            </div>
                        </x-ui.card>

                        {{-- Stakes --}}
                        <x-ui.card padding="none" class="lg:col-span-7 lg:self-start" data-prediction-stakes>
                            <div class="px-6 pt-6 sm:px-8 sm:pt-8">
                                <x-ui.heading :level="3" size="h4">{{ __('What’s at stake') }}</x-ui.heading>
                                <p class="mt-1.5 text-sm text-ink-muted">{{ __('Rating points each player would win or lose in a friendly.') }}</p>
                            </div>
                            <x-ui.table flush :hover="false" class="mt-5 border-t border-line">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>{{ __('Player') }}</th>
                                            <th class="text-end">{{ __('Rating') }}</th>
                                            <th class="text-end">{{ __('Win') }}</th>
                                            <th class="text-end">{{ __('Lose') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($stakeRows as $row)
                                            @php $rank = $ranks[$row['user']->id] ?? null; @endphp
                                            <tr wire:key="stake-{{ $row['user']->id }}">
                                                <td @class([
                                                    'min-w-0 max-w-[14rem]',
                                                    'shadow-[inset_3px_0_0_var(--color-navy-800)] dark:shadow-[inset_3px_0_0_var(--color-navy-400)]' => $row['side'] === \App\Enums\Side::A,
                                                    'shadow-[inset_3px_0_0_var(--color-orange-400)]' => $row['side'] === \App\Enums\Side::B,
                                                ])>
                                                    <x-smash.player :user="$row['user']" size="sm" :meta="$rank ? __('#:rank in :discipline', ['rank' => $rank, 'discipline' => strtolower($discipline->label())]) : __('Not ranked yet')" />
                                                </td>
                                                <td class="text-end"><x-smash.rating :user="$row['user']" :discipline="$discipline" size="sm" /></td>
                                                <td class="text-end"><x-smash.rating-delta :value="$row['win']" size="md" :label="__('If they win')" /></td>
                                                <td class="text-end"><x-smash.rating-delta :value="$row['lose']" size="md" :label="__('If they lose')" /></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </x-ui.table>
                            <p class="mt-auto border-t border-line px-6 py-4 text-sm leading-relaxed text-ink-muted sm:px-8">
                                {{ __('For a typical result. A straight-games win can move ratings up to :max% more, a three-game decider :decider% less, and tournament matches count :t×.', [
                                    'max' => (int) round(((1 + $straight) * (1 + $pointBonus) - 1) * 100),
                                    'decider' => (int) round((1 - $decider) * 100),
                                    't' => rtrim(rtrim(number_format($tournament, 2), '0'), '.'),
                                ]) }}
                            </p>
                        </x-ui.card>

                        {{-- Head-to-head --}}
                        <x-ui.card padding="lg" class="lg:col-span-5" data-prediction-h2h>
                            <x-ui.heading :level="3" size="h4">{{ __('Head-to-head') }}</x-ui.heading>

                            @if ($meetings === null || $meetings['matches']->isEmpty())
                                <p class="mt-1.5 text-sm text-ink-muted">{{ __('Previous rated :discipline meetings between these sides.', ['discipline' => strtolower($discipline->label())]) }}</p>
                                <div class="mt-6 flex items-center gap-4 border border-dashed border-line-strong px-5 py-6">
                                    <x-ui.shuttle class="size-10 shrink-0 text-heading" />
                                    <div>
                                        <p class="font-serif text-h5 text-heading">{{ __('First meeting') }}</p>
                                        <p class="mt-1 text-sm text-ink-soft">{{ __('No rated results between these sides yet, so history doesn’t move the needle.') }}</p>
                                    </div>
                                </div>
                            @else
                                @php $total = $meetings['matches']->count(); @endphp
                                <p class="mt-1.5 text-sm text-ink-muted">{{ trans_choice(':n rated meeting|:n rated meetings', $total, ['n' => $total]) }} · {{ __('games :a–:b', ['a' => $meetings['gamesA'], 'b' => $meetings['gamesB']]) }}</p>

                                <x-ui.versus class="mt-6">
                                    <x-slot:a><span class="min-w-0 truncate text-[15px] font-semibold text-ink">{{ $names['a'] }}</span></x-slot:a>
                                    <x-slot:center>
                                        <span class="num text-[44px] font-extrabold leading-none text-ink">{{ $meetings['winsA'] }}–{{ $meetings['winsB'] }}</span>
                                        <span class="label-caps mt-1 text-ink-muted">{{ __('Wins') }}</span>
                                    </x-slot:center>
                                    <x-slot:b><span class="min-w-0 truncate text-[15px] font-semibold text-ink">{{ $names['b'] }}</span></x-slot:b>
                                </x-ui.versus>

                                <div class="mt-6 space-y-3">
                                    @foreach ($meetings['matches']->take(3) as $match)
                                        <x-smash.match-card :match="$match" :for="$lineup['a'][0]" variant="compact" wire:key="meeting-{{ $match->id }}" />
                                    @endforeach
                                </div>

                                @if (! $doubles && Route::has('head-to-head'))
                                    <x-ui.link class="mt-6" :href="route('head-to-head', ['a' => $lineup['a'][0]->id, 'b' => $lineup['b'][0]->id])">{{ __('Full head-to-head') }}</x-ui.link>
                                @endif
                            @endif
                        </x-ui.card>
                    </div>
                </div>
            @endif
        </div>
    </x-ui.section>

    {{-- Fixtures closest to 50–50: computed once per visit (the island skips re-renders). --}}
    @island(name: 'closest')
        <x-ui.section
            tone="grey"
            :kicker="__('Fixtures')"
            :title="__('Too close to call')"
            :intro="__('The scheduled matches SMASH finds hardest to split. Think you know better? Make your picks in pick’em.')"
        >
            <x-slot:actions>
                <x-ui.link :href="route('predictions.index')">{{ __('Play pick’em') }}</x-ui.link>
            </x-slot:actions>

            @if ($this->closest->isEmpty())
                <x-ui.empty :title="__('No fixtures to call yet')" variant="plain" class="bg-surface">
                    {{ __('Scheduled matches show up here as soon as both sides are known.') }}
                </x-ui.empty>
            @else
                <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
                    @foreach ($this->closest as $row)
                        <div class="flex min-w-0 flex-col gap-3" wire:key="closest-{{ $row['match']->id }}">
                            <x-smash.match-card :match="$row['match']" :probability="$row['prediction']" class="flex-1" />
                            <x-ui.link :href="$row['url']" class="self-start">{{ __('Break it down') }}</x-ui.link>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.section>
    @endisland

    <x-ui.section :kicker="__('Under the bonnet')" :title="__('How SMASH calls it')" :intro="__('No gut feeling, no favourites: the same four steps for every matchup, using only results logged at the club.')">
        <div class="grid gap-x-10 gap-y-10 md:grid-cols-2 xl:grid-cols-4">
            <x-ui.step number="1" :title="__('Ratings')">
                {{ __('Every rated match moves an Elo rating, separately for singles and doubles. A doubles pair counts as the average of both partners.') }}
            </x-ui.step>
            <x-ui.step number="2" :title="__('Form and history')">
                {{ __('Recent results shift the gap by up to :form points and earlier meetings by up to :h2h, so a hot streak or a nemesis counts.', ['form' => SmashConfig::int('prediction.form_cap', 40), 'h2h' => SmashConfig::int('prediction.h2h_max_shift', 50)]) }}
            </x-ui.step>
            <x-ui.step number="3" :title="__('Games and rallies')">
                {{ __('The match chance is broken down into the chance of winning one game, and of winning one rally.') }}
            </x-ui.step>
            <x-ui.step number="4" :title="__('Every scoreline')">
                {{ __('Replaying rally scoring (win by two, golden point at the cap) gives the likely scorelines and a typical game score.') }}
            </x-ui.step>
        </div>
        <div class="mt-10 flex flex-wrap items-center gap-x-8 gap-y-4">
            <x-ui.button variant="outline" :href="route('how-it-works')">{{ __('Read the full explainer') }}</x-ui.button>
            <x-ui.link :href="route('how-it-works').'#accuracy'">{{ __('How accurate is it?') }}</x-ui.link>
        </div>
    </x-ui.section>
</div>
