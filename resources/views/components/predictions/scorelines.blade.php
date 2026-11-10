@props([
    /*
     | x-predictions.scorelines — the predictor's score distribution as columns: side A's wins
     | (navy) on the left, side B's (orange) on the right, each scoreline read from the winner's
     | point of view ("2–1 Wei"). Percentages add up to exactly 100 (largest remainder).
     |
     | distribution   MatchPrediction::scoreDistribution (side A's point of view, A's wins first,
     |                e.g. ['2–0' => .41, '2–1' => .29, '1–2' => .18, '0–2' => .12]).
     | nameA/B        Short side names under the scorelines.
     */
    'distribution' => [],
    'nameA' => 'Side A',
    'nameB' => 'Side B',
])

@php
    $columns = [];
    foreach ($distribution as $score => $probability) {
        $games = array_map('intval', preg_split('/\D+/u', (string) $score) ?: []);
        $a = $games[0] ?? 0;
        $b = $games[1] ?? 0;
        $columns[] = [
            'side' => $a > $b ? 'a' : 'b',
            'score' => max($a, $b).'–'.min($a, $b),
            'name' => $a > $b ? $nameA : $nameB,
            'p' => max(0.0, (float) $probability),
        ];
    }

    // Whole percentages that add up to 100: floor everything, hand out the rest by remainder.
    $total = array_sum(array_column($columns, 'p')) ?: 1.0;
    $floors = array_map(fn ($c) => (int) floor($c['p'] / $total * 100), $columns);
    $remainders = array_map(fn ($c, $f) => $c['p'] / $total * 100 - $f, $columns, $floors);
    arsort($remainders);
    $missing = 100 - array_sum($floors);
    foreach (array_keys($remainders) as $index) {
        if ($missing-- <= 0) {
            break;
        }
        $floors[$index]++;
    }
    foreach ($columns as $index => $column) {
        $columns[$index]['percent'] = $floors[$index] ?? 0;
    }

    $max = max(array_column($columns, 'p') ?: [1.0]) ?: 1.0;
    $likeliest = collect($columns)->sortByDesc('p')->keys()->first();

    $grid = match (count($columns)) {
        2 => 'grid-cols-2',
        4 => 'grid-cols-4',
        6 => 'grid-cols-6',
        default => 'grid-cols-3',
    };
@endphp

<div {{ $attributes->class('min-w-0') }} data-prediction-scorelines>
    <ul class="sr-only">
        @foreach ($columns as $column)
            <li>{{ __(':name win :score: :percent percent', ['name' => $column['name'], 'score' => $column['score'], 'percent' => $column['percent']]) }}</li>
        @endforeach
    </ul>

    <div aria-hidden="true">
        <div class="grid h-44 items-end gap-2 sm:gap-3 {{ $grid }}">
            @foreach ($columns as $index => $column)
                <div class="flex h-full min-w-0 flex-col justify-end">
                    <p @class([
                        'num leading-none',
                        'text-[26px] font-extrabold' => $index === $likeliest,
                        'text-[21px] font-bold' => $index !== $likeliest,
                        'text-heading' => $column['side'] === 'a' && $index === $likeliest,
                        'text-orange-700 dark:text-orange-300' => $column['side'] === 'b' && $index === $likeliest,
                        'text-ink-soft' => $index !== $likeliest,
                    ])>{{ $column['percent'] }}<span class="text-[0.6em]">%</span></p>
                    <span
                        @class([
                            'mt-2 block w-full transition-[height] duration-500 ease-brand',
                            'bg-heading' => $column['side'] === 'a',
                            'bg-orange-400' => $column['side'] === 'b',
                            'opacity-45' => $index !== $likeliest,
                        ])
                        style="height: {{ round(max(2, $column['p'] / $max * 74), 2) }}%"
                    ></span>
                </div>
            @endforeach
        </div>
        <div class="grid gap-2 border-t-2 border-ink pt-2.5 sm:gap-3 {{ $grid }}">
            @foreach ($columns as $column)
                <div class="min-w-0">
                    <p class="num text-[19px] font-bold leading-none text-ink">{{ $column['score'] }}</p>
                    <p class="mt-1 truncate text-[13px] text-ink-muted">{{ $column['name'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</div>
