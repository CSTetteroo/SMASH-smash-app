{{-- Account dropdown in the desktop header (members only). --}}
@php
    $user = auth()->user();
    $firstName = method_exists($user, 'firstName') ? $user->firstName() : (explode(' ', trim((string) $user->name))[0] ?? $user->name);
@endphp

<flux:dropdown position="bottom" align="end">
    <button
        type="button"
        class="inline-flex h-12 items-center gap-2.5 rounded-full border border-line bg-surface ps-1.5 pe-3.5 text-[15px] font-medium text-navy-800 transition-colors hover:border-navy-800 dark:text-navy-100 dark:hover:border-navy-300"
        aria-label="{{ __('Account menu for :name', ['name' => $user->name]) }}"
        data-test="user-menu-button"
    >
        <x-ui.avatar :name="$user->name" size="sm" />
        <span class="max-w-32 truncate">{{ $firstName }}</span>
        <flux:icon.chevron-down variant="micro" class="size-4 text-ink-muted" />
    </button>

    <flux:menu class="min-w-64">
        <div class="flex items-center gap-3 px-2 py-2">
            <x-ui.avatar :name="$user->name" size="md" />
            <div class="min-w-0 leading-tight">
                <p class="truncate text-sm font-semibold text-ink">{{ $user->name }}</p>
                <p class="truncate text-xs text-ink-muted">{{ $user->email }}</p>
            </div>
        </div>

        <flux:menu.separator />

        <flux:menu.item :href="route('dashboard')" icon="squares-2x2" wire:navigate>{{ __('Dashboard') }}</flux:menu.item>
        <flux:menu.item :href="route('player-profile.edit')" icon="identification" wire:navigate>{{ __('Player profile') }}</flux:menu.item>
        <flux:menu.item :href="route('profile.edit')" icon="cog-6-tooth" wire:navigate>{{ __('Settings') }}</flux:menu.item>

        @can('manage')
            <flux:menu.separator />
            <flux:menu.item :href="route('admin.index')" icon="shield-check" wire:navigate>{{ __('Admin overview') }}</flux:menu.item>
        @endcan

        <flux:menu.separator />

        <form method="POST" action="{{ route('logout') }}" class="w-full">
            @csrf
            <flux:menu.item
                as="button"
                type="submit"
                icon="arrow-right-start-on-rectangle"
                class="w-full cursor-pointer"
                data-test="logout-button"
            >
                {{ __('Log out') }}
            </flux:menu.item>
        </form>
    </flux:menu>
</flux:dropdown>
