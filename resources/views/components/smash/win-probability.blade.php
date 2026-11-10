@props([
    /*
     | x-smash.win-probability — two-sided win-chance bar. Side A navy, side B orange; the
     | favourite's side is emphasised. A tick marks 50 %. The canonical probability component
     | (x-ui.probability renders this one).
     |
     | a            Probability that side A wins (0–1).
     | prediction   Or a MatchPrediction (probabilityA, favourite, confidence are read from it).
     | match        BadmintonMatch: side names from its players (or tournament entries); "TBD"
     |              for unknown sides. Does NOT run the predictor: pass prediction or a as well.
     | labelA/B     Side names. Or pass sideA / sideB (users) and names are built from them.
     | sideA/B      Users (iterable) on each side; names use family names for pairs.
     | variant      compact (one line, for cards and tables) · default · large (predictor hero)
     | names        Show the side names: null = yes, except in compact; true adds a names row
     |              above the compact bar; false hides them (default / large).
     | confidence   Text under the bar ("Slight edge"). null = from the prediction or computed
     |              with the predictor's bands; false = hidden.
     | caption      Small heading above the bar (default variant and large), e.g. "Win probability".
     */
    'a' => null,
    'prediction' => null,
    'match' => null,
    'labelA' => null,
    'labelB' => null,
    'sideA' => null,
    'sideB' => null,
    'variant' => 'default',
    'names' => null,
    'confidence' => null,
    'caption' => null,
])

@php
    if ($match instanceof \App\Models\BadmintonMatch) {
        $playersOf = fn (\App\Enums\Side $side) => $match->tournament_id !== null && $match->entryOn($side) !== null
            ? $match->entryOn($side)->members()
            : $match->playersOn($side);

        $sideA ??= $playersOf(\App\Enums\Side::A);
        $sideB ??= $playersOf(\App\Enums\Side::B);
        $labelA ??= collect($sideA)->isEmpty() ? $match->sideLabel(\App\Enums\Side::A) : null;
        $labelB ??= collect($sideB)->isEmpty() ? $match->sideLabel(\App\Enums\Side::B) : null;
    }

    $probabilityA = is_object($prediction) && isset($prediction->probabilityA)
        ? (float) $prediction->probabilityA
        : (float) ($a ?? 0.5);
    $probabilityA = max(0.0, min(1.0, $probabilityA));

    // Whole percentages that add up to 100; never show 0/100 for a match that is still open.
    $percentA = (int) round($probabilityA * 100);
    if ($probabilityA > 0 && $probabilityA < 1) {
        $percentA = max(1, min(99, $percentA));
    }
    $percentB = 100 - $percentA;

    $favourite = match (true) {
        $percentA > $percentB => 'a',
        $percentB > $percentA => 'b',
        default => null,
    };

    $nameOf = function ($users): ?string {
        $members = collect($users ?? [])->filter(fn ($user) => $user instanceof \App\Models\User)->values();

        return match ($members->count()) {
            0 => null,
            1 => $members->first()->name,
            default => $members->map(fn ($user) => \Illuminate\Support\Str::ucfirst($user->lastName()))->join(' & '),
        };
    };
    $labelA ??= $nameOf($sideA) ?? __('Side A');
    $labelB ??= $nameOf($sideB) ?? __('Side B');

    if ($confidence === null) {
        $confidence = is_object($prediction) && isset($prediction->confidence)
            ? (string) $prediction->confidence
            : match (true) {
                max($probabilityA, 1 - $probabilityA) < 0.55 => __('Toss-up'),
                max($probabilityA, 1 - $probabilityA) < 0.65 => __('Slight edge'),
                max($probabilityA, 1 - $probabilityA) < 0.8 => __('Favourite'),
                default => __('Strong favourite'),
            };
    }

    $favouriteName = $favourite === 'a' ? $labelA : ($favourite === 'b' ? $labelB : null);

    $spoken = __('Win probability: :a :pa percent, :b :pb percent.', ['a' => $labelA, 'pa' => $percentA, 'b' => $labelB, 'pb' => $percentB])
        .($confidence ? ' '.$confidence.($favouriteName ? ': '.$favouriteName : '').'.' : '');

    $numberSize = match ($variant) {
        'compact' => 'text-[17px]',
        'large' => 'text-[clamp(3rem,2.2rem+3vw,4.75rem)]',
        default => 'text-[2.125rem]',
    };
    $bar = match ($variant) {
        'compact' => 'h-1.5',
        'large' => 'h-3.5',
        default => 'h-2.5',
    };

    // Number colours: side A heading navy, side B text-safe orange; the underdog goes grey.
    $numberA = $favourite === 'b' ? 'font-bold text-ink-muted' : 'font-extrabold text-heading';
    $numberB = $favourite === 'a'
        ? 'font-bold text-ink-muted'
        : 'font-extrabold text-orange-700 dark:text-orange-300 in-[.theme-navy]:text-orange-300';
