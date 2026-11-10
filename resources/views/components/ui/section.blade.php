@props([
    'title' => null,
    'kicker' => null,
    'intro' => null,
    // Anchor id (for in-page links / tabs).
    'id' => null,
    // white (default) · grey (BUas band) · navy
    'tone' => 'white',
    // site · narrow
    'width' => 'site',
    // Vertical rhythm: none · sm · md · lg
    'spacing' => 'md',
    // Heading level for the title (2 for page sections, 3 inside a sub-area).
    'level' => 2,
])

{{--
    Page section with a BUas section header (kicker, serif title + ornament, intro) and an actions slot on the right.
    Alternate white and grey sections down the page. Slots: $actions, default.
--}}
@php
    $toneClass = match ($tone) {
        'grey', 'muted' => 'bg-canvas-muted',
        'navy' => 'theme-navy',
        default => 'bg-canvas',
    };

    $spacingClass = match ($spacing) {
        'none' => '',
        'sm' => 'py-8 lg:py-10',
        'lg' => 'py-16 lg:py-24',
        default => 'py-12 lg:py-16',
    };

    $hasHeader = filled($title) || filled($kicker) || isset($actions);
@endphp

<section @if ($id) id="{{ $id }}" @endif {{ $attributes->class([$toneClass, $spacingClass, 'scroll-mt-6']) }}>
    <div class="{{ $width === 'narrow' ? 'container-narrow' : 'container-site' }}">
        @if ($hasHeader)
            <div class="mb-8 flex flex-wrap items-end justify-between gap-x-8 gap-y-5 lg:mb-10">
                <div class="min-w-0 max-w-3xl">
                    @if ($kicker)
                        <p class="kicker mb-2.5">{{ $kicker }}</p>
                    @endif
                    @if ($title)
                        <x-ui.heading :level="$level" :size="$level >= 3 ? 'h3' : 'h2'" :ornament="true">{{ $title }}</x-ui.heading>
                    @endif
                    @if ($intro)
                        <p class="mt-5 max-w-2xl text-[17px] leading-relaxed text-ink-soft">{{ $intro }}</p>
                    @endif
                </div>

                @isset($actions)
                    <div class="flex flex-wrap items-center gap-3">{{ $actions }}</div>
                @endisset
            </div>
        @endif

        {{ $slot }}
    </div>
</section>
