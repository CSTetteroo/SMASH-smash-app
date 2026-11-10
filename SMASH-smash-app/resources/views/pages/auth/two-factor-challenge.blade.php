<x-layouts::auth :title="__('Two-factor authentication')">
    <div class="flex flex-col gap-6">
        <div
            class="relative h-auto w-full"
            x-cloak
            x-data="{
                showRecoveryInput: @js($errors->has('recovery_code')),
                code: '',
                recovery_code: '',
                focusOtp() {
                    this.$nextTick(() => this.$refs.otp?.querySelector('input')?.focus());
                },
                init() {
                    if (! this.showRecoveryInput) {
                        this.focusOtp();
                    }
                },
                toggleInput() {
                    this.showRecoveryInput = !this.showRecoveryInput;

                    this.code = '';
                    this.recovery_code = '';

                    $nextTick(() => {
                        this.showRecoveryInput
                            ? this.$refs.recovery_code?.focus()
                            : this.focusOtp();
                    });
                },
            }"
        >
            <x-ui.kicker>{{ __('Two-step log in') }}</x-ui.kicker>

            <div x-show="!showRecoveryInput">
                <x-ui.heading :level="1" class="mt-2.5">{{ __('Authentication code') }}</x-ui.heading>
                <p class="mt-5 text-[17px] leading-relaxed text-ink-soft">{{ __('Enter the authentication code provided by your authenticator application.') }}</p>
            </div>

            <div x-show="showRecoveryInput">
                <x-ui.heading :level="1" class="mt-2.5">{{ __('Recovery code') }}</x-ui.heading>
                <p class="mt-5 text-[17px] leading-relaxed text-ink-soft">{{ __('Please confirm access to your account by entering one of your emergency recovery codes.') }}</p>
            </div>

            <form method="POST" action="{{ route('two-factor.login.store') }}" class="mt-8">
                @csrf

                <div class="space-y-6">
                    <div x-show="!showRecoveryInput">
                        <div class="flex items-center justify-start" x-ref="otp">
                            <flux:otp
                                x-model="code"
                                length="6"
                                name="code"
                                label="OTP Code"
                                label:sr-only
                            />
                        </div>
                    </div>

                    <div x-show="showRecoveryInput">
                        <flux:input
                            type="text"
                            name="recovery_code"
                            x-ref="recovery_code"
                            x-bind:required="showRecoveryInput"
                            autocomplete="one-time-code"
                            x-model="recovery_code"
                        />

                        @error('recovery_code')
                            <flux:text color="red" class="mt-2">
                                {{ $message }}
                            </flux:text>
                        @enderror
                    </div>

                    <x-ui.button variant="cta" type="submit" block size="lg">
                        {{ __('Continue') }}
                    </x-ui.button>
                </div>

                <p class="mt-6 border-t border-line pt-6 text-[15px] text-ink-soft">
                    <span>{{ __('or you can') }}</span>
                    <button type="button" class="link font-medium" x-show="!showRecoveryInput" x-on:click="toggleInput()">{{ __('log in using a recovery code') }}</button>
                    <button type="button" class="link font-medium" x-show="showRecoveryInput" x-on:click="toggleInput()">{{ __('log in using an authentication code') }}</button>
                </p>
            </form>
        </div>
    </div>
</x-layouts::auth>
