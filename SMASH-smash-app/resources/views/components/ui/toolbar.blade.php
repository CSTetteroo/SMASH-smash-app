{{--
    Filter / action bar above a list or table. Default slot = filters (Flux inputs/selects, tabs);
    $actions slot = right-aligned buttons. Wraps on small screens.
--}}
<div {{ $attributes->class('flex flex-wrap items-end gap-3') }}>
    {{ $slot }}
    @isset($actions)
        <div class="ms-auto flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
