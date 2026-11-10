@props([
    // site (1512px, header/footer width) · narrow (960px content, forms, prose)
    'width' => 'site',
])

<div {{ $attributes->class($width === 'narrow' ? 'container-narrow' : 'container-site') }}>{{ $slot }}</div>
