{{--
    Site footer — BUas 2026 footer: navy, orange uppercase kickers, white link columns, orange pill CTA,
    circular outlined social links. Contact links only render when configured in config/smash.php.
--}}
@php
    $club = [
        'name' => config('smash.club.name') ?? 'SMASH',
        'venue' => config('smash.club.venue') ?? 'BUas Sports Hall',
        'email' => config('smash.club.email'),
        'instagram' => config('smash.club.instagram_url'),
        'whatsapp' => config('smash.club.whatsapp_url'),
        'questionnaire' => config('smash.club.questionnaire_url'),
    ];

    $groups = [
        __('Compete') => [
            [__('Rankings'), 'rankings.index'],
            [__('Head-to-head'), 'head-to-head'],
            [__('Matches & results'), 'matches.index'],
            [__('Tournaments'), 'tournaments.index'],
            [__('Who wins? Predictor'), 'predictor'],
            [__("Pick'em"), 'predictions.index'],
        ],
        __('Club') => [
            [__('Club nights'), 'club-nights.index'],
            [__('Players'), 'players.index'],
            [__('How ratings work'), 'how-it-works'],
            [__('Search'), 'search'],
        ],
        __('Members') => auth()->check()
            ? [
                [__('Dashboard'), 'dashboard'],
                [__('Log a match'), 'matches.create'],
                [__('Player profile'), 'player-profile.edit'],
                [__('Settings'), 'profile.edit'],
            ]
            : [
                [__('Join SMASH'), 'register'],
                [__('Log in'), 'login'],
                [__('Forgot password'), 'password.request'],
            ],
    ];

    $socials = array_filter([
        'instagram' => $club['instagram'],
        'whatsapp' => $club['whatsapp'],
        'email' => $club['email'] ? 'mailto:'.$club['email'] : null,
    ]);
@endphp

