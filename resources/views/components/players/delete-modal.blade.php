@props([
    // App\Models\User being deleted.
    'user',
    // Show the "Deactivate instead" shortcut (calls the page's toggleActive action).
    'canDeactivate' => false,
    'name' => 'delete-player',
])

{{--
    Confirmation for deleting a player. The page provides the wire actions: delete (and toggleActive
    when canDeactivate is true). Explains what DeletePlayer does and recommends deactivating instead.
--}}
<flux:modal :name="$name" class="md:w-[34rem]" {{ $attributes }}>
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Delete :name?', ['name' => $user->name]) }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('This removes :first\'s account, every friendly they played in (for everybody in those matches), their tournament entries, RSVPs and pick\'em predictions. All ratings are then recalculated, so other players\' ratings can change. This can\'t be undone.', ['first' => $user->firstName()]) }}
            </flux:text>
        </div>

        @if ($user->is_active)
            <x-ui.alert tone="info" :title="__('Usually you want to deactivate instead')">
                {{ __('Inactive players disappear from the directory, the rankings and the pickers, but their results and everybody\'s ratings stay exactly as they are.') }}
            </x-ui.alert>
        @endif

        <div class="flex flex-wrap justify-end gap-2">
            <flux:modal.close>
                <x-ui.button variant="ghost" size="sm">{{ __('Cancel') }}</x-ui.button>
            </flux:modal.close>
            @if ($user->is_active && $canDeactivate)
                <flux:modal.close>
                    <x-ui.button variant="outline" size="sm" wire:click="toggleActive">{{ __('Deactivate instead') }}</x-ui.button>
                </flux:modal.close>
            @endif
            <x-ui.button variant="danger" size="sm" wire:click="delete" data-test="confirm-delete">{{ __('Delete player') }}</x-ui.button>
        </div>
    </div>
</flux:modal>
