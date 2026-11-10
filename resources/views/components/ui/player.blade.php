@props([
    // App\Models\User — name, link and programme are taken from it.
    'user' => null,
    'name' => null,
    // Link target. Defaults to players.show for a $user; pass false for no link.
    'href' => null,
    // Line under the name. Defaults to the user's programme; pass false to hide.
    'meta' => null,
    // xs · sm · md · lg
    'size' => 'md',
    'avatar' => true,
])

{{--
    Avatar + name. With a User (and no custom name) this renders x-smash.player, the canonical
    player component, so both look identical. The markup below is for plain names (guests,
    sample rows) and mirrors x-smash.player's default variant.
--}}
@if ($user instanceof \App\Models\User && $name === null)
    <x-smash.player :user="$user" :href="$href" :meta="$meta" :size="$size" :avatar="$avatar" {{ $attributes }}>{{ $slot }}</x-smash.player>
@else
    @php
        $name ??= $user?->name ?? '';

        if ($href === null && $user !== null && Route::has('players.show')) {
            $href = route('players.show', $user);
        }

        if ($meta === null && $user !== null) {
            $programme = $user->programme ?? null;
            $meta = $programme instanceof \BackedEnum && method_exists($programme, 'label') ? $programme->label() : null;
        }

        $avatarSize = match ($size) {
            'xs' => 'xs',
            'sm' => 'sm',
            'lg' => 'lg',
            default => 'md',
        };

        $nameClass = match ($size) {
            'xs' => 'text-[13px]',
            'sm' => 'text-sm',
            'lg' => 'text-lg',
            default => 'text-[15px]',
        };
    @endphp

    <span {{ $attributes->class(['flex min-w-0 items-center', $size === 'xs' ? 'gap-2' : 'gap-3']) }}>
        @if ($avatar)
            <x-ui.avatar :name="$name" :size="$avatarSize" />
        @endif
        <span class="min-w-0 leading-tight">
            @if ($href)
                <a href="{{ $href }}" wire:navigate class="block truncate font-semibold text-ink underline-offset-[3px] decoration-1 hover:text-link hover:underline {{ $nameClass }}">{{ $name }}</a>
            @else
                <span class="block truncate font-semibold text-ink {{ $nameClass }}">{{ $name }}</span>
            @endif
            @if ($meta)
                <span class="mt-1 block truncate text-xs leading-4 text-ink-muted">{{ $meta }}</span>
            @endif
        </span>
        {{ $slot }}
    </span>
@endif
