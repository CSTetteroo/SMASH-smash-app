@props([
    'title',
    'kicker' => null,
    'intro' => null,
    // Breadcrumb trail after "Home" (see x-ui.breadcrumbs). null = no breadcrumb row.
    'breadcrumbs' => null,
    // grey (BUas #EEEFF0 band, default) · white · navy
    'tone' => 'grey',
    // Badminton court lines in the band.
    'court' => false,
    // md (page title) · lg (display title, landing moments)
    'size' => 'md',
])

{{--
    Page hero, BUas style: breadcrumbs on white, then a band with kicker / serif title + ornament / intro / actions.
    Slots:
      $actions — buttons under the intro
      $meta    — tags or meta lines between intro and actions
      $aside   — white card that overlaps the bottom edge of the band (like the BUas event card)
      default  — anything else under the intro (e.g. a stat row)
--}}
@php
    $hasAside = isset($aside) && trim((string) $aside) !== '';

    $band = match ($tone) {
        'navy' => 'theme-navy',
        'white' => 'bg-canvas border-b border-line',
        default => 'bg-canvas-muted',
    };

    $courtColour = match ($tone) {
        'navy' => 'text-white/10',
        'white' => 'text-navy-800/8 dark:text-white/6',
        default => 'text-white dark:text-white/5',
    };
@endphp

<div {{ $attributes->class('ui-page-hero') }}>
    @if ($breadcrumbs !== null)
        <div class="bg-canvas">
            <div class="container-site py-2 md:py-3">
                <x-ui.breadcrumbs :items="$breadcrumbs" />
            </div>
        </div>
    @endif

    <section @class(['relative isolate', $band])>
        {{-- The aside card would cover the court, so the court only draws without one. --}}
        @if ($court && ! $hasAside)
            <div class="pointer-events-none absolute inset-0 -z-10 overflow-hidden" aria-hidden="true">
                <x-ui.court-lines
                    orientation="vertical"
                    :stroke="2"
                    @class([
                        'absolute top-1/2 right-[clamp(1rem,7vw,8rem)] h-[124%] w-auto -translate-y-1/2 rotate-[-8deg] max-md:hidden',
                        $courtColour,
                    ])
                />
            </div>
        @endif

        <div @class([
            'container-site grid items-start gap-x-16 gap-y-8',
            'lg:grid-cols-[minmax(0,1fr)_minmax(20rem,27rem)]' => $hasAside,
            $size === 'lg' ? 'py-14 lg:py-24' : 'py-10 lg:py-16',
        ])>
            <div class="min-w-0 max-w-3xl">
                @if ($kicker)
                    <p class="kicker mb-3">{{ $kicker }}</p>
                @endif

                <h1 @class([
                    'ornament font-serif font-normal tracking-[-0.01em] text-balance text-heading',
                    $size === 'lg' ? 'text-display' : 'text-h1',
                ])>{{ $title }}</h1>

                @if ($intro)
                    <p class="mt-6 max-w-2xl text-intro text-ink-soft">{{ $intro }}</p>
                @endif

                @if (trim((string) $slot) !== '')
                    <div class="mt-6">{{ $slot }}</div>
                @endif

                @isset($meta)
                    <div class="mt-6 flex flex-wrap items-center gap-2">{{ $meta }}</div>
                @endisset

                @isset($actions)
                    <div class="mt-8 flex flex-wrap items-center gap-3">{{ $actions }}</div>
                @endisset
            </div>

            @if ($hasAside)
                <aside class="theme-default relative z-10 -mb-20 bg-surface p-6 shadow-card sm:p-8 lg:-mb-28 lg:self-end dark:border dark:border-line">
                    {{ $aside }}
                </aside>
            @endif
        </div>
    </section>

    @if ($hasAside)
        {{-- Room for the card hanging over the band edge. --}}
        <div class="h-12 lg:h-14" aria-hidden="true"></div>
    @endif
</div>
