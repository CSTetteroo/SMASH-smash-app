@props([
    /*
     | x-live.header — a compact page hero for umpire mode (breadcrumbs, kicker, serif title with
     | the ornament, match chips). Compact on purpose: courtside on a phone the console must
     | start near the top of the screen. Renders the page h1.
     |
     | match   BadmintonMatch (tournament + entries loaded)
     */
    'match',
])

@php
    use Illuminate\Support\Str;

    $context = $match->tournament !== null
        ? collect([$match->tournament->name, $match->roundLabel()])->filter()->join(' · ')
        : __('Friendly');
@endphp

<div {{ $attributes->class('border-b border-line bg-canvas') }}>
    <div class="container-site py-2 md:py-3">
        <x-ui.breadcrumbs :items="[
            [__('Matches'), route('matches.index')],
            [Str::limit($match->matchup(), 48), route('matches.show', $match)],
            __('Umpire mode'),
        ]" />
    </div>

    <div class="container-site pb-6 pt-2 lg:pb-8 lg:pt-4">
        <div class="flex flex-wrap items-end justify-between gap-x-10 gap-y-4">
            <div class="min-w-0">
                <p class="kicker mb-2">{{ __('Umpire mode') }} · {{ $context }}</p>
                <h1 class="ornament font-serif text-h3 font-normal tracking-[-0.01em] text-balance text-heading">{{ $match->matchup() }}</h1>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <x-smash.status-badge :match="$match" size="sm" />
                <x-smash.discipline-badge :discipline="$match->discipline" />
                @if ($match->court)
                    <x-ui.tag tone="grey" size="sm">{{ __('Court :n', ['n' => $match->court]) }}</x-ui.tag>
                @endif
                @unless ($match->is_rated)
                    <x-ui.tag tone="grey" size="sm">{{ __('Unrated') }}</x-ui.tag>
                @endunless
                <x-ui.link :href="route('matches.show', $match)" variant="caps" class="ms-2">{{ __('Match page') }}</x-ui.link>
            </div>
        </div>
    </div>
</div>
