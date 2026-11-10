@props([
    // p · span · div
    'as' => 'p',
])

{{-- Orange 11px uppercase letter-spaced label above a heading. Uses --color-kicker (orange on light, #FF8820 on navy/dark). --}}
<{{ $as }} {{ $attributes->class('kicker') }}>{{ $slot }}</{{ $as }}>
