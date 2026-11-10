@props([
    /*
     | x-smash.discipline-badge — "Singles" (navy) or "Doubles" (sky) chip, built on x-ui.tag.
     |
     | discipline   App\Enums\Discipline or its value ('singles' | 'doubles').
     | size         sm (default) · md · lg
     | variant      outline (default) · soft · solid
     | icon         Show the discipline icon (user / user-group).
     */
    'discipline' => null,
    'size' => 'sm',
    'variant' => 'outline',
    'icon' => false,
])

@php
    $discipline = $discipline instanceof \App\Enums\Discipline
        ? $discipline
        : (\App\Enums\Discipline::tryFrom((string) $discipline) ?? \App\Enums\Discipline::Singles);

    $tone = $discipline === \App\Enums\Discipline::Doubles ? 'sky' : 'navy';
@endphp

<x-ui.tag :tone="$tone" :size="$size" :variant="$variant" :icon="$icon ? $discipline->icon() : null" {{ $attributes }} data-smash-discipline>{{ $discipline->label() }}</x-ui.tag>