@endphp

<div {{ $attributes->class('min-w-0') }} role="group" aria-label="{{ __('Win probability') }}" data-smash-win-probability>
    <p class="sr-only">{{ $spoken }}</p>

    @if ($variant === 'compact')
        @if ($names === true)
            <p class="mb-2 flex justify-between gap-3 text-sm font-medium text-ink" aria-hidden="true">
                <span class="truncate">{{ $labelA }}</span>
                <span class="truncate text-end">{{ $labelB }}</span>
            </p>
        @endif
        <div class="flex items-center gap-2.5" aria-hidden="true">
            <span class="num w-9 shrink-0 leading-none {{ $numberSize }} {{ $numberA }}">{{ $percentA }}<span class="text-[0.6em]">%</span></span>
            <span class="flex min-w-0 flex-1 gap-0.5 {{ $bar }}">
                <span class="h-full bg-heading" style="width: {{ $percentA }}%"></span>
                <span class="h-full flex-1 bg-orange-400"></span>
            </span>
            <span class="num w-9 shrink-0 text-end leading-none {{ $numberSize }} {{ $numberB }}">{{ $percentB }}<span class="text-[0.6em]">%</span></span>
        </div>
    @else
        <div aria-hidden="true">
            @if ($caption)
                <p @class(['label-caps mb-3 text-ink-muted', 'text-center' => $variant === 'large'])>{{ $caption }}</p>
            @endif

            <div class="flex items-end justify-between gap-4">
                <div class="min-w-0">
                    @if ($names !== false)
                        <p @class(['mb-1 truncate font-semibold text-ink', $variant === 'large' ? 'text-lg' : 'text-[15px]'])>{{ $labelA }}</p>
                    @endif
                    <p class="num leading-none {{ $numberSize }} {{ $numberA }}">{{ $percentA }}<span class="text-[0.55em]">%</span></p>
                </div>
                <div class="min-w-0 text-end">
                    @if ($names !== false)
                        <p @class(['mb-1 truncate font-semibold text-ink', $variant === 'large' ? 'text-lg' : 'text-[15px]'])>{{ $labelB }}</p>
                    @endif
                    <p class="num leading-none {{ $numberSize }} {{ $numberB }}">{{ $percentB }}<span class="text-[0.55em]">%</span></p>
                </div>
            </div>

            <div class="relative mt-3 flex gap-0.5 {{ $bar }}">
                <span class="h-full bg-heading transition-[width] duration-500 ease-brand" style="width: {{ $percentA }}%"></span>
                <span class="h-full flex-1 bg-orange-400"></span>
                {{-- 50 % mark under the bar --}}
                <span class="absolute top-full left-1/2 mt-1 h-1.5 w-px -translate-x-1/2 bg-line-strong"></span>
            </div>

            @if ($confidence)
                <p @class([
                    'mt-4 text-sm',
                    'text-center' => $variant === 'large',
                    'text-start' => $variant !== 'large' && $favourite !== 'b',
                    'text-end' => $variant !== 'large' && $favourite === 'b',
                ])>
                    <span class="label-caps text-ink-muted">{{ $confidence }}</span>
                    @if ($favouriteName && $variant === 'large' && $favourite !== null && max($percentA, $percentB) >= 55)
                        <span class="text-ink-soft">· {{ $favouriteName }}</span>
                    @endif
                </p>
            @endif
        </div>
    @endif
</div>
