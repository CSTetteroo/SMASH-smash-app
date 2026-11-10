@props([
    // info · success · warning · danger — or a tone name (navy · sky · orange · green · smash · grey)
    'tone' => 'info',
    'title' => null,
    // Heroicon name; a sensible default per tone.
    'icon' => null,
    'dismissible' => false,
])

@php
    $toneName = match ($tone) {
        'info' => 'navy',
        'success' => 'green',
        'warning' => 'orange',
        'danger', 'error' => 'smash',
        default => $tone,
    };

    $icon ??= match ($toneName) {
        'green' => 'check-circle',
        'orange' => 'exclamation-triangle',
        'smash' => 'exclamation-circle',
        default => 'information-circle',
    };

    $role = in_array($toneName, ['smash', 'orange'], true) ? 'alert' : 'status';
@endphp

<div
    role="{{ $role }}"
    @if ($dismissible) x-data="{ shown: true }" x-show="shown" x-transition.opacity @endif
    {{ $attributes->class(['tone-'.$toneName, 'flex items-start gap-3 border-s-4 border-[var(--tone)] bg-[var(--tone-soft)] px-4 py-3.5 text-[15px] text-ink-soft']) }}
>
    <flux:icon :icon="$icon" variant="mini" class="mt-0.5 size-5 shrink-0 text-[var(--tone-ink)]" />
    <div class="min-w-0 flex-1">
        @if ($title)
            <p class="font-semibold text-ink">{{ $title }}</p>
        @endif
        @if (trim((string) $slot) !== '')
            <div @class(['leading-relaxed', 'mt-0.5' => $title])>{{ $slot }}</div>
        @endif
        @isset($actions)
            <div class="mt-3 flex flex-wrap gap-2">{{ $actions }}</div>
        @endisset
    </div>
    @if ($dismissible)
        <button type="button" x-on:click="shown = false" class="-m-1 inline-flex size-8 shrink-0 items-center justify-center text-ink-muted hover:text-ink" aria-label="{{ __('Dismiss') }}">
            <flux:icon.x-mark variant="mini" class="size-4" />
        </button>
    @endif
</div>
