@props([
    // Trail after "Home". Each item: ['label' => 'Rankings', 'href' => route(...)] · ['Rankings', route(...)] · 'Current page'
    // The last item is the current page (its href is ignored).
    'items' => [],
    // Prepend the home crumb (house icon).
    'home' => true,
])

@php
    $crumbs = collect($items)
        ->map(function ($item): array {
            if (is_string($item)) {
                return ['label' => $item, 'href' => null];
            }

            return [
                'label' => (string) ($item['label'] ?? $item[0] ?? ''),
                'href' => $item['href'] ?? $item['url'] ?? $item[1] ?? null,
            ];
        })
        ->values();

    if ($home) {
        $crumbs->prepend(['label' => __('Home'), 'href' => route('home'), 'home' => true]);
    }

    $parent = $crumbs->count() > 1 ? $crumbs[$crumbs->count() - 2] : null;
@endphp

@if ($crumbs->isNotEmpty())
    <nav aria-label="{{ __('Breadcrumb') }}" {{ $attributes->class('text-sm leading-6 text-navy-800 dark:text-navy-100') }}>
        {{-- Desktop: full trail --}}
        <ol class="flex flex-wrap items-center gap-x-3 gap-y-1 max-md:hidden" role="list">
            @foreach ($crumbs as $crumb)
                <li class="inline-flex items-center gap-3">
                    @unless ($loop->first)
                        <flux:icon.chevron-right variant="micro" class="size-3.5 opacity-60" />
                    @endunless

                    @if ($loop->last)
                        <span aria-current="page" class="text-ink-soft">{{ $crumb['label'] }}</span>
                    @elseif ($crumb['href'])
                        <a href="{{ $crumb['href'] }}" wire:navigate class="inline-flex items-center underline-offset-[3px] hover:underline">
                            @if ($crumb['home'] ?? false)
                                <flux:icon.home variant="mini" class="size-[18px]" />
                                <span class="sr-only">{{ $crumb['label'] }}</span>
                            @else
                                {{ $crumb['label'] }}
                            @endif
                        </a>
                    @else
                        <span>{{ $crumb['label'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>

        {{-- Mobile: back to the parent, BUas style --}}
        @if ($parent && $parent['href'])
            <a href="{{ $parent['href'] }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 text-[17px] md:hidden">
                <flux:icon.chevron-left variant="mini" class="-ms-1 size-5" />
                {{ $parent['label'] }}
            </a>
        @endif
    </nav>
@endif
