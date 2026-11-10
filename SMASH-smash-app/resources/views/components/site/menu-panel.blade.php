{{--
    BUas-style mega menu. Rendered inside the header's Alpine scope (x-data="{ open }").
    Desktop: full-width white panel with link columns. Mobile: stacked groups with full-width rows.
--}}
@php
    $isRoute = fn (array $patterns): bool => request()->routeIs(...$patterns);

    $groups = [
        [
            'title' => __('Compete'),
            'links' => [
                ['label' => __('Rankings'), 'route' => 'rankings.index', 'active' => ['rankings.*']],
                ['label' => __('Head-to-head'), 'route' => 'head-to-head', 'active' => ['head-to-head']],
                ['label' => __('Matches & results'), 'route' => 'matches.index', 'active' => ['matches.index', 'matches.show']],
                ['label' => __('Tournaments'), 'route' => 'tournaments.index', 'active' => ['tournaments.index', 'tournaments.show']],
                ['label' => __('Who wins? Predictor'), 'route' => 'predictor', 'active' => ['predictor']],
                ['label' => __("Pick'em leaderboard"), 'route' => 'predictions.index', 'active' => ['predictions.*']],
            ],
        ],
        [
            'title' => __('Club'),
            'links' => [
                ['label' => __('Club nights'), 'route' => 'club-nights.index', 'active' => ['club-nights.index', 'club-nights.show']],
                ['label' => __('Players'), 'route' => 'players.index', 'active' => ['players.index', 'players.show']],
                ['label' => __('How ratings work'), 'route' => 'how-it-works', 'active' => ['how-it-works']],
                ['label' => __('Search'), 'route' => 'search', 'active' => ['search']],
            ],
        ],
    ];

    if (auth()->check()) {
        $groups[] = [
            'title' => __('Your account'),
            'links' => [
                ['label' => __('Dashboard'), 'route' => 'dashboard', 'active' => ['dashboard']],
                ['label' => __('Log a match'), 'route' => 'matches.create', 'active' => ['matches.create']],
                ['label' => __('Player profile'), 'route' => 'player-profile.edit', 'active' => ['player-profile.edit']],
                ['label' => __('Settings'), 'route' => 'profile.edit', 'active' => ['profile.edit', 'security.edit', 'appearance.edit']],
            ],
        ];
    } else {
        $groups[] = [
            'title' => __('Your account'),
            'links' => [
                ['label' => __('Join SMASH'), 'route' => 'register', 'active' => ['register']],
                ['label' => __('Log in'), 'route' => 'login', 'active' => ['login']],
            ],
        ];
    }

    if (auth()->user()?->can('manage')) {
        $groups[] = [
            'title' => __('Manage'),
            'links' => [
                ['label' => __('Admin overview'), 'route' => 'admin.index', 'active' => ['admin.*']],
                ['label' => __('Rating adjustments'), 'route' => 'rating-adjustments.index', 'active' => ['rating-adjustments.*']],
                ['label' => __('New match'), 'route' => 'matches.create', 'active' => []],
                ['label' => __('New tournament'), 'route' => 'tournaments.create', 'active' => ['tournaments.create']],
                ['label' => __('New club night'), 'route' => 'club-nights.create', 'active' => ['club-nights.create']],
                ['label' => __('New player'), 'route' => 'players.create', 'active' => ['players.create']],
            ],
        ];
    }
@endphp

<nav
    id="site-menu"
    x-cloak
    x-show="open"
    x-trap="open"
    x-transition:enter="transition duration-200 ease-brand"
    x-transition:enter-start="-translate-y-2 opacity-0"
    x-transition:enter-end="translate-y-0 opacity-100"
    x-transition:leave="transition duration-100 ease-in"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    aria-label="{{ __('Main menu') }}"
    class="absolute inset-x-0 top-full z-20 -mt-px max-h-[calc(100dvh-100%)] overflow-y-auto overscroll-contain border-t border-line bg-surface shadow-header"
>
    <div class="container-site py-6 lg:py-12">
        <div class="lg:flex lg:items-start lg:gap-12">
            <div class="grid flex-1 gap-x-10 gap-y-8 max-lg:gap-y-0 lg:grid-cols-[repeat(auto-fit,minmax(12rem,1fr))]">
            @foreach ($groups as $group)
                <div class="min-w-0 max-lg:border-b max-lg:border-line max-lg:py-5">
                    <p class="text-[15px] font-bold uppercase tracking-[0.04em] text-navy-800 lg:text-[17px] dark:text-white">{{ $group['title'] }}</p>
                    <ul class="mt-2 lg:mt-4" role="list">
                        @foreach ($group['links'] as $link)
                            @php $current = $isRoute($link['active']); @endphp
                            <li>
                                <a
                                    href="{{ route($link['route']) }}"
                                    wire:navigate
                                    @if ($current) aria-current="page" @endif
                                    @class([
                                        'group/menu flex min-h-11 items-center gap-2 py-1.5 text-[17px] leading-snug transition-colors lg:min-h-0 lg:text-lg',
                                        'font-bold text-navy-800 dark:text-white' => $current,
                                        'text-link hover:text-link-hover' => ! $current,
                                    ])
                                >
                                    <span class="underline-offset-[5px] group-hover/menu:underline">{{ $link['label'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
            </div>

            {{-- Promo tile, BUas footer-promo style. --}}
            <div class="theme-navy relative isolate shrink-0 overflow-hidden p-6 max-lg:mt-6 lg:w-[22rem] lg:p-7">
                <x-ui.court-lines class="absolute -right-10 -bottom-16 -z-10 h-64 w-auto text-white/12" />
                <p class="kicker">{{ __('Smash it forward.') }}</p>
                @auth
                    <p class="mt-3 font-serif text-2xl leading-tight text-white">{{ __('Played a friendly tonight?') }}</p>
                    <p class="mt-2 text-[15px] text-ink-soft">{{ __('Log the score and watch your rating move.') }}</p>
                    <x-ui.button variant="pill-orange" size="sm" :href="route('matches.create')" class="mt-5" icon-trailing="arrow-right">{{ __('Log a match') }}</x-ui.button>
                @else
                    <p class="mt-3 font-serif text-2xl leading-tight text-white">{{ __('New to SMASH?') }}</p>
                    <p class="mt-2 text-[15px] text-ink-soft">{{ __('Every level is welcome. Come to a club night, play a few friendlies and earn your first rating.') }}</p>
                    <x-ui.button variant="pill-orange" size="sm" :href="route('register')" class="mt-5" icon-trailing="arrow-right">{{ __('Join the club') }}</x-ui.button>
                @endauth
            </div>
        </div>

        @auth
            <form method="POST" action="{{ route('logout') }}" class="mt-6 lg:hidden">
                @csrf
                <button type="submit" class="inline-flex min-h-11 items-center gap-2 text-[17px] text-link hover:text-link-hover" data-test="logout-button-mobile">
                    <flux:icon.arrow-right-start-on-rectangle variant="mini" class="size-5" />
                    {{ __('Log out') }}
                </button>
            </form>
        @endauth
    </div>
</nav>
