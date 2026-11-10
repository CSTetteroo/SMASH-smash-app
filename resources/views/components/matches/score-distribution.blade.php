@props([
    /*
     | x-matches.score-distribution — the predictor's chance of each final scoreline as bars.
     | prediction  App\Services\Prediction\MatchPrediction (scoreDistribution is from side A's view:
     |             '2–0', '2–1', '1–2', '0–2').
     | names       ['a' => 'Lucas Janssen', 'b' => 'Jonas Weber'].
     | Side A's wins are navy, side B's orange (as in x-smash.win-probability).
     */
    'prediction',
    'names' => ['a' => 'Side A', 'b' => 'Side B'],
])

@php
    $rows = collect($prediction->scoreDistribution)
        ->map(function ($probability, $score) {
            [$a, $b] = array_map('intval', preg_split('/\D+/u', (string) $score) ?: [0, 0]);

            return ['score' => $score, 'a' => $a, 'b' => $b, 'probability' => (float) $probability, 'winner' => $a > $b ? 'a' : 'b'];
        })
        ->values();
    $max = max(0.0001, (float) $rows->max('probability'));
    $likeliest = $rows->sortByDesc('probability')->first();
@endphp

<div {{ $attributes->class('min-w-0') }}>
    <ul class="space-y-3" role="list">
        @foreach ($rows as $row)
            @php
                $winnerName = $names[$row['winner']] ?? '';
                $won = $row['winner'] === 'a' ? $row['a'] : $row['b'];
                $lost = $row['winner'] === 'a' ? $row['b'] : $row['a'];
                $percent = (int) round($row['probability'] * 100);
            @endphp
            <li class="grid grid-cols-[minmax(0,8rem)_minmax(0,1fr)_3rem] items-center gap-3 sm:grid-cols-[minmax(0,11rem)_minmax(0,1fr)_3.25rem]" wire:key="sd-{{ $row['score'] }}">
                <span class="flex min-w-0 items-baseline gap-2 text-sm" title="{{ $winnerName }} {{ $won }}–{{ $lost }}">
                    <span class="num shrink-0 text-[17px] font-bold text-ink">{{ $won }}–{{ $lost }}</span>
                    <span class="min-w-0 truncate font-semibold text-ink">{{ $winnerName }}</span>
                </span>
                <span class="relative h-3 bg-surface-muted" aria-hidden="true">
                    <span
                        @class([
                            'absolute inset-y-0 start-0',
                            'bg-navy-800 dark:bg-navy-300' => $row['winner'] === 'a',
                            'bg-orange-500 dark:bg-orange-400' => $row['winner'] === 'b',
                        ])
                        style="width: {{ round($row['probability'] / $max * 100, 1) }}%"
                    ></span>
                </span>
                <span @class(['num text-end text-[17px]', 'font-extrabold text-ink' => $row === $likeliest, 'font-semibold text-ink-soft' => $row !== $likeliest])>{{ $percent }}&nbsp;%</span>
            </li>
        @endforeach
    </ul>
</div>
