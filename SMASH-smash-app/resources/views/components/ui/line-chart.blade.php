@props([
    // Chronological points. Each: ['date' => Carbon|string, 'rating' => float, 'change' => ?float]
    // (PlayerStats::ratingHistory format) — 'value'/'y' and 'x' are accepted too.
    'points' => [],
    // What is plotted ("Singles rating"). Used for the accessible name and the table caption.
    'label' => 'Rating',
    // Optional reference line, e.g. the 1500 starting rating.
    'baseline' => null,
    'baselineLabel' => null,
    // Chart height in px.
    'height' => 220,
    // Show the "Show the numbers" table toggle.
    'table' => true,
])

{{-- Kept for older markup: renders x-smash.rating-chart (x = match order), the canonical rating chart. --}}
<x-smash.rating-chart
    :points="$points"
    :label="$label"
    :baseline="$baseline"
    :baseline-label="$baselineLabel"
    :height="$height"
    :table="$table"
    scale="index"
    {{ $attributes }}
/>
