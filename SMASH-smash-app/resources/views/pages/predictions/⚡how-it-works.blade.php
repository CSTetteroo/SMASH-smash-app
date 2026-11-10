<?php

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\MatchPlayer;
use App\Models\RatingAdjustment;
use App\Models\User;
use App\Services\Prediction\MatchPrediction;
use App\Services\Prediction\MatchPredictor;
use App\Services\Prediction\PredictionBacktest;
use App\Services\Rating\EloCalculator;
use App\Services\Rating\RatedHistory;
use App\Support\Badminton\Scoring;
use App\Support\SmashConfig;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('How it works')] class extends Component {
    /**
     * Every tunable the explainer quotes, straight from config/smash.php.
     *
     * @return array<string, float|int>
     */
    #[Computed]
    public function settings(): array
    {
        return [
            'initial' => SmashConfig::int('rating.initial', 1500),
            'provisional' => SmashConfig::int('rating.provisional_matches', 10),
            'kProvisional' => SmashConfig::float('rating.k_provisional', 40.0),
            'kEstablished' => SmashConfig::float('rating.k_established', 24.0),
            'tournament' => SmashConfig::float('rating.tournament_multiplier', 1.25),
            'straight' => SmashConfig::float('rating.straight_games_bonus', 0.10),
            'decider' => SmashConfig::float('rating.decider_factor', 0.90),
            'pointBonus' => SmashConfig::float('rating.max_point_bonus', 0.20),
            'pointCap' => EloCalculator::POINT_DIFF_CAP,
            'scale' => SmashConfig::int('rating.scale', 400),
            'formWindow' => SmashConfig::int('prediction.form_window', 5),
            'formPoints' => SmashConfig::float('prediction.form_points_per_net_win', 8.0),
            'formCap' => SmashConfig::int('prediction.form_cap', 40),
            'h2hWeight' => (int) MatchPredictor::H2H_WEIGHT,
            'h2hMeetings' => MatchPredictor::H2H_FULL_WEIGHT_MEETINGS,
            'h2hCap' => SmashConfig::int('prediction.h2h_max_shift', 50),
            'upset' => SmashConfig::int('upset_threshold', 100),
        ];
    }

    /**
     * The latest rated singles result, recomputed step by step with the rating maths.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function example(): ?array
    {
        $match = BadmintonMatch::query()
            ->withDetails()
            ->where('matches.status', MatchStatus::Completed->value)
            ->where('matches.is_rated', true)
            ->where('matches.discipline', Discipline::Singles->value)
            ->whereNotNull('matches.winning_side')
            ->whereNotNull('matches.played_at')
            ->whereDoesntHave('participants', fn ($query) => $query->whereNull('rating_before')->orWhereNull('rating_after'))
            ->has('participants', '=', 2)
            ->has('games')
            ->latestFirst()
            ->first();

        if ($match === null || $match->winning_side === null || $match->played_at === null) {
            return null;
        }

        $winnerSide = $match->winning_side;
        $winner = $match->participants->first(fn (MatchPlayer $mp): bool => $mp->side === $winnerSide);
        $loser = $match->participants->first(fn (MatchPlayer $mp): bool => $mp->side === $winnerSide->opposite());

        if ($winner === null || $loser === null) {
            return null;
        }

        $history = RatedHistory::load(Discipline::Singles, [$winner->user_id, $loser->user_id]);
        $at = RatedHistory::format($match->played_at);
        $tournament = $match->tournament_id !== null;
        $playedW = count($history->before($winner->user_id, $at, $match->id));
        $playedL = count($history->before($loser->user_id, $at, $match->id));
        $expected = EloCalculator::expected((float) $winner->rating_before, (float) $loser->rating_before);
        $gamesW = $match->gamesWon($winnerSide);
        $gamesL = $match->gamesWon($winnerSide->opposite());
        $pointsW = $match->pointsWon($winnerSide);
        $pointsL = $match->pointsWon($winnerSide->opposite());
        $margin = EloCalculator::marginMultiplier($gamesW, $gamesL, $pointsW - $pointsL, $match->best_of);
        $kW = EloCalculator::kFactor($playedW, $tournament);
        $kL = EloCalculator::kFactor($playedL, $tournament);

        return [
            'match' => $match,
            'winner' => $winner,
            'loser' => $loser,
            'playedW' => $playedW,
            'playedL' => $playedL,
            'kW' => $kW,
            'kL' => $kL,
            'expected' => $expected,
            'gamesW' => $gamesW,
            'gamesL' => $gamesL,
            'pointsW' => $pointsW,
            'pointsL' => $pointsL,
            'format' => match (true) {
                $match->best_of <= 1 => 1.0,
                $gamesL === 0 => 1 + $this->settings['straight'],
                $gamesW + $gamesL >= $match->best_of => $this->settings['decider'],
                default => 1.0,
            },
            'margin' => $margin,
            'deltaW' => $kW * $margin * (1 - $expected),
            'deltaL' => -$kL * $margin * (1 - $expected),
            'tournament' => $tournament,
        ];
    }

    /**
     * The latest rated doubles result's team ratings (mean of both partners before the match).
     *
     * @return array{match: BadmintonMatch, a: list<MatchPlayer>, b: list<MatchPlayer>, teamA: float, teamB: float}|null
     */
    #[Computed]
    public function doubles(): ?array
    {
        $match = BadmintonMatch::query()
            ->withDetails()
            ->where('matches.status', MatchStatus::Completed->value)
            ->where('matches.is_rated', true)
            ->where('matches.discipline', Discipline::Doubles->value)
            ->whereDoesntHave('participants', fn ($query) => $query->whereNull('rating_before'))
            ->has('participants', '=', 4)
            ->latestFirst()
            ->first();

        if ($match === null) {
            return null;
        }

        $a = $match->participants->filter(fn (MatchPlayer $mp): bool => $mp->side === Side::A)->values()->all();
        $b = $match->participants->filter(fn (MatchPlayer $mp): bool => $mp->side === Side::B)->values()->all();
        $mean = fn (array $players): float => array_sum(array_map(fn (MatchPlayer $mp): float => (float) $mp->rating_before, $players)) / max(1, count($players));

        return ['match' => $match, 'a' => $a, 'b' => $b, 'teamA' => $mean($a), 'teamB' => $mean($b)];
    }

    /**
     * The predictor on the top two of the singles rankings.
     *
     * @return array{a: User, b: User, prediction: MatchPrediction}|null
     */
    #[Computed]
    public function sample(): ?array
    {
        $top = User::query()->ranked(Discipline::Singles)->limit(2)->get();

        if ($top->count() < 2) {
            return null;
        }

        return [
            'a' => $top[0],
            'b' => $top[1],
            'prediction' => MatchPredictor::predict([$top[0]], [$top[1]], Discipline::Singles),
        ];
    }

    /**
     * How the pure rating chance has done on every rated result, overall and per discipline.
     *
     * @return array{all: array<string, mixed>, singles: array<string, mixed>, doubles: array<string, mixed>}
     */
    #[Computed]
    public function backtest(): array
    {
        return [
            'all' => PredictionBacktest::run(),
            'singles' => PredictionBacktest::run(Discipline::Singles),
            'doubles' => PredictionBacktest::run(Discipline::Doubles),
        ];
    }

    /**
     * The biggest upsets from the backtest, as matches (in order of how unlikely they were).
     *
     * @return list<array{match: BadmintonMatch, probability: float}>
     */
    #[Computed]
    public function upsets(): array
    {
        $rows = $this->backtest['all']['biggestUpsets'];
        $matches = BadmintonMatch::query()->withDetails()->whereIn('matches.id', array_column($rows, 'match_id'))->get()->keyBy('id');

        $upsets = [];

        foreach ($rows as $row) {
            if ($matches->has($row['match_id'])) {
                $upsets[] = ['match' => $matches->get($row['match_id']), 'probability' => (float) $row['probability']];
            }
        }

        return $upsets;
    }

    #[Computed]
    public function adjustments(): int
    {
        return RatingAdjustment::query()->count();
    }

    /**
     * Example game scores, judged by the same rules the score sheets use.
     *
     * @return list<array{a: int, b: int, state: string, note: string}>
     */
    #[Computed]
    public function games(): array
    {
        $examples = [
            [21, 15, 'First to 21 with two clear.'],
            [21, 20, 'One point clear is not enough.'],
            [24, 22, 'Past 20–20, first to lead by two.'],
            [30, 29, 'At 29–29 the next rally wins it.'],
            [31, 29, '30 is the cap, so this can’t happen.'],
        ];

        return array_map(fn (array $game): array => [
            'a' => $game[0],
            'b' => $game[1],
            'state' => Scoring::isCompleteGame($game[0], $game[1], 21) ? 'game' : (Scoring::isValidInProgressGame($game[0], $game[1], 21) ? 'play-on' : 'impossible'),
            'note' => $game[2],
        ], $examples);
    }

    /**
     * Short formats: target => cap.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function caps(): array
    {
        return [21 => Scoring::cap(21), 15 => Scoring::cap(15), 11 => Scoring::cap(11)];
    }
}; ?>

@php
    $s = $this->settings;
    $number = fn (float $value, int $decimals = 2): string => rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
    $percent = fn (float $p, int $decimals = 0): string => number_format($p * 100, $decimals).'%';
    $signed = fn (float $v, int $decimals = 1): string => round(abs($v), $decimals) == 0 ? '0' : ($v > 0 ? '+' : '−').number_format(abs($v), $decimals);
    $bt = $this->backtest;
    $all = $bt['all'];
    $toc = [
        'ratings' => __('Ratings'),
        'predictor' => __('The predictor'),
        'accuracy' => __('How accurate is it?'),
        'scoring' => __('Badminton scoring'),
        'adjustments' => __('Rating adjustments'),
        'pickem' => __('Pick’em rules'),
    ];
@endphp

<div>
    <x-ui.page-hero
        :breadcrumbs="[__('How it works')]"
        :kicker="__('Ratings, odds and the rules')"
        :title="__('How it works')"
        :intro="__('Every SMASH number comes from results logged at the club: no committee opinions, no hidden tweaks. Here is exactly how ratings move, how the predictor calls a match, how often it’s right, and how a game of badminton is scored.')"
    >
        <x-slot:actions>
            <x-ui.button variant="primary" :href="route('predictor')">{{ __('Try the predictor') }}</x-ui.button>
            <x-ui.link :href="route('rankings.index')">{{ __('See the rankings') }}</x-ui.link>
        </x-slot:actions>

        <x-slot:aside>
            <p class="kicker">{{ __('On this page') }}</p>
            <nav aria-label="{{ __('On this page') }}" class="mt-3">
                <ol class="divide-y divide-line">
                    @foreach ($toc as $anchor => $label)
                        <li>
                            <a href="#{{ $anchor }}" class="group flex items-baseline gap-4 py-2.5 text-[15px] text-ink hover:text-link">
                                <span class="num w-6 shrink-0 text-[17px] font-bold text-ink-muted group-hover:text-link">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                <span class="group-hover:underline">{{ $label }}</span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            </nav>
        </x-slot:aside>
    </x-ui.page-hero>

    {{-- 1. Ratings --}}
    <x-ui.section id="ratings" :kicker="__('01 · Ratings')" :title="__('Elo ratings in SMASH')" class="scroll-mt-24">
        <div class="grid gap-x-12 gap-y-10 lg:grid-cols-12">
            <div class="min-w-0 max-w-2xl space-y-5 text-[16px] leading-relaxed text-ink-soft lg:col-span-7">
                <p>
                    {{ __('Every member starts on :initial. After each rated match the winner takes points from the loser, and how many depends on how surprising the result was: beat someone rated far above you and you jump; beat someone far below and you barely move.', ['initial' => $s['initial']]) }}
                </p>
                <p>
                    {{ __('Singles and doubles are rated separately, because being brilliant at one says little about the other. In doubles a pair counts as the average of both partners’ doubles ratings, and each partner then moves by their own K-factor.') }}
                </p>

                <x-ui.heading :level="3" size="h4" class="pt-4">{{ __('Step 1: the expected score') }}</x-ui.heading>
                <p>{{ __('Before a match, the rating gap turns into a chance of winning. A gap of :scale points makes the stronger side ten times as likely to win as to lose.', ['scale' => $s['scale']]) }}</p>
                <div class="border-s-4 border-heading bg-surface-muted px-6 py-5" role="math" aria-label="{{ __('Expected score of A equals 1 divided by 1 plus 10 to the power of rating B minus rating A, divided by :scale', ['scale' => $s['scale']]) }}">
                    <p class="font-serif text-h4 text-heading" aria-hidden="true">
                        E<sub class="text-[0.6em]">A</sub> = 1 ÷ (1 + 10<sup class="text-[0.6em]">(R<sub>B</sub> − R<sub>A</sub>) ÷ {{ $s['scale'] }}</sup>)
                    </p>
                    <p class="mt-2 text-sm text-ink-muted" aria-hidden="true">{{ __('R = rating before the match, E = chance of winning (0 to 1)') }}</p>
                </div>

                <x-ui.heading :level="3" size="h4" class="pt-4">{{ __('Step 2: the change') }}</x-ui.heading>
                <div class="border-s-4 border-heading bg-surface-muted px-6 py-5" role="math" aria-label="{{ __('Rating change equals K times margin times result minus expected score') }}">
                    <p class="font-serif text-h4 text-heading" aria-hidden="true">Δ = K × M × (S − E)</p>
                    <p class="mt-2 text-sm text-ink-muted" aria-hidden="true">{{ __('S = 1 for a win, 0 for a loss · K = how fast ratings move · M = margin of victory') }}</p>
                </div>
                <p>
                    {{ __('New players move fast so they find their level quickly; established players move more slowly so one bad night doesn’t undo a season. Tournament matches count a bit more than friendlies.') }}
                </p>
                <p>
                    {{ __('The margin rewards a convincing win: a straight-games win counts :straight more, a three-game decider :decider less, and the total point difference adds up to :bonus more (full bonus at :cap points clear). Walkovers, cancelled and unrated matches never change a rating.', [
                        'straight' => $percent($s['straight']),
                        'decider' => $percent(1 - $s['decider']),
                        'bonus' => $percent($s['pointBonus']),
                        'cap' => $s['pointCap'],
                    ]) }}
                </p>
            </div>

            <div class="min-w-0 space-y-6 lg:col-span-5">
                <x-ui.card padding="lg" highlight="navy">
                    <p class="kicker">{{ __('The numbers') }}</p>
                    <dl class="mt-4 divide-y divide-line">
                        @foreach ([
                            [__('Starting rating'), $s['initial'], __('Everyone, in both disciplines')],
                            [__('K, provisional'), $number($s['kProvisional']), __('First :n rated matches', ['n' => $s['provisional']])],
                            [__('K, established'), $number($s['kEstablished']), __('From match :n on', ['n' => $s['provisional'] + 1])],
                            [__('Tournament'), '×'.$number($s['tournament']), __('K :a and :b in tournaments', ['a' => $number($s['kProvisional'] * $s['tournament'], 1), 'b' => $number($s['kEstablished'] * $s['tournament'], 1)])],
                            [__('Straight games'), '×'.$number(1 + $s['straight']), __('Won without dropping a game')],
                            [__('Decider'), '×'.$number($s['decider']), __('Went to the final game')],
                            [__('Point difference'), '+'.$percent($s['pointBonus']), __('At :cap points or more clear', ['cap' => $s['pointCap']])],
                        ] as [$label, $value, $note])
                            <div class="flex items-baseline justify-between gap-4 py-3">
                                <dt class="min-w-0">
                                    <span class="block text-[15px] font-semibold text-ink">{{ $label }}</span>
                                    <span class="block text-sm text-ink-muted">{{ $note }}</span>
                                </dt>
                                <dd class="num shrink-0 text-[28px] font-extrabold leading-none text-heading">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="mt-4 text-sm text-ink-muted">{{ __('Until then a rating is provisional and shows an orange P next to it.') }}</p>
                </x-ui.card>

                @if ($d = $this->doubles)
                    <x-ui.card padding="lg">
                        <p class="kicker">{{ __('Doubles: the pair is the average') }}</p>
                        <div class="mt-4 space-y-4">
                            @foreach (['a' => [$d['a'], $d['teamA']], 'b' => [$d['b'], $d['teamB']]] as $side => [$pair, $team])
                                <div class="flex items-center justify-between gap-4" wire:key="dbl-{{ $side }}">
                                    <div class="min-w-0 space-y-1">
                                        @foreach ($pair as $mp)
                                            <p class="flex items-baseline justify-between gap-3 text-[15px]" wire:key="dbl-{{ $mp->id }}">
                                                <span class="truncate text-ink">{{ $mp->user->name }}</span>
                                                <span class="num text-[17px] font-semibold text-ink-soft">{{ (int) round((float) $mp->rating_before) }}</span>
                                            </p>
                                        @endforeach
                                    </div>
                                    <p class="shrink-0 border-s border-line ps-4 text-end">
                                        <span class="label-caps block text-ink-muted">{{ __('Team') }}</span>
                                        <span class="num text-[28px] font-extrabold leading-none text-heading">{{ (int) round($team) }}</span>
                                    </p>
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-4 text-sm text-ink-muted">
                            {{ __('Ratings before') }} <a href="{{ route('matches.show', $d['match']) }}" wire:navigate class="link">{{ __('the latest rated doubles match') }}</a>{{ $d['match']->played_at ? ' ('.$d['match']->played_at->format('D j M').')' : '' }}.
                        </p>
                    </x-ui.card>
                @endif
            </div>
        </div>

        {{-- Worked example --}}
        @if ($e = $this->example)
            @php
                $m = $e['match'];
                $w = $e['winner'];
                $l = $e['loser'];
                $storedW = (float) $w->rating_after - (float) $w->rating_before;
                $storedL = (float) $l->rating_after - (float) $l->rating_before;
                $favourite = $e['expected'] >= 0.5;
            @endphp
            <div class="mt-14 border-t border-line pt-10" data-worked-example>
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="kicker">{{ __('Worked example') }}</p>
                        <x-ui.heading :level="3" size="h3" class="mt-2">{{ __(':w beat :l :score', ['w' => $w->user->name, 'l' => $l->user->name, 'score' => $e['gamesW'].'–'.$e['gamesL']]) }}</x-ui.heading>
                        <p class="mt-2 text-[15px] text-ink-soft">
                            {{ __('The latest rated singles result:') }}
                            {{ $m->tournament ? $m->tournament->name.($m->roundLabel() ? ', '.$m->roundLabel() : '') : __('a friendly') }},
                            {{ $m->played_at?->format('l j F') }}.
                        </p>
                    </div>
                    <x-ui.link :href="route('matches.show', $m)">{{ __('Match report') }}</x-ui.link>
                </div>

                <ol class="mt-8 grid gap-px bg-line md:grid-cols-2 xl:grid-cols-4">
                    <li class="bg-surface p-6">
                        <p class="label-caps text-ink-muted">{{ __('1 · Before') }}</p>
                        <p class="mt-3 flex items-baseline justify-between gap-3"><span class="truncate text-[15px] text-ink">{{ $w->user->name }}</span><span class="num text-[26px] font-extrabold leading-none text-heading">{{ (int) round((float) $w->rating_before) }}</span></p>
                        <p class="mt-2 flex items-baseline justify-between gap-3"><span class="truncate text-[15px] text-ink">{{ $l->user->name }}</span><span class="num text-[26px] font-extrabold leading-none text-ink-soft">{{ (int) round((float) $l->rating_before) }}</span></p>
                        <p class="mt-3 text-sm text-ink-muted">{{ __('A gap of :gap in favour of :name.', ['gap' => (int) round(abs((float) $w->rating_before - (float) $l->rating_before)), 'name' => $favourite ? $w->user->firstName() : $l->user->firstName()]) }}</p>
                    </li>
                    <li class="bg-surface p-6">
                        <p class="label-caps text-ink-muted">{{ __('2 · Expected') }}</p>
                        <p class="num mt-3 text-[40px] font-extrabold leading-none text-heading">{{ $percent($e['expected'], 1) }}</p>
                        <p class="mt-3 text-sm text-ink-muted">
                            {{ $favourite
                                ? __(':name was the favourite, so the win was no surprise.', ['name' => $w->user->firstName()])
                                : __(':name was the underdog, which is why the win is worth more.', ['name' => $w->user->firstName()]) }}
                        </p>
                    </li>
                    <li class="bg-surface p-6">
                        <p class="label-caps text-ink-muted">{{ __('3 · Margin') }}</p>
                        <p class="num mt-3 text-[40px] font-extrabold leading-none text-heading">×{{ number_format($e['margin'], 3) }}</p>
                        <p class="mt-3 text-sm text-ink-muted">
                            {{ __(':games in games (×:format), :pw–:pl in points (+:diff).', [
                                'games' => $e['gamesW'].'–'.$e['gamesL'],
                                'format' => $number($e['format']),
                                'pw' => $e['pointsW'],
                                'pl' => $e['pointsL'],
                                'diff' => max(0, $e['pointsW'] - $e['pointsL']),
                            ]) }}
                        </p>
                    </li>
                    <li class="bg-surface p-6">
                        <p class="label-caps text-ink-muted">{{ __('4 · Change') }}</p>
                        <p class="mt-3 flex items-baseline justify-between gap-3"><span class="truncate text-[15px] text-ink">{{ $w->user->firstName() }}</span><x-smash.rating-delta :value="$storedW" :precision="1" size="lg" /></p>
                        <p class="mt-2 flex items-baseline justify-between gap-3"><span class="truncate text-[15px] text-ink">{{ $l->user->firstName() }}</span><x-smash.rating-delta :value="$storedL" :precision="1" size="lg" /></p>
                        <p class="mt-3 text-sm text-ink-muted">{{ __('Each with their own K.') }}</p>
                    </li>
                </ol>

                <div class="relative mt-6 overflow-x-auto">
                    <div class="grid min-w-0 gap-x-10 gap-y-4 md:grid-cols-2">
                        @foreach ([[$w, $e['kW'], $e['playedW'], '1 − '.number_format($e['expected'], 3), $e['deltaW'], 'text-win'], [$l, $e['kL'], $e['playedL'], '0 − '.number_format(1 - $e['expected'], 3), $e['deltaL'], 'text-loss']] as [$mp, $k, $before, $score, $delta, $tone])
                            <div wire:key="calc-{{ $mp->id }}">
                                <p class="text-sm text-ink-muted">
                                    <span class="font-semibold text-ink">{{ $mp->user->firstName() }}</span>
                                    · {{ trans_choice(':n rated match before, so K :k|:n rated matches before, so K :k', $before, ['n' => $before, 'k' => $number($k, 1)]) }}
                                </p>
                                <p class="mt-1 font-serif text-[17px] whitespace-nowrap text-ink">
                                    {{ $number($k, 1) }} × {{ number_format($e['margin'], 3) }} × ({{ $score }}) = <span @class(['num text-[20px] font-bold', $tone])>{{ $signed($delta) }}</span>
                                </p>
                            </div>
                        @endforeach
                    </div>
                </div>
                <p class="mt-4 max-w-3xl text-sm text-ink-muted">
                    {{ __(':w went from :wb to :wa and :l from :lb to :la. The numbers are rounded on screen; ratings are stored to the decimal.', [
                        'w' => $w->user->firstName(),
                        'wb' => (int) round((float) $w->rating_before),
                        'wa' => (int) round((float) $w->rating_after),
                        'l' => $l->user->firstName(),
                        'lb' => (int) round((float) $l->rating_before),
                        'la' => (int) round((float) $l->rating_after),
                    ]) }}
                    @if ($e['tournament'])
                        {{ __('Tournament match, so both K-factors include the ×:t.', ['t' => $number($s['tournament'])]) }}
                    @endif
                </p>
            </div>
        @endif
    </x-ui.section>

    {{-- 2. The predictor --}}
    <x-ui.section id="predictor" tone="grey" :kicker="__('02 · The predictor')" :title="__('How SMASH calls a match')" class="scroll-mt-24"
        :intro="__('The “Who wins?” predictor starts from the Elo chance above and adds what the rating can’t see yet: who is on a run, and who has the other’s number.')">
        <div class="grid gap-x-12 gap-y-10 lg:grid-cols-12">
            <div class="grid min-w-0 gap-x-10 gap-y-9 sm:grid-cols-2 lg:col-span-7 lg:grid-cols-1 xl:grid-cols-2">
                <x-ui.step number="1" :title="__('Rating gap')">
                    {{ __('Side A’s rating minus side B’s (pair averages in doubles). This is the starting point, in Elo points.') }}
                </x-ui.step>
                <x-ui.step number="2" :title="__('Form and head-to-head')">
                    {{ __('Form: each side’s wins minus losses over its last :n rated matches, :pts Elo points per net win, at most :cap either way. Head-to-head: earlier meetings between exactly these sides, (smoothed win rate − ½) × :w, at full weight after :m meetings and capped at :h2h.', [
                        'n' => $s['formWindow'], 'pts' => $number($s['formPoints']), 'cap' => $s['formCap'],
                        'w' => $s['h2hWeight'], 'm' => $s['h2hMeetings'], 'h2h' => $s['h2hCap'],
                    ]) }}
                </x-ui.step>
                <x-ui.step number="3" :title="__('Games and rallies')">
                    {{ __('The adjusted gap gives the match chance on the Elo curve. SMASH then works backwards to the chance of winning a single game that produces that match chance, and the chance of winning a single rally that produces that game chance.') }}
                </x-ui.step>
                <x-ui.step number="4" :title="__('Every scoreline')">
                    {{ __('Playing out rally scoring rally by rally (win by two, capped at :cap) gives the chance of each scoreline and the favourite’s typical winning game.', ['cap' => $this->caps[21]]) }}
                </x-ui.step>
            </div>

            @if ($sample = $this->sample)
                @php
                    $p = $sample['prediction'];
                    $favA = $p->probabilityA >= 0.5;
                    $favName = $favA ? $sample['a']->firstName() : $sample['b']->firstName();
                    $pick = fn (float $pa): float => $favA ? $pa : 1 - $pa;
                @endphp
                <x-ui.card padding="lg" class="min-w-0 lg:col-span-5" data-predictor-sample>
                    <p class="kicker">{{ __('Right now: #1 v #2 in singles') }}</p>
                    <p class="mt-2 font-serif text-h4 text-heading">{{ $sample['a']->name }} {{ __('v') }} {{ $sample['b']->name }}</p>

                    <dl class="mt-5 divide-y divide-line">
                        @foreach ($p->factors as $factor)
                            @continue($factor['key'] === 'provisional')
                            <div class="flex items-baseline justify-between gap-4 py-2.5" wire:key="sf-{{ $factor['key'] }}">
                                <dt class="min-w-0">
                                    <span class="block text-[15px] font-semibold text-ink">{{ $factor['label'] }}</span>
                                    <span class="block truncate text-sm text-ink-muted">{{ $factor['detail'] }}</span>
                                </dt>
                                <dd class="num shrink-0 text-[22px] font-bold text-ink">{{ $signed($factor['shift'], 0) }}</dd>
                            </div>
                        @endforeach
                        <div class="flex items-baseline justify-between gap-4 py-2.5">
                            <dt class="text-[15px] font-bold text-ink">{{ __('Adjusted gap') }}</dt>
                            <dd class="num text-[22px] font-extrabold text-heading">{{ $signed($p->effectiveDiff, 0) }}</dd>
                        </div>
                    </dl>

                    <div class="mt-5 grid grid-cols-3 gap-px bg-line">
                        <div class="bg-surface py-3 pe-2">
                            <p class="label-caps text-ink-muted">{{ __('Match') }}</p>
                            <p class="num mt-1 text-[28px] font-extrabold leading-none text-heading">{{ $percent($pick($p->probabilityA)) }}</p>
                        </div>
                        <div class="bg-surface px-3 py-3">
                            <p class="label-caps text-ink-muted">{{ __('Game') }}</p>
                            <p class="num mt-1 text-[28px] font-extrabold leading-none text-ink">{{ $percent($pick($p->gameProbabilityA)) }}</p>
                        </div>
                        <div class="bg-surface py-3 ps-3">
                            <p class="label-caps text-ink-muted">{{ __('Rally') }}</p>
                            <p class="num mt-1 text-[28px] font-extrabold leading-none text-ink">{{ $percent($pick($p->rallyProbabilityA), 1) }}</p>
                        </div>
                    </div>
                    <p class="mt-3 text-sm text-ink-muted">
                        {{ __('Chances for :name. A :rally edge on every rally is enough for :match of matches; the typical game is :score.', [
                            'name' => $favName,
                            'rally' => $percent($pick($p->rallyProbabilityA), 1),
                            'match' => $percent($pick($p->probabilityA)),
                            'score' => $p->expectedGameScore[0].'–'.$p->expectedGameScore[1],
                        ]) }}
                    </p>

                    <x-ui.button variant="outline" size="sm" class="mt-6" :href="route('predictor', ['d' => 'singles', 'a1' => $sample['a']->id, 'b1' => $sample['b']->id])">{{ __('Open in the predictor') }}</x-ui.button>
                </x-ui.card>
            @endif
        </div>
    </x-ui.section>

    {{-- 3. Accuracy --}}
    <x-ui.section id="accuracy" :kicker="__('03 · Model accuracy')" :title="__('How accurate is it?')" class="scroll-mt-24"
        :intro="__('We replay every rated result with the chance the ratings gave beforehand and check how often the favourite won. This test uses the pure rating chance (steps 1 and 3), so anyone can recompute it from the match pages.')">
        @if ($all['matches'] === 0)
            <x-ui.empty :title="__('No results to test yet')">{{ __('Accuracy shows up here once rated matches have been played.') }}</x-ui.empty>
        @else
            <x-smash.stat-row :items="[
                ['value' => (int) round($all['accuracy'] * 100), 'suffix' => '%', 'label' => __('Favourite won'), 'sublabel' => __(':c of :n results', ['c' => $all['correct'], 'n' => $all['matches']])],
                ['value' => number_format($all['brier'], 3), 'label' => __('Brier score'), 'sublabel' => __('Lower is better; a coin flip scores 0.250')],
                ['value' => $bt['singles']['accuracy'] === null ? '–' : (int) round($bt['singles']['accuracy'] * 100), 'suffix' => $bt['singles']['accuracy'] === null ? null : '%', 'label' => __('Singles'), 'sublabel' => trans_choice(':n result|:n results', $bt['singles']['matches'], ['n' => $bt['singles']['matches']])],
                ['value' => $bt['doubles']['accuracy'] === null ? '–' : (int) round($bt['doubles']['accuracy'] * 100), 'suffix' => $bt['doubles']['accuracy'] === null ? null : '%', 'label' => __('Doubles'), 'sublabel' => trans_choice(':n result|:n results', $bt['doubles']['matches'], ['n' => $bt['doubles']['matches']])],
            ]" />
            @if ($all['skipped'] > 0)
                <p class="mt-3 text-sm text-ink-muted">{{ trans_choice(':n result between evenly rated sides (exactly 50–50) is left out: there was no favourite to be right or wrong.|:n results between evenly rated sides (exactly 50–50) are left out: there was no favourite to be right or wrong.', $all['skipped'], ['n' => $all['skipped']]) }}</p>
            @endif

            <div class="mt-12 grid gap-x-12 gap-y-10 lg:grid-cols-12">
                <x-ui.card padding="lg" class="min-w-0 lg:col-span-7 lg:self-start" data-calibration-card>
                    <x-ui.heading :level="3" size="h4">{{ __('Calibration') }}</x-ui.heading>
                    <p class="mt-1.5 max-w-xl text-sm leading-relaxed text-ink-muted">{{ __('When SMASH gives a favourite 70%, they should win about 7 in 10. Points on the dashed line mean the chances are honest. Above it, favourites won more often than predicted; below it, less often.') }}</p>
                    <x-predictions.calibration class="mt-6" :buckets="$all['calibration']" :label="__('Calibration of the rating chance')" />
                </x-ui.card>

                <div class="min-w-0 lg:col-span-5">
                    <x-ui.heading :level="3" size="h4">{{ __('Biggest upsets') }}</x-ui.heading>
                    <p class="mt-1.5 text-sm text-ink-muted">{{ __('The results the ratings least saw coming.') }}</p>
                    <div class="mt-5 space-y-3">
                        @forelse ($this->upsets as $upset)
                            <div wire:key="upset-{{ $upset['match']->id }}">
                                <p class="mb-1.5 text-sm text-ink-soft">
                                    <span class="num text-[17px] font-bold text-loss">{{ $percent($upset['probability']) }}</span>
                                    {{ __('chance for :name', ['name' => $upset['match']->sideLabel($upset['match']->winning_side ?? Side::A)]) }}
                                </p>
                                <x-smash.match-card :match="$upset['match']" variant="compact" />
                            </div>
                        @empty
                            <x-ui.empty :title="__('No upsets yet')" variant="bordered">{{ __('Every favourite has won so far.') }}</x-ui.empty>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </x-ui.section>

    {{-- 4. Badminton scoring --}}
    <x-ui.section id="scoring" tone="grey" :kicker="__('04 · The rules')" :title="__('How badminton is scored')" class="scroll-mt-24">
        <div class="grid gap-x-12 gap-y-10 lg:grid-cols-12">
            <div class="min-w-0 max-w-2xl space-y-5 text-[16px] leading-relaxed text-ink-soft lg:col-span-7">
                <p>{{ __('Every rally wins a point, whoever served. A game goes to 21, but you have to win by two: at 20–20 play continues until one side leads by two, up to a cap of 30, where 29–29 becomes a single deciding point. A match is the best of three games.') }}</p>
                <ul class="space-y-3">
                    <li class="flex gap-3"><flux:icon.check-circle variant="mini" class="mt-1 size-5 shrink-0 text-heading" />{{ __('The winner of a rally serves the next one. Even score: serve from the right; odd score: from the left.') }}</li>
                    <li class="flex gap-3"><flux:icon.check-circle variant="mini" class="mt-1 size-5 shrink-0 text-heading" />{{ __('When the leading side reaches 11 there is a 60-second interval, and two minutes between games.') }}</li>
                    <li class="flex gap-3"><flux:icon.check-circle variant="mini" class="mt-1 size-5 shrink-0 text-heading" />{{ __('Ends change after each game, and in the third game when the leader reaches 11.') }}</li>
                    <li class="flex gap-3"><flux:icon.check-circle variant="mini" class="mt-1 size-5 shrink-0 text-heading" />{{ __('Shorter formats for busy club nights: to 15 (cap :c15) or to 11 (cap :c11), one game or best of three.', ['c15' => $this->caps[15], 'c11' => $this->caps[11]]) }}</li>
                </ul>
                <p>{{ __('Score sheets in SMASH are checked against exactly these rules, so an impossible score can’t be saved.') }}</p>
            </div>

            <x-ui.card padding="none" class="min-w-0 lg:col-span-5" data-scoring-examples>
                <div class="px-6 pt-6">
                    <p class="kicker">{{ __('Game over or play on?') }}</p>
                    <p class="mt-1 text-sm text-ink-muted">{{ __('Games to 21, as the score sheet sees them.') }}</p>
                </div>
                <ul class="mt-4 divide-y divide-line border-t border-line">
                    @foreach ($this->games as $game)
                        <li class="flex items-center gap-4 px-6 py-3.5" wire:key="g-{{ $game['a'] }}-{{ $game['b'] }}">
                            <span class="num w-20 shrink-0 whitespace-nowrap text-[26px] font-extrabold leading-none text-ink">{{ $game['a'] }}–{{ $game['b'] }}</span>
                            <span class="min-w-0 flex-1 text-sm text-ink-soft">{{ $game['note'] }}</span>
                            @switch($game['state'])
                                @case('game')
                                    <x-ui.tag tone="green" size="sm">{{ __('Game') }}</x-ui.tag>
                                    @break
                                @case('play-on')
                                    <x-ui.tag tone="orange" size="sm">{{ __('Play on') }}</x-ui.tag>
                                    @break
                                @default
                                    <x-ui.tag tone="smash" size="sm">{{ __('Not possible') }}</x-ui.tag>
                            @endswitch
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        </div>
    </x-ui.section>

    {{-- 5. Rating adjustments --}}
    <x-ui.section id="adjustments" :kicker="__('05 · Fair play')" :title="__('Rating adjustments')" class="scroll-mt-24">
        <div class="grid gap-x-12 gap-y-8 lg:grid-cols-12">
            <div class="min-w-0 max-w-2xl space-y-5 text-[16px] leading-relaxed text-ink-soft lg:col-span-7">
                <p>{{ __('Ratings are never typed in by hand. Very occasionally the committee applies an adjustment instead: a placement for an experienced player who joins mid-season, or a correction when a result was logged wrongly.') }}</p>
                <p>{{ __('Every adjustment is audited: who made it, the reason, and the date it counts from. It is limited to :max points either way and replayed in date order with all the matches, so everything after it is recalculated properly. It moves the rating, never the matches played or won.', ['max' => \App\Models\RatingAdjustment::MAX_AMOUNT]) }}</p>
                @can('manage')
                    @if (Route::has('rating-adjustments.index'))
                        <x-ui.link :href="route('rating-adjustments.index')">{{ __('Manage rating adjustments') }}</x-ui.link>
                    @endif
                @endcan
            </div>
            <div class="min-w-0 lg:col-span-5">
                <x-ui.card variant="muted" padding="lg">
                    <p class="label-caps text-ink-muted">{{ __('Adjustments this season') }}</p>
                    <p class="num mt-2 text-stat font-extrabold leading-none text-heading">{{ $this->adjustments }}</p>
                    <p class="mt-2 text-sm text-ink-soft">
                        {{ $this->adjustments === 0
                            ? __('None so far: every rating comes from match results alone.')
                            : trans_choice('{1} One rating has been placed or corrected by the committee.|[2,*] :n placements and corrections by the committee.', $this->adjustments, ['n' => $this->adjustments]) }}
                    </p>
                </x-ui.card>
            </div>
        </div>
    </x-ui.section>

    {{-- 6. Pick'em --}}
    <x-ui.section id="pickem" tone="grey" :kicker="__('06 · The prediction game')" :title="__('Pick’em rules')" class="scroll-mt-24">
        <div class="grid gap-x-12 gap-y-10 lg:grid-cols-12">
            <ol class="grid min-w-0 gap-x-10 gap-y-9 sm:grid-cols-2 lg:col-span-8">
                <li><x-ui.step number="1" :title="__('Pick before kick-off')">{{ __('Any member can call any scheduled match with both sides known: the winner, and in best-of-three matches the score. Change your mind as often as you like until it starts.') }}</x-ui.step></li>
                <li><x-ui.step number="2" :title="__('Score points')">{{ __(':a points for the right winner, :b more for the right number of games. Wrong winner, no points.', ['a' => \App\Models\Prediction::POINTS_CORRECT_WINNER, 'b' => \App\Models\Prediction::POINTS_EXACT_BONUS]) }}</x-ui.step></li>
                <li><x-ui.step number="3" :title="__('No picking yourself')">{{ __('You can’t pick a match you’re playing in. Walkovers and cancelled matches are void: nobody scores.') }}</x-ui.step></li>
                <li><x-ui.step number="4" :title="__('The table')">{{ __('Ranked by points, then correct picks, then exact scores, then accuracy. Ten correct picks earn the Oracle badge.') }}</x-ui.step></li>
            </ol>
            <x-ui.card variant="navy" padding="lg" class="min-w-0 lg:col-span-4">
                <p class="kicker">{{ __('Pick’em') }}</p>
                <p class="mt-3 font-serif text-h4 text-heading">{{ __('Think you can beat the model?') }}</p>
                <p class="mt-3 text-[15px] leading-relaxed text-ink-soft">{{ __('The ratings pick the favourite :p of the time. Upsets are where the points are.', ['p' => $all['accuracy'] === null ? '–' : $percent($all['accuracy'])]) }}</p>
                <x-ui.button variant="pill-orange" class="mt-6" :href="route('predictions.index')">{{ __('Make your picks') }}</x-ui.button>
            </x-ui.card>
        </div>
    </x-ui.section>
</div>
