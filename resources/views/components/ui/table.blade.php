@props([
    // Tighter rows for long admin lists.
    'dense' => false,
    // Row hover tint.
    'hover' => true,
    // Drop the outer border (when the table already sits in a card).
    'flush' => false,
])

{{--
    Table wrapper: consistent header type, row lines, hover and horizontal scroll on small screens.
    Put a plain <table> (thead/tbody) or a <flux:table> inside. Slots: $header (title + actions bar), $footer (pagination).
    Highlight a row with <tr data-current>. Right-align numbers with class="text-end num".
--}}
<div {{ $attributes->class([
    'ui-table min-w-0 bg-surface',
    'border border-line' => ! $flush,
    'ui-table--dense' => $dense,
    'ui-table--hover' => $hover,
]) }}>
    @isset($header)
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">{{ $header }}</div>
    @endisset

    <div class="relative overflow-x-auto overscroll-x-contain">
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="border-t border-line px-5 py-3">{{ $footer }}</div>
    @endisset
</div>
