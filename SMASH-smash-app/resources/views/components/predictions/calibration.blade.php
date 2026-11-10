@props([
    /*
     | x-predictions.calibration — calibration chart for PredictionBacktest::run(): for each band of
     | the favourite's predicted chance, how often the favourite actually won. Dots on the dashed
     | diagonal = perfectly calibrated; below = favourites won less often than predicted.
     | Lines and grid are a stretched inline SVG; dots, counts and axis labels are HTML so they stay
     | round and legible at any width. A table with the same numbers follows (table = false hides it).
     |
     | buckets   PredictionBacktest calibration: list of {bucket, predicted, actual, count}.
     | label     Accessible name / caption.
     | table     Show the numbers under the chart.
     */
    'buckets' => [],
    'label' => 'Calibration',
    'table' => true,
])

@php
    $buckets = array_values(array_filter($buckets, fn ($b) => ($b['count'] ?? 0) > 0));
    $minActual = $buckets === [] ? 0.5 : min(array_column($buckets, 'actual'));
    // y from 50 % (or lower, in steps of 10, if a band did worse than a coin flip) to 100 %.
    $yMin = min(50, (int) floor($minActual * 10) * 10);
    $x = fn (float $p): float => round(($p * 100 - 50) / 50 * 100, 3);
    $y = fn (float $p): float => round(100 - ($p * 100 - $yMin) / (100 - $yMin) * 100, 3);
    $yTicks = range($yMin, 100, 10);
    $xTicks = [50, 60, 70, 80, 90, 100];
    $line = collect($buckets)->map(fn ($b) => $x($b['predicted']).','.$y($b['actual']))->join(' ');
    $total = array_sum(array_column($buckets, 'count'));
@endphp

<figure {{ $attributes->class('min-w-0') }} data-calibration>
    @if ($buckets === [])
        <x-ui.empty :title="__('Not enough results yet')" variant="bordered">
            {{ __('The chart fills in once rated matches have been played between players with different ratings.') }}
        </x-ui.empty>
    @else
        <p class="sr-only">
            {{ __(':label. Each point compares the chance SMASH gave the favourite with how often the favourite won.', ['label' => $label]) }}
            @foreach ($buckets as $b)
                {{ __(':bucket: predicted :p%, won :a% of :n matches.', ['bucket' => $b['bucket'], 'p' => (int) round($b['predicted'] * 100), 'a' => (int) round($b['actual'] * 100), 'n' => $b['count']]) }}
            @endforeach
        </p>

        <div class="relative ps-11 pe-3 pt-3 pb-9" aria-hidden="true">
            <div class="relative h-64 sm:h-80">
                {{-- Grid, diagonal and the line through the points --}}
                <svg class="absolute inset-0 size-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none" focusable="false">
                    @foreach ($yTicks as $tick)
                        <line x1="0" x2="100" y1="{{ $y($tick / 100) }}" y2="{{ $y($tick / 100) }}" class="stroke-line" stroke-width="1" vector-effect="non-scaling-stroke" />
                    @endforeach
                    @foreach ($xTicks as $tick)
                        <line y1="0" y2="100" x1="{{ $x($tick / 100) }}" x2="{{ $x($tick / 100) }}" class="stroke-line" stroke-width="1" vector-effect="non-scaling-stroke" />
                    @endforeach
                    {{-- Perfect calibration: predicted = actual --}}
                    <line x1="{{ $x(0.5) }}" y1="{{ $y(0.5) }}" x2="{{ $x(1) }}" y2="{{ $y(1) }}" class="stroke-ink-muted" stroke-width="1.5" stroke-dasharray="5 5" vector-effect="non-scaling-stroke" />
                    <polyline points="{{ $line }}" fill="none" class="stroke-heading" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                </svg>

                <span class="absolute -translate-x-full text-[12px] whitespace-nowrap text-ink-muted max-sm:hidden" style="left: {{ $x(0.99) }}%; top: {{ $y(0.855) }}%">{{ __('Perfectly calibrated') }}</span>

                {{-- Points with counts and hover details --}}
                @foreach ($buckets as $b)
                    <span
                        class="group absolute z-10 -translate-x-1/2 -translate-y-1/2 p-2 outline-none"
                        style="left: {{ $x($b['predicted']) }}%; top: {{ $y($b['actual']) }}%"
                        tabindex="0"
                        wire:key="cal-{{ $loop->index }}"
                    >
                        <span class="block size-3 rounded-full bg-heading ring-2 ring-surface transition-transform duration-150 ease-brand group-hover:scale-125 group-focus:scale-125"></span>
                        <span class="num absolute top-1/2 left-full -translate-y-1/2 ps-0.5 text-[13px] font-semibold whitespace-nowrap text-ink-soft">{{ $b['count'] }}</span>
                        <span class="pointer-events-none absolute bottom-full left-1/2 mb-1 hidden w-max -translate-x-1/2 border border-line bg-surface px-3 py-2 text-[13px] leading-snug text-ink shadow-card group-hover:block group-focus:block">
                            <span class="block font-semibold">{{ __('Favourite at :bucket', ['bucket' => $b['bucket']]) }}</span>
                            <span class="block text-ink-soft">{{ __('Predicted :p% · won :a%', ['p' => (int) round($b['predicted'] * 100), 'a' => (int) round($b['actual'] * 100)]) }}</span>
                            <span class="block text-ink-muted">{{ trans_choice(':n match|:n matches', $b['count'], ['n' => $b['count']]) }}</span>
                        </span>
                    </span>
                @endforeach

                {{-- Axis labels --}}
                @foreach ($yTicks as $tick)
                    <span class="num absolute -left-2 -translate-x-full -translate-y-1/2 text-[13px] text-ink-muted" style="top: {{ $y($tick / 100) }}%">{{ $tick }}%</span>
                @endforeach
                @foreach ($xTicks as $tick)
                    <span class="num absolute -bottom-2 -translate-x-1/2 translate-y-full text-[13px] text-ink-muted" style="left: {{ $x($tick / 100) }}%">{{ $tick }}%</span>
                @endforeach
            </div>
        </div>

        <figcaption class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2 text-[13px] text-ink-soft">
            <span class="inline-flex items-center gap-2"><span class="size-3 rounded-full bg-heading" aria-hidden="true"></span>{{ __('How often the favourite won (number = matches)') }}</span>
            <span class="inline-flex items-center gap-2"><span class="w-5 border-t-[1.5px] border-dashed border-ink-muted" aria-hidden="true"></span>{{ __('Chance SMASH gave the favourite') }}</span>
        </figcaption>

        @if ($table)
            <x-ui.table class="mt-6" dense :hover="false">
                <table>
                    <caption class="sr-only">{{ $label }}</caption>
                    <thead>
                        <tr>
                            <th>{{ __('Band') }}</th>
                            <th class="text-end">{{ __('Predicted') }}</th>
                            <th class="text-end">{{ __('Won') }}</th>
                            <th class="text-end">{{ __('Matches') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($buckets as $b)
                            <tr wire:key="calrow-{{ $loop->index }}">
                                <td class="num text-[17px] font-semibold text-ink">{{ $b['bucket'] }}</td>
                                <td class="num text-end text-[17px] text-ink-soft">{{ (int) round($b['predicted'] * 100) }}%</td>
                                <td class="num text-end text-[17px] font-semibold text-ink">{{ (int) round($b['actual'] * 100) }}%</td>
                                <td class="num text-end text-[17px] text-ink-soft">{{ $b['count'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.table>
        @endif
    @endif
</figure>
