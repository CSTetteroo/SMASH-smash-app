@props([
    // Colour of the cork; the feathers use currentColor.
    'cork' => '#F03F2A',
])

{{-- Shuttlecock glyph from the SMASH mark (feathers in currentColor, red cork). Decorative. --}}
<svg xmlns="http://www.w3.org/2000/svg" viewBox="16 18 62 66" aria-hidden="true" focusable="false" {{ $attributes->class('shrink-0') }}>
    <path d="M61.1 29.5L19.8 52.2L26.4 54L27.4 62.1L36.7 63.4L37.3 71.9L46.2 72.9L48.3 80.5L69.9 37.3Z" fill="currentColor" />
    <path d="M62.6 32.9L28.9 56.6M64.6 35.3L37.6 62.3M66.9 37.8L43.4 70.4" fill="none" stroke="var(--color-canvas, #fff)" stroke-width="1.6" stroke-linecap="round" />
    <circle cx="70.5" cy="28.6" r="7.6" fill="{{ $cork }}" />
</svg>
