<x-layouts::auth :title="__('Email verification')">
    <div class="flex flex-col gap-7">
        <div>
            <x-ui.kicker>{{ __('One more step') }}</x-ui.kicker>
            <x-ui.heading :level="1" class="mt-2.5">{{ __('Verify your email address') }}</x-ui.heading>
            <p class="mt-5 text-[17px] leading-relaxed text-ink-soft">
                {{ __('Please verify your email address by clicking on the link we just emailed to you.') }}
            </p>
        </div>

        @if (session('status') == 'verification-link-sent')
            <x-ui.alert tone="success">
                {{ __('A new verification link has been sent to the email address you provided during registration.') }}
            </x-ui.alert>
        @endif

        <div class="flex flex-col gap-4">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <x-ui.button type="submit" variant="cta" block size="lg">
                    {{ __('Resend verification email') }}
                </x-ui.button>
            </form>

            <form method="POST" action="{{ route('logout') }}" class="border-t border-line pt-4">
                @csrf
                <x-ui.button variant="ghost" type="submit" size="sm" icon="arrow-right-start-on-rectangle" data-test="logout-button">
                    {{ __('Log out') }}
                </x-ui.button>
            </form>
        </div>
    </div>
</x-layouts::auth>
