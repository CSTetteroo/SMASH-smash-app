<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-7">
        <div>
            <x-ui.kicker>{{ __('Members') }}</x-ui.kicker>
            <x-ui.heading :level="1" class="mt-2.5">{{ __('Log in to SMASH') }}</x-ui.heading>
            <p class="mt-5 text-[17px] leading-relaxed text-ink-soft">{{ __('Welcome back. Log in to RSVP for club nights, log your matches and make your picks.') }}</p>
        </div>

        @if (session('status'))
            <x-ui.alert tone="success">{{ session('status') }}</x-ui.alert>
        @endif

        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute end-0 top-0 text-[13px] leading-[1.35]" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <x-ui.button variant="cta" type="submit" block size="lg" data-test="login-button">
                {{ __('Log in') }}
            </x-ui.button>
        </form>

        <p class="border-t border-line pt-6 text-[15px] text-ink-soft">
            {{ __('New to SMASH?') }}
            <a href="{{ route('register') }}" wire:navigate class="link font-medium">{{ __('Join the club') }}</a>
        </p>
    </div>
</x-layouts::auth>
