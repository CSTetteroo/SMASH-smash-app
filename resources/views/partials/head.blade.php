@php
    $smashName = config('smash.club.name') ?? 'SMASH';
    $smashFullName = config('smash.club.full_name') ?? 'SMASH — BUas Badminton Club';
    $smashTitle = filled($title ?? null) ? $title.' · '.$smashName : $smashFullName;
    $smashDescription = filled($description ?? null)
        ? $description
        : __('SMASH is the badminton club of Breda University of Applied Sciences: weekly club nights, singles and doubles ELO rankings, tournaments and match predictions.');
@endphp
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $smashTitle }}</title>
<meta name="description" content="{{ $smashDescription }}" />
<meta name="theme-color" content="#00406B" />

<link rel="icon" href="/favicon.ico" sizes="32x32" />
<link rel="icon" href="/favicon.svg" type="image/svg+xml" />
<link rel="apple-touch-icon" href="/apple-touch-icon.png" />

<meta property="og:type" content="website" />
<meta property="og:site_name" content="{{ $smashFullName }}" />
<meta property="og:title" content="{{ $smashTitle }}" />
<meta property="og:description" content="{{ $smashDescription }}" />
<meta property="og:url" content="{{ url()->current() }}" />
<meta property="og:image" content="{{ asset('images/smash-logo.png') }}" />
<meta property="og:image:alt" content="{{ $smashFullName }}" />
<meta name="twitter:card" content="summary" />

{{--
    Light mode is the brand default. A first-time visitor gets "light" once; after that Flux owns
    the setting (choosing "System" removes flux.appearance, so we must not re-force light).
--}}
<script>
    try {
        if (! localStorage.getItem('smash.appearance-initialised')) {
            localStorage.setItem('smash.appearance-initialised', '1');

            if (! localStorage.getItem('flux.appearance')) {
                localStorage.setItem('flux.appearance', 'light');
            }
        }
    } catch (e) {}
</script>

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
