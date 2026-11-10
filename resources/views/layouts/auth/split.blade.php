{{--
    Auth layout: navy SMASH panel (logo, court lines, slogan, pitch) on the left, white form panel on the right.
    Below lg the brand panel collapses into the BUas-style white top bar.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas text-ink-soft antialiased">
        <div class="grid min-h-dvh lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            {{-- Brand panel --}}
            <aside class="theme-navy relative isolate hidden flex-col overflow-hidden border-t-[10px] border-navy-950 lg:flex">
                <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
                    <x-ui.court-lines orientation="vertical" :stroke="1.5" class="absolute top-1/2 -right-[17%] h-[92%] w-auto -translate-y-1/2 rotate-[-8deg] text-white/12" />
                </div>

                <div class="flex flex-1 flex-col px-[clamp(2rem,4vw,4.5rem)] py-12">
                    <x-app-logo :href="route('home')" variant="inverse" size="lg" />

                    <div class="mt-auto max-w-xl pt-16">
                        <p class="kicker">{{ __('BUas Badminton Club') }}</p>
                        <p class="mt-4 font-display text-[clamp(3.5rem,2rem+4vw,6rem)] font-extrabold italic uppercase leading-[0.86] tracking-[-0.005em] text-white">
                            {{ __('Smash it') }}<br>{{ __('forward') }}<span class="text-smash-500">.</span>
                        </p>
                        <p class="mt-8 max-w-md text-[17px] leading-relaxed text-ink-soft">
                            {{ __('Weekly club nights in the BUas Sports Hall, singles and doubles ratings that move with every match, and tournaments for every level.') }}
                        </p>

                        <dl class="mt-10 grid max-w-md grid-cols-3 gap-6 border-t border-line pt-6">
                            <div>
                                <dt class="label-caps text-ink-muted">{{ __('Courts') }}</dt>
                                <dd class="num mt-1 text-3xl font-extrabold text-white">{{ config('smash.club.courts') ?? 4 }}</dd>
                            </div>
                            <div>
                                <dt class="label-caps text-ink-muted">{{ __('Disciplines') }}</dt>
                                <dd class="num mt-1 text-3xl font-extrabold text-white">2</dd>
                            </div>
                            <div>
                                <dt class="label-caps text-ink-muted">{{ __('Start rating') }}</dt>
                                <dd class="num mt-1 text-3xl font-extrabold text-white">{{ config('smash.rating.initial') ?? 1500 }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <p class="border-t border-line px-[clamp(2rem,4vw,4.5rem)] py-5 text-[13px] text-ink-muted">
                    {{ __(':name is a student sports club at Breda University of Applied Sciences.', ['name' => config('smash.club.name') ?? 'SMASH']) }}
                </p>
            </aside>

            {{-- Form panel --}}
            <main id="main" class="flex min-w-0 flex-col max-lg:border-t-[10px] max-lg:border-navy-800">
                <div class="flex items-center justify-between gap-4 px-[var(--spacing-gutter)] pt-4 pb-3 lg:px-12 lg:pt-8">
                    <x-app-logo :href="route('home')" size="sm" class="lg:hidden" />
                    <x-ui.link :href="route('home')" variant="back" class="ms-auto">{{ __('Back to SMASH') }}</x-ui.link>
                </div>

                <div class="flex flex-1 items-start justify-center px-[var(--spacing-gutter)] pt-6 pb-16 sm:items-center lg:px-12">
                    <div class="w-full max-w-[26rem]">
                        {{ $slot }}
                    </div>
                </div>

                <p class="px-[var(--spacing-gutter)] pb-6 text-center text-xs text-ink-muted lg:hidden">
                    {{ __(':name is a student sports club at Breda University of Applied Sciences.', ['name' => config('smash.club.name') ?? 'SMASH']) }}
                </p>
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
