<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-7">
        <div>
            <x-ui.kicker>{{ __('Join the club') }}</x-ui.kicker>
            <x-ui.heading :level="1" class="mt-2.5">{{ __('Create your SMASH account') }}</x-ui.heading>
            <p class="mt-5 text-[17px] leading-relaxed text-ink-soft">{{ __('One account for club nights, ratings, tournaments and pick\'em. Every level is welcome.') }}</p>
        </div>

        @if (session('status'))
            <x-ui.alert tone="success">{{ session('status') }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Name -->
            <flux:input
                name="name"
                :label="__('Name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Full name')"
            />

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <x-ui.button variant="cta" type="submit" block size="lg" data-test="register-user-button">
                {{ __('Create account') }}
            </x-ui.button>
        </form>

        <p class="border-t border-line pt-6 text-[15px] text-ink-soft">
            {{ __('Already a member?') }}
            <a href="{{ route('login') }}" wire:navigate class="link font-medium">{{ __('Log in') }}</a>
        </p>
    </div>
</x-layouts::auth>
