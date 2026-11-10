@props([
    // ['Format' => 'Single elimination', 'Best of' => '3 games'] — or use x-ui.facts.item children.
    'items' => [],
    // Columns from sm up: 1 · 2 · 3 · 4
    'columns' => 2,
])

{{-- Key facts as a definition list with hairline separators. --}}
<dl {{ $attributes->class([
    'grid gap-x-8 gap-y-5',
    'sm:grid-cols-2' => (int) $columns === 2,
    'sm:grid-cols-2 lg:grid-cols-3' => (int) $columns === 3,
    'sm:grid-cols-2 lg:grid-cols-4' => (int) $columns >= 4,
]) }}>
    @foreach ($items as $label => $value)
        <x-ui.facts.item :label="$label">{{ $value }}</x-ui.facts.item>
    @endforeach
    {{ $slot }}
</dl>
