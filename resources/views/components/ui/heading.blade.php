@props([
    // Semantic level: 1–6 (renders <h1>…<h6>).
    'level' => 2,
    // Visual size: display · h1 · h2 · h3 · h4 · h5 (defaults from the level).
    'size' => null,
    // The BUas 50 × 2 px bar under the heading.
    'ornament' => null,
    // Optional orange kicker rendered above the heading.
    'kicker' => null,
    // centre the heading (and its ornament)
    'center' => false,
])

@php
    $level = max(1, min(6, (int) $level));
    $size ??= match ($level) {
        1 => 'h1',
        2 => 'h2',
        3 => 'h3',
        4 => 'h4',
        default => 'h5',
    };
    // Ornaments belong to page and section titles; smaller headings go without unless asked.
    $ornament ??= in_array($size, ['display', 'h1', 'h2'], true);

    $sizeClass = match ($size) {
        'display' => 'text-display',
        'h1' => 'text-h1',
        'h3' => 'text-h3',
        'h4' => 'text-h4',
        'h5' => 'text-h5',
        default => 'text-h2',
    };
@endphp

@if ($kicker)
    <x-ui.kicker @class(['mb-2.5', 'text-center' => $center])>{{ $kicker }}</x-ui.kicker>
@endif
<h{{ $level }} {{ $attributes->class([
    'font-serif font-normal tracking-[-0.005em] text-balance text-heading',
    $sizeClass,
    'ornament' => $ornament && ! $center,
    'ornament-center text-center' => $ornament && $center,
    'text-center' => $center && ! $ornament,
]) }}>{{ $slot }}</h{{ $level }}>
