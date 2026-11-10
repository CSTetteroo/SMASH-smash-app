@props([
    /*
     | x-club-nights.level — the recommended level of a club night as a grey tag
     | ("All levels", "Beginners", "Intermediate+", "Advanced+", "Competitive").
     |
     | level  SkillLevel|null (null = every level welcome).
     | size   x-ui.tag size: sm · md · lg
     */
    'level' => null,
    'size' => 'md',
])

@php
    $label = match ($level) {
        \App\Enums\SkillLevel::Beginner => __('Beginners'),
        \App\Enums\SkillLevel::Intermediate => __('Intermediate+'),
        \App\Enums\SkillLevel::Advanced => __('Advanced+'),
        \App\Enums\SkillLevel::Competitive => __('Competitive'),
        default => __('All levels'),
    };
@endphp

<x-ui.tag tone="grey" :size="$size" icon="chart-bar" :title="$level?->description() ?? __('Every level is welcome.')" {{ $attributes }}>{{ $label }}</x-ui.tag>
