@props([
    'number',
    'title',
    // Heading level of the title.
    'level' => 3,
])

{{-- Numbered explainer step (How it works, onboarding). --}}
<div {{ $attributes->class('flex gap-5') }}>
    <span class="num w-12 shrink-0 text-[44px] font-extrabold leading-[0.85] text-navy-200 dark:text-navy-700" aria-hidden="true">{{ str_pad((string) $number, 2, '0', STR_PAD_LEFT) }}</span>
    <div class="min-w-0 border-t-2 border-navy-800 pt-3 dark:border-navy-400">
        <x-ui.heading :level="$level" size="h4" :ornament="false">{{ $title }}</x-ui.heading>
        <div class="mt-2 text-[15px] leading-relaxed text-ink-soft">{{ $slot }}</div>
    </div>
</div>
