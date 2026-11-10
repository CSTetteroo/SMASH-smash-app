@props([
    /*
     | x-predictions.split — how the club has picked a fixture: share of pick'em picks per side,
     | side A navy on the left, side B orange on the right (the win-probability colours).
     |
     | a / b      Number of picks for side A / side B.
     | nameA/B    Short side names.
     */
    'a' => 0,
    'b' => 0,
    'nameA' => 'Side A',
    'nameB' => 'Side B',
])

@php
    $a = max(0, (int) $a);
    $b = max(0, (int) $b);
    $total = $a + $b;
    $percentA = $total === 0 ? 50 : (int) round($a / $total * 100);
    $percentB = 100 - $percentA;
@endphp

<div {{ $attributes->class('min-w-0') }} data-pick-split>
    <div class="flex items-baseline justify-between gap-3">
        <p class="label-caps text-ink-muted">{{ __('Club picks') }}</p>
        <p class="text-[13px] text-ink-muted">{{ trans_choice('{0} No picks yet|{1} :n pick|[2,*] :n picks', $total, ['n' => $total]) }}</p>
    </div>

    @if ($total === 0)
        <div class="mt-2 h-1.5 bg-canvas-muted" aria-hidden="true"></div>
        <p class="mt-2 text-[13px] text-ink-soft">{{ __('Nobody has called this one yet.') }}</p>
    @else
        <p class="sr-only">{{ __(':a% of picks for :nameA, :b% for :nameB.', ['a' => $percentA, 'nameA' => $nameA, 'b' => $percentB, 'nameB' => $nameB]) }}</p>
        <div class="mt-2 flex h-1.5 gap-0.5" aria-hidden="true">
            @if ($a > 0)
                <span class="bg-heading" style="width: {{ $percentA }}%"></span>
            @endif
            @if ($b > 0)
                <span class="bg-orange-400" style="width: {{ $percentB }}%"></span>
            @endif
        </div>
        <p class="mt-2 flex items-baseline justify-between gap-3 text-[13px] leading-none" aria-hidden="true">
            <span class="min-w-0 truncate"><span class="num text-[17px] font-bold text-heading">{{ $percentA }}%</span> <span class="text-ink-soft">{{ $nameA }}</span></span>
            <span class="min-w-0 truncate text-end"><span class="text-ink-soft">{{ $nameB }}</span> <span class="num text-[17px] font-bold text-orange-700 dark:text-orange-300">{{ $percentB }}%</span></span>
        </p>
    @endif
</div>
