{{--
    Site header — BUas 2026 header (white bar, 10px navy top border, Search / Menu pills, thin divider)
    with a SMASH primary navigation row. The mega panel lives in x-site.menu-panel.
--}}
@php
    $primary = [
        ['label' => __('Rankings'), 'route' => 'rankings.index', 'active' => ['rankings.*', 'head-to-head']],
        ['label' => __('Matches'), 'route' => 'matches.index', 'active' => ['matches.*']],
        ['label' => __('Tournaments'), 'route' => 'tournaments.index', 'active' => ['tournaments.*']],
        ['label' => __('Players'), 'route' => 'players.index', 'active' => ['players.*']],
        ['label' => __('Club nights'), 'route' => 'club-nights.index', 'active' => ['club-nights.*']],
        ['label' => __('Predictor'), 'route' => 'predictor', 'active' => ['predictor']],
    ];

    $utility = [
        ['label' => __('How it works'), 'route' => 'how-it-works', 'active' => ['how-it-works']],
        ['label' => __("Pick'em"), 'route' => 'predictions.index', 'active' => ['predictions.*']],
    ];
@endphp

<header
    x-data="{ open: false }"
    x-on:keydown.escape.window="open = false"
    x-bind:class="open && 'is-open'"
    class="relative z-40 border-t-[10px] border-navy-800 bg-surface print:hidden dark:border-navy-700"
    data-site-header
>
    {{-- Scrim behind the open panel. --}}
    <div
        x-cloak
        x-show="open"
        x-transition.opacity.duration.150ms
        x-on:click="open = false"
        class="fixed inset-0 z-10 bg-[rgb(15_23_32/0.45)]"
        aria-hidden="true"
    ></div>

    <div class="relative z-20 bg-surface">
        <div class="container-site">
            <div class="flex items-center justify-between gap-4 pt-4 pb-3 lg:gap-8 lg:pt-6 lg:pb-3">
                <x-app-logo :href="route('home')" size="sm" class="lg:hidden" />
                <x-app-logo :href="route('home')" size="md" class="max-lg:hidden" />

                <div class="flex flex-col items-end gap-3">
                    {{-- Utility links (desktop) --}}
                    <nav aria-label="{{ __('Utility') }}" class="flex items-center gap-5 text-sm max-lg:hidden">
                        @foreach ($utility as $link)
                            @php $current = request()->routeIs(...$link['active']); @endphp
                            <a
                                href="{{ route($link['route']) }}"
                                wire:navigate
                                @if ($current) aria-current="page" @endif
                                @class([
                                    'text-navy-800 underline-offset-[3px] hover:underline dark:text-navy-100',
                                    'font-semibold' => $current,
                                ])
                            >{{ $link['label'] }}</a>
                        @endforeach

                        @guest
                            <span class="h-4 w-px bg-line" aria-hidden="true"></span>
                            <a href="{{ route('login') }}" wire:navigate class="inline-flex items-center gap-1.5 font-medium text-navy-800 underline-offset-[3px] hover:underline dark:text-navy-100">
                                <flux:icon.user-circle variant="micro" class="size-4" />
                                {{ __('Log in') }}
                            </a>
                        @endguest
                    </nav>

                    <div class="flex items-center gap-2 lg:gap-3">
                        {{-- Search: icon button on mobile, light-blue pill on desktop --}}
                        <a
                            href="{{ route('search') }}"
                            wire:navigate
                            @class([
                                'inline-flex size-11 items-center justify-center gap-2.5 rounded-full bg-navy-100 text-navy-800 transition-colors hover:bg-[color-mix(in_srgb,var(--color-navy-100),var(--color-navy-800)_10%)]',
                                'lg:h-12 lg:w-auto lg:px-6 lg:text-[17px] lg:font-medium',
                                'dark:bg-navy-900 dark:text-navy-100 dark:hover:bg-navy-800',
                                'ring-2 ring-navy-800 ring-offset-2 ring-offset-surface' => request()->routeIs('search'),
                            ])
                        >
                            <span class="max-lg:sr-only">{{ __('Search') }}</span>
                            <flux:icon.magnifying-glass variant="mini" class="size-5" />
                        </a>

                        {{-- Menu pill --}}
                        <button
                            type="button"
                            x-on:click="open = ! open"
                            x-bind:aria-expanded="open.toString()"
                            aria-expanded="false"
                            aria-controls="site-menu"
                            class="inline-flex h-11 items-center gap-2.5 rounded-full bg-navy-800 px-4 text-[15px] font-medium text-white transition-colors hover:bg-[color-mix(in_srgb,var(--color-navy-800),black_12%)] lg:h-12 lg:px-6 lg:text-[17px] dark:bg-navy-600 dark:hover:bg-navy-700"
                            data-test="menu-button"
                        >
                            <span x-text="open ? @js(__('Close')) : @js(__('Menu'))">{{ __('Menu') }}</span>
                            <flux:icon.bars-3 x-show="! open" variant="mini" class="size-5" />
                            <flux:icon.x-mark x-cloak x-show="open" variant="mini" class="size-5" />
                        </button>

                        {{-- Account --}}
                        <div class="max-md:hidden">
                            @auth
                                <x-site.user-menu />
                            @else
                                <x-ui.button variant="pill-green" :href="route('register')" icon-trailing="arrow-right" class="lg:h-12 lg:px-6 lg:text-[17px]">
                                    {{ __('Join SMASH') }}
                                </x-ui.button>
                            @endauth
                        </div>
                    </div>
                </div>
            </div>

            {{-- Primary navigation. Desktop: row on the divider. Mobile: swipeable strip. --}}
            <nav
                aria-label="{{ __('Primary') }}"
                class="relative -mx-[var(--spacing-gutter)] overflow-x-auto scrollbar-none border-b border-line px-[var(--spacing-gutter)] lg:mx-0 lg:overflow-visible lg:px-0"
            >
                <ul class="flex items-center gap-x-6 lg:gap-x-9" role="list">
                    @foreach ($primary as $link)
                        <li class="shrink-0">
                            <x-site.nav-link :href="route($link['route'])" :current="request()->routeIs(...$link['active'])">
                                {{ $link['label'] }}
                            </x-site.nav-link>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </div>
    </div>

    <x-site.menu-panel />
</header>
