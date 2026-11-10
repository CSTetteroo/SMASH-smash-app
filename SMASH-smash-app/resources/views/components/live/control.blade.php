@props([
    /*
     | x-live.control — a console button for the navy scoreboard (x-ui.button's outline and ghost
     | variants are navy-on-white). Same BUas language: square, uppercase, letter-spaced, 48px.
     |
     | icon      Heroicon name
     | shortcut  Keyboard hint shown on large screens ("U")
     | variant   outline (white hairline) · solid (orange, the one thing to do now)
     | size      md (48px) · sm (40px)
     */
    'icon' => null,
    'shortcut' => null,
    'variant' => 'outline',
    'size' => 'md',
])

<button
    type="button"
    {{ $attributes->class([
        'inline-flex select-none items-center justify-center gap-2.5 rounded-none px-4 font-semibold uppercase leading-tight tracking-caps',
        'transition-[background-color,border-color,color,opacity] duration-150 ease-brand',
        'disabled:cursor-not-allowed disabled:opacity-40',
        'min-h-12 text-[13px]' => $size === 'md',
        'min-h-10 text-[11px]' => $size === 'sm',
        'border border-white/40 text-white enabled:hover:border-white enabled:hover:bg-white/10' => $variant === 'outline',
        'bg-orange-400 text-navy-950 enabled:hover:bg-white' => $variant === 'solid',
    ]) }}
>
    @if ($icon)
        <flux:icon :icon="$icon" variant="micro" class="size-4 shrink-0" />
    @endif
    <span>{{ $slot }}</span>
    @if ($shortcut)
        <kbd @class([
            'ms-1 inline-flex h-5 min-w-5 items-center justify-center border px-1 font-sans text-[10px] tracking-normal max-lg:hidden',
            'border-white/30 text-white/70' => $variant === 'outline',
            'border-navy-950/30 text-navy-950/70' => $variant === 'solid',
        ])>{{ $shortcut }}</kbd>
    @endif
</button>
