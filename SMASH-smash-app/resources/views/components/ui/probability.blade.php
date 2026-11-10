@props([
    // Probability that side A wins, 0–1.
    'a' => 0.5,
    'labelA' => null,
    'labelB' => null,
    // sm (one line) · md · lg (predictor hero)
    'size' => 'md',
])

{{-- Kept for older markup: renders x-smash.win-probability, the canonical win-chance bar (side A navy, side B orange). --}}
@php
    $variant = match ($size) {
        'sm' => 'compact',
        'lg' => 'large',
        default => 'default',
    };
@endphp

<x-smash.win-probability :a="$a" :label-a="$labelA" :label-b="$labelB" :variant="$variant" :names="$labelA !== null || $labelB !== null" :confidence="false" {{ $attributes }} />
