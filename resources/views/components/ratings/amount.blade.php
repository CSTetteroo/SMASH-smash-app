@props([
    /*
     | x-ratings.amount — a rating adjustment's signed amount in Barlow: green "+150", red "−25".
     |
     | adjustment   App\Models\RatingAdjustment (or pass value)
     | value        float, when there is no model
     | size         sm 17 · md 20 (default) · lg 24
     */
    'adjustment' => null,
    'value' => null,
    'size' => 'md',
])

@php
    $amount = (float) ($adjustment?->amount ?? $value ?? 0);
@endphp

<span {{ $attributes->class([
    'num font-bold whitespace-nowrap',
    'text-[17px]' => $size === 'sm',
    'text-[20px]' => $size === 'md',
    'text-[24px] leading-none' => $size === 'lg',
    'text-win' => $amount > 0,
    'text-loss' => $amount < 0,
    'text-ink-muted' => $amount == 0,
]) }}>{{ \App\Models\RatingAdjustment::format($amount) }}<span class="sr-only"> {{ __('rating points') }}</span></span>
