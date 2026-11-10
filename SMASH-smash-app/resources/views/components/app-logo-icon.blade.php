@props([
    // "simple" drops the feather separators and thickens the border — use it at 16–24px.
    'simple' => false,
    // Accessible name. Leave null when the mark sits next to visible text (it is then decorative).
    'title' => null,
])

{{--
    SMASH mark: black badge with a SMASH-red border, white shuttlecock flying up-right, red cork.
    Geometry traced from public/images/smash-logo.png (badge = 100 × 104.6 units).
--}}
<svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="-2.5 -2.5 105 110"
    {{ $attributes->merge(['class' => 'shrink-0']) }}
    @if ($title) role="img" aria-label="{{ $title }}" @else aria-hidden="true" @endif
    focusable="false"
    data-smash-mark
>
    <path
        d="M15 0H85L100 15.7V68.4L50 104.6L0 68.4V15.7Z"
        fill="#121212"
        stroke="#F03F2A"
        stroke-width="{{ $simple ? 8 : 3.6 }}"
        stroke-linejoin="miter"
    />
    <path
        d="M61.1 29.5L19.8 52.2L26.4 54L27.4 62.1L36.7 63.4L37.3 71.9L46.2 72.9L48.3 80.5L69.9 37.3Z"
        fill="#FFFFFF"
    />
    @unless ($simple)
        <path
            d="M62.6 32.9L28.9 56.6M64.6 35.3L37.6 62.3M66.9 37.8L43.4 70.4"
            fill="none"
            stroke="#121212"
            stroke-width="1.5"
            stroke-linecap="round"
        />
    @endunless
    <circle cx="70.5" cy="28.6" r="{{ $simple ? 9 : 7.6 }}" fill="#F03F2A" />
</svg>
