@props([
    // Winning side: 'a' | 'b' | null (accepts App\Enums\Side). The losing side is dimmed.
    'winner' => null,
    // row (A · centre · B) · stacked (A above B, e.g. narrow cards)
    'layout' => 'row',
])

{{-- Two sides of a match. Slots: $a, $b, $center (score / "vs" / time). --}}
@php
    $winner = $winner instanceof \BackedEnum ? $winner->value : $winner;
@endphp

@if ($layout === 'stacked')
    <div {{ $attributes->class('grid gap-2') }}>
        <div @class(['flex min-w-0 items-center justify-between gap-3', 'opacity-55' => $winner === 'b'])>{{ $a }}</div>
        @isset($center)
            <div class="label-caps text-ink-muted">{{ $center }}</div>
        @endisset
        <div @class(['flex min-w-0 items-center justify-between gap-3', 'opacity-55' => $winner === 'a'])>{{ $b }}</div>
    </div>
@else
    <div {{ $attributes->class('grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-3 sm:gap-6') }}>
        <div @class(['flex min-w-0 justify-end text-end', 'opacity-55' => $winner === 'b'])>{{ $a }}</div>
        <div class="flex flex-col items-center text-center">
            @isset($center)
                {{ $center }}
            @else
                <span class="num text-lg font-bold uppercase text-ink-muted">{{ __('vs') }}</span>
            @endisset
        </div>
        <div @class(['flex min-w-0 justify-start', 'opacity-55' => $winner === 'a'])>{{ $b }}</div>
    </div>
@endif
