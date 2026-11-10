@props([
    // text (red dot + LIVE) · solid (red chip)
    'variant' => 'text',
    'label' => null,
])

<span {{ $attributes->class([
    'inline-flex shrink-0 items-center gap-2 font-semibold uppercase leading-none tracking-label',
    'text-[11px] text-live' => $variant === 'text',
    'h-6 bg-smash-600 px-2 text-[11px] text-white' => $variant === 'solid',
]) }}>
    <span @class(['relative inline-block size-2 rounded-full animate-live', 'bg-smash-500' => $variant === 'text', 'bg-white' => $variant === 'solid'])></span>
    {{ $label ?? __('Live') }}
</span>
