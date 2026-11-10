@props([
    // App\Services\Stats\Achievement
    'achievement',
])

{{--
    One badge on a profile: earned badges in their tone (4px top bar, filled icon tile, date),
    locked badges greyed out with a dashed tile and progress towards the target when there is one.
--}}
@php
    $a = $achievement;
    $tone = $a->earned ? $a->tone : 'grey';

    // Peak 1700 counts up from the starting rating, not from zero.
    $initial = (int) config('smash.rating.initial', 1500);
    $isPeak = $a->key === 'peak_1700' && $a->progress !== null;
    $progressValue = $a->progress === null ? null : ($isPeak ? max(0, $a->progress['current'] - $initial) : $a->progress['current']);
    $progressMax = $a->progress === null ? null : ($isPeak ? max(1, $a->progress['target'] - $initial) : $a->progress['target']);
    $progressText = $a->progress === null ? null : ($isPeak
        ? __('Peak :current of :target', ['current' => $a->progress['current'], 'target' => $a->progress['target']])
        : $a->progress['current'].' / '.$a->progress['target']);
@endphp

<div
    {{ $attributes->class([
        'tone-'.$tone,
        'flex min-w-0 gap-4 border border-line border-t-4 p-4 sm:p-5',
        'border-t-[var(--tone)] bg-surface' => $a->earned,
        'border-t-line-strong bg-surface-muted' => ! $a->earned,
    ]) }}
    data-achievement="{{ $a->key }}"
    data-earned="{{ $a->earned ? 'true' : 'false' }}"
>
    <span @class([
        'inline-flex size-12 shrink-0 items-center justify-center',
        'bg-[var(--tone-solid)] text-[var(--tone-on)]' => $a->earned,
        'border border-dashed border-line-strong text-ink-muted' => ! $a->earned,
    ]) aria-hidden="true">
        <flux:icon :icon="$a->icon" :variant="$a->earned ? 'solid' : 'outline'" class="size-6" />
    </span>

    <div class="min-w-0 flex-1">
        <p @class(['font-semibold leading-snug', 'text-ink' => $a->earned, 'text-ink-soft' => ! $a->earned])>
            {{ $a->name }}
            <span class="sr-only">{{ $a->earned ? __('(unlocked)') : __('(locked)') }}</span>
        </p>
        <p class="mt-0.5 text-sm leading-snug text-ink-muted">{{ $a->description }}</p>

        @if ($a->earned)
            <p class="mt-2.5 label-caps text-[var(--tone-ink)]">
                {{ $a->earnedAt ? __('Unlocked :date', ['date' => $a->earnedAt->format('j M Y')]) : __('Unlocked') }}
            </p>
        @elseif ($a->progress !== null)
            <x-ui.progress
                class="mt-3"
                :value="$progressValue"
                :max="$progressMax"
                tone="navy"
                size="sm"
                :label="__('Progress')"
                :value-label="$progressText"
            />
        @else
            <p class="mt-2.5 label-caps text-ink-muted">{{ __('Locked') }}</p>
        @endif
    </div>
</div>
