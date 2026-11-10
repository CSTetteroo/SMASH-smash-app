@props([
    // The player being edited; null on the create page (adds the password field).
    'player' => null,
    // The admin is editing their own account: role and active status are locked.
    'isSelf' => false,
    'bioMax' => 500,
])

{{--
    Admin create/edit form for a player. Binds to the page's Livewire properties name, email, password
    (create only), role, isActive and the profile fields; submits to the page's save action.
    The default slot holds the form actions (x-ui.form-actions).
--}}
@use('App\Enums\UserRole')

<form wire:submit="save" {{ $attributes->class('w-full') }} novalidate>
    <x-ui.form-section
        :title="__('Account')"
        :description="__('The name everybody sees, and the email address they log in with. Email addresses are never shown on public pages.')"
    >
        <div class="grid gap-6 sm:grid-cols-2">
            <flux:input wire:model="name" :label="__('Full name')" autocomplete="off" required data-test="field-name" />
            <flux:input wire:model="email" type="email" :label="__('Email')" autocomplete="off" required data-test="field-email" />
        </div>

        @if ($player === null)
            <flux:input
                wire:model="password"
                type="password"
                :label="__('Password')"
                :description="__('Optional. Leave it empty and we\'ll set a random one: they can choose their own with \'Forgot your password?\' on the login page.')"
                autocomplete="new-password"
                viewable
                data-test="field-password"
            />
        @endif
    </x-ui.form-section>

    <x-ui.form-section
        :title="__('Player profile')"
        :description="__('Shown on the public profile and in the player directory. Members can change these themselves under Settings.')"
    >
        <x-players.profile-fields :bio-max="$bioMax" />
    </x-ui.form-section>

    <x-ui.form-section
        :title="__('Club role')"
        :description="__('Admins are the committee: they manage players, matches, tournaments and club nights. Ratings always come from results and can\'t be edited here.')"
    >
        @if ($isSelf)
            <x-ui.alert tone="info" :title="__('This is your own account')">
                {{ __('You can\'t remove your own admin role or deactivate yourself. Ask another committee member if that needs to change.') }}
            </x-ui.alert>
        @endif

        <flux:radio.group wire:model="role" variant="cards" :label="__('Role')" class="flex-col sm:flex-row" data-test="field-role">
            <flux:radio
                :value="UserRole::Member->value"
                :label="__('Member')"
                :description="__('Plays, RSVPs to club nights, logs their own matches and makes predictions.')"
                :disabled="$isSelf"
            />
            <flux:radio
                :value="UserRole::Admin->value"
                :label="__('Admin (committee)')"
                :description="__('Everything members can do, plus managing the club\'s data.')"
                :disabled="$isSelf"
            />
        </flux:radio.group>

        <flux:switch
            wire:model="isActive"
            :label="__('Active member')"
            :description="__('Inactive players are hidden from the directory, the rankings and the match pickers. Their results and everybody\'s ratings stay as they are.')"
            :disabled="$isSelf"
            align="left"
            data-test="field-active"
        />
    </x-ui.form-section>

    {{ $slot }}
</form>
