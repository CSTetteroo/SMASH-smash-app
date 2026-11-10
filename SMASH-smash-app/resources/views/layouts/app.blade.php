{{--
    SMASH site layout — default layout for every Livewire page (config livewire.component_layout = layouts::app).
    Pages render full-bleed content (x-ui.page-hero + x-ui.section bands). Pages that are not built with
    those primitives can ask for a padded container: #[Layout('layouts::app', ['contained' => true])].
--}}
@php
    // The starter-kit account pages render bare content; give them the container automatically.
    $contained ??= request()->routeIs('profile.edit', 'security.edit', 'appearance.edit');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="flex min-h-screen flex-col bg-canvas text-ink-soft antialiased">
        <a
            href="#main"
            class="sr-only z-50 bg-navy-800 px-4 py-3 text-sm font-semibold text-white focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
        >{{ __('Skip to main content') }}</a>

        <x-site.header />

        <main id="main" tabindex="-1" class="flex-1 focus:outline-none">
            @if ($contained)
                <div class="container-site py-10 lg:py-14">
                    {{ $slot }}
                </div>
            @else
                {{ $slot }}
            @endif
        </main>

        <x-site.footer />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
