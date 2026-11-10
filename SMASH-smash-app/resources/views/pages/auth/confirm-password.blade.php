<x-layouts::auth :title="__('Confirm password')">
    <div class="flex flex-col gap-7">
        <div>
            <x-ui.kicker>{{ __('Secure area') }}</x-ui.kicker>
            <x-ui.heading :level="1" class="mt-2.5">{{ __('Confirm password') }}</x-ui.heading>
            <p class="mt-5 text-[17px] leading-relaxed text-ink-soft">{{ __('This is a secure area of the application. Please confirm your password before continuing.') }}</p>
        </div>

        @if (session('status'))
            <x-ui.alert tone="success">{{ session('status') }}</x-ui.alert>
        @endif

        <x-passkey-verify
            options-route="passkey.confirm-options"
            submit-route="passkey.confirm"
            :label="__('Confirm with passkey')"
            :loading-label="__('Confirming...')"
            :separator="__('Or confirm with password')"
        />

        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('Password')"
                viewable
            />

            <x-ui.button variant="primary" type="submit" block size="lg" data-test="confirm-password-button">
                {{ __('Confirm') }}
            </x-ui.button>
        </form>
    </div>
</x-layouts::auth>