<footer class="theme-navy mt-auto print:hidden" data-site-footer>
    <div class="container-site grid gap-x-20 gap-y-12 py-12 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,2.2fr)_minmax(0,1fr)] lg:py-14">
        {{-- Promo --}}
        <div class="flex max-w-md flex-col items-start gap-4">
            <p class="kicker">{{ __('The club') }}</p>
            <div class="relative aspect-[308/180] w-full overflow-hidden bg-navy-900 dark:bg-[#001a2c]">
                <x-ui.court-lines class="absolute inset-0 m-auto h-[78%] w-auto text-white/25" />
                <x-app-logo-icon class="absolute inset-0 m-auto h-16 w-auto drop-shadow-[0_6px_12px_rgb(0_0_0/0.35)]" />
            </div>
            <p class="font-serif text-[26px] leading-tight text-white">{{ __('Smash it forward.') }}</p>
            <p class="text-[17px] leading-relaxed text-ink-soft">
                {{ __('SMASH brings BUas students and staff together on court: weekly club nights, ranked singles and doubles, and tournaments for every level.') }}
            </p>
            <a href="{{ route('how-it-works') }}" wire:navigate class="group/promo inline-flex items-center gap-2 border-b border-white/40 pb-1.5 text-sm font-medium text-white">
                <span class="group-hover/promo:underline">{{ __('How ratings and predictions work') }}</span>
                <flux:icon.arrow-right variant="micro" class="size-4 transition-transform duration-200 ease-brand group-hover/promo:translate-x-0.5" />
            </a>
        </div>

        {{-- Link groups --}}
        <div class="grid grid-cols-2 gap-x-10 gap-y-10 sm:grid-cols-3">
            @foreach ($groups as $title => $links)
                <div class="flex flex-col gap-4">
                    <p class="kicker">{{ $title }}</p>
                    <ul class="flex flex-col gap-2 text-sm" role="list">
                        @foreach ($links as [$label, $routeName])
                            <li>
                                <a href="{{ route($routeName) }}" wire:navigate class="text-white underline-offset-[3px] hover:underline">{{ $label }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        {{-- Aside --}}
        <div class="flex flex-col items-start gap-10">
            <div class="flex flex-col items-start gap-4">
                @auth
                    <p class="kicker">{{ __('Your SMASH') }}</p>
                    <x-ui.button variant="pill-orange" :href="route('dashboard')" icon-trailing="arrow-right" size="lg">{{ __('Your dashboard') }}</x-ui.button>
                    <p class="max-w-64 text-sm text-ink-soft">{{ __('Your next club night, your rating and the matches you can still predict.') }}</p>
                @else
                    <p class="kicker">{{ __('Play with us') }}</p>
                    <x-ui.button variant="pill-orange" :href="route('register')" icon-trailing="arrow-right" size="lg">{{ __('Join the club') }}</x-ui.button>
                    <p class="max-w-64 text-sm text-ink-soft">{{ __('Open to BUas students and staff, from first-timers to competitive players.') }}</p>
                @endauth
            </div>

            <div class="flex flex-col items-start gap-3 text-sm">
                <p class="kicker">{{ __('Find us') }}</p>
                <p class="text-white">{{ $club['venue'] }}<br>{{ __('Breda University of Applied Sciences') }}</p>
                @if ($club['email'])
                    <p><span class="text-ink-muted">E</span>&nbsp; <a href="mailto:{{ $club['email'] }}" class="text-white underline-offset-[3px] hover:underline">{{ $club['email'] }}</a></p>
                @endif
                @if ($club['questionnaire'])
                    <a href="{{ $club['questionnaire'] }}" target="_blank" rel="noopener" class="text-white underline underline-offset-[3px] hover:text-orange-400">
                        {{ __('Tell us what you want from the club') }}
                    </a>
                @endif
            </div>
        </div>
    </div>

    <div class="container-site">
        <div class="flex flex-wrap items-center justify-between gap-6 border-t border-line py-8">
            <div class="flex flex-wrap items-center gap-x-8 gap-y-4">
                <x-app-logo :href="route('home')" variant="inverse" size="sm" />
                <p class="max-w-xl text-[13px] leading-relaxed text-ink-muted">
                    {{ __(':name is a student sports club at Breda University of Applied Sciences.', ['name' => $club['name']]) }}
                    <span class="whitespace-nowrap">&copy; {{ now()->year }} {{ $club['name'] }}</span>
                </p>
            </div>

            @if ($socials !== [])
                <ul class="flex items-center gap-1.5" role="list">
                    @foreach ($socials as $network => $url)
                        <li>
                            <a
                                href="{{ $url }}"
                                @if ($network !== 'email') target="_blank" rel="noopener" @endif
                                class="inline-flex size-12 items-center justify-center rounded-full border border-white text-white transition-colors hover:bg-white hover:text-navy-800"
                                aria-label="{{ match ($network) { 'instagram' => __('SMASH on Instagram'), 'whatsapp' => __('SMASH WhatsApp community'), default => __('Email SMASH') } }}"
                            >
                                @if ($network === 'instagram')
                                    <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5" /><circle cx="12" cy="12" r="4.2" /><circle cx="17.4" cy="6.6" r="1.1" fill="currentColor" stroke="none" /></svg>
                                @elseif ($network === 'whatsapp')
                                    <svg viewBox="0 0 24 24" class="size-5" fill="currentColor" aria-hidden="true"><path d="M12 2.2a9.7 9.7 0 0 0-8.35 14.66L2.3 21.7l4.97-1.3A9.7 9.7 0 1 0 12 2.2Zm0 17.68a7.97 7.97 0 0 1-4.06-1.11l-.29-.17-2.95.77.79-2.87-.19-.3A7.98 7.98 0 1 1 12 19.88Zm4.38-5.97c-.24-.12-1.42-.7-1.64-.78-.22-.08-.38-.12-.54.12-.16.24-.62.78-.76.94-.14.16-.28.18-.52.06a6.53 6.53 0 0 1-3.25-2.84c-.25-.42.24-.39.69-1.3.08-.16.04-.3-.02-.42-.06-.12-.54-1.3-.74-1.78-.2-.47-.4-.4-.54-.41h-.46a.89.89 0 0 0-.64.3 2.7 2.7 0 0 0-.84 2c0 1.18.86 2.32.98 2.48.12.16 1.69 2.58 4.1 3.62 1.52.66 2.12.72 2.88.6.46-.07 1.42-.58 1.62-1.14.2-.56.2-1.04.14-1.14-.06-.1-.22-.16-.46-.28Z" /></svg>
                                @else
                                    <flux:icon.envelope variant="outline" class="size-5" />
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</footer>
