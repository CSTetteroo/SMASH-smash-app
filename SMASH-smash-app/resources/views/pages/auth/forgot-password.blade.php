<x-layouts::auth :title="__('Forgot password')">
    <div class="flex flex-col gap-7">
        <div>
            <x-ui.kicker>{{ __('Account') }}</x-ui.kicker>
            <x-ui.heading :level="1" class="mt-2.5">{{ __('Forgot your password?') }}</x-ui.heading>
            <p class="mt-5 text-[17px] leading-relaxed text-ink-soft">{{ __('Enter your email address and we will send you a link to choose a new one.') }}</p>
        </div>

        <!-- Session Status -->
        @if (session('status'))
            <x-ui.alert tone="success">{{ session('status') }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                type="email"
                required
                autofocus
                placeholder="email@example.com"
            />

            <x-ui.button variant="cta" type="submit" block size="lg" data-test="email-password-reset-link-button">
                {{ __('Email password reset link') }}
            </x-ui.button>
        </form>

        <p class="border-t border-line pt-6 text-[15px] text-ink-soft">
            {{ __('Remembered it?') }}
            <a href="{{ route('login') }}" wire:navigate class="link font-medium">{{ __('Back to log in') }}</a>
        </p>
    </div>
</x-layouts::auth>
