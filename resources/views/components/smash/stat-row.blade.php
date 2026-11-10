@props([
    /*
     | x-smash.stat-row — a strip of numbers with hairline dividers (2 columns on phones).
     |
     | items        list of x-ui.stat props: ['value' => 1698, 'label' => 'Singles rating',
     |              'sublabel' => 'Peak 1712', 'delta' => 12.4, 'suffix' => '%', 'tone' => 'smash'].
     |              Or put x-ui.stat (or any blocks) in the slot instead.
     | columns      Columns from md up: 2 · 3 · 4 (default: number of items, max 4) · 5 · 6
     | size         x-ui.stat size: sm · md · lg
     | variant      cells (white cells on hairlines, default) · plain (no fills; for navy bands)
     */
    'items' => [],
    'columns' => null,
    'size' => 'md',
    'variant' => 'cells',
])

@php
    $items = array_values($items);
    $count = count($items);
    $columns = (int) ($columns ?? max(2, min(4, $count ?: 4)));

    $grid = match ($columns) {
        2 => 'md:grid-cols-2',
        3 => 'md:grid-cols-3',
        5 => 'md:grid-cols-3 lg:grid-cols-5',
        6 => 'md:grid-cols-3 lg:grid-cols-6',
        default => 'md:grid-cols-4',
    };

    $cell = $variant === 'plain' ? 'py-2' : 'bg-surface p-5 sm:p-6';
@endphp

<div
    {{ $attributes->class([
        'grid grid-cols-2',
        $grid,
        'gap-px border border-line bg-line *:bg-surface' => $variant !== 'plain',
        'gap-x-6 gap-y-8' => $variant === 'plain',
    ]) }}
    data-smash-stat-row
>
    @if ($count)
        @foreach ($items as $i => $item)
            <x-ui.stat
                @class([$cell, 'max-md:col-span-2' => $count % 2 === 1 && $i === $count - 1])
                :value="$item['value'] ?? '–'"
                :label="$item['label'] ?? ''"
                :sublabel="$item['sublabel'] ?? null"
                :delta="$item['delta'] ?? null"
                :delta-suffix="$item['deltaSuffix'] ?? null"
                :suffix="$item['suffix'] ?? null"
                :tone="$item['tone'] ?? null"
                :size="$size"
            />
        @endforeach
    @else
        {{ $slot }}
    @endif
</div>
