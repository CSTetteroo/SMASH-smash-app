@props([
    /*
     | x-tournaments.registration — the viewer's registration block in the tournament hero card.
     | Renders inside pages::tournaments.show: its buttons call that page's register / withdraw /
     | logInToRegister actions, which authorise and validate everything again on the server.
     |
     | tournament   App\Models\Tournament (entries loaded or entries_count).
     | entry        The viewer's ?TournamentEntry (player + partner loaded).
     | partners     Eligible doubles partners (active players not entered yet) for the select.
     */
    'tournament',
    'entry' => null,
    'partners' => null,
])

@php
    $t = $tournament;
    $user = auth()->user();
    $isDoubles = $t->isDoubles();
    $deadline = $t->registrationDeadline();
    $partners = collect($partners ?? []);
    $partner = $entry && $user ? $entry->members()->first(fn ($member) => ! $member->is($user)) : null;
@endphp

<div {{ $attributes->class('space-y-4') }} data-tournament-registration>
    @error('tournament')
        <x-ui.alert tone="danger">{{ $message }}</x-ui.alert>
    @enderror

    @if ($entry)
        {{-- Registered: show the entry and (before the draw) a way out. --}}
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.tag tone="green" variant="solid" icon="check">{{ __("You're in") }}</x-ui.tag>
            @if ($partner)
                <span class="text-sm text-ink-soft">{{ __('with :name', ['name' => $partner->name]) }}</span>
            @endif
        </div>

        @if (! $t->hasDraw())
            <p class="text-sm text-ink-soft">
                {{ __('Seeds are set by rating when the draw is made. Plans changed? You can withdraw until then.') }}
            </p>
            <flux:modal.trigger name="withdraw-entry">
                <x-ui.button variant="danger-outline" size="sm" icon="x-mark">{{ $isDoubles ? __('Withdraw our pair') : __('Withdraw') }}</x-ui.button>
            </flux:modal.trigger>

            <flux:modal name="withdraw-entry" class="md:w-[28rem]">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Withdraw from :name?', ['name' => $t->name]) }}</flux:heading>
                        <flux:text class="mt-2">
                            @if ($partner)
                                {{ __('This takes your pair out, so :partner is withdrawn too. You can register again while registration is open.', ['partner' => $partner->name]) }}
                            @else
                                {{ __('Your place goes back to the pool. You can register again while registration is open.') }}
                            @endif
                        </flux:text>
                    </div>
                    <div class="flex justify-end gap-2">
                        <flux:modal.close><x-ui.button variant="ghost" size="sm">{{ __('Keep my place') }}</x-ui.button></flux:modal.close>
                        <x-ui.button variant="danger" size="sm" wire:click="withdraw">{{ __('Withdraw') }}</x-ui.button>
                    </div>
                </div>
            </flux:modal>
        @else
            <p class="text-sm text-ink-soft">{{ __('The draw is out. Check the Draw tab for your first match.') }}</p>
        @endif
    @elseif ($t->isOpenForRegistration())
        @guest
            <x-ui.button variant="cta" block wire:click="logInToRegister">{{ __('Log in to register') }}</x-ui.button>
            <p class="text-sm text-ink-soft">
                {{ __('New to SMASH?') }}
                <a href="{{ route('register') }}" class="link" wire:navigate>{{ __('Create an account') }}</a>
                {{ __('first. It takes a minute.') }}
            </p>
        @else
            @if ($isDoubles)
                <flux:modal.trigger name="register-pair">
                    <x-ui.button variant="cta" block data-test="register-pair">{{ __('Register with partner') }}</x-ui.button>
                </flux:modal.trigger>
                <p class="mt-3 text-sm text-ink-soft">{{ __('One of you registers the pair. Your partner needs a SMASH account and must not be entered yet.') }}</p>

                <flux:modal name="register-pair" class="md:w-[30rem]">
                    <form wire:submit="register" class="space-y-6">
                        <div>
                            <flux:heading size="lg">{{ __('Register your pair') }}</flux:heading>
                            <flux:text class="mt-2">{{ __('Pairs are seeded on the average doubles rating of both partners.') }}</flux:text>
                        </div>

                        @if ($partners->isEmpty())
                            <x-ui.alert tone="warning">{{ __('Every active player is already entered, so there is nobody left to pair up with.') }}</x-ui.alert>
                        @else
                            <flux:select wire:model="partnerId" :label="__('Partner')" required>
                                <option value="">{{ __('Choose your partner') }}</option>
                                @foreach ($partners as $candidate)
                                    <option value="{{ $candidate->id }}" wire:key="partner-{{ $candidate->id }}">{{ $candidate->name }} · {{ $candidate->rating(\App\Enums\Discipline::Doubles) }}</option>
                                @endforeach
                            </flux:select>
                            @error('partner')
                                <flux:error :message="$message" />
                            @enderror
                        @endif

                        <div class="flex justify-end gap-2">
                            <flux:modal.close><x-ui.button variant="ghost" size="sm">{{ __('Cancel') }}</x-ui.button></flux:modal.close>
                            <x-ui.button variant="cta" size="sm" type="submit" :disabled="$partners->isEmpty()">{{ __('Register pair') }}</x-ui.button>
                        </div>
                    </form>
                </flux:modal>
            @else
                <x-ui.button variant="cta" block wire:click="register">{{ __('Register') }}</x-ui.button>
                <p class="text-sm text-ink-soft">{{ __('One click and you are in. You can withdraw until the draw is made.') }}</p>
            @endif
        @endguest
    @elseif ($t->status === \App\Enums\TournamentStatus::Open)
        <div class="border-s-2 border-line-strong ps-4">
            <p class="font-semibold text-ink">{{ $t->isFull() ? __('This tournament is full') : __('Registration has closed') }}</p>
            <p class="mt-1 text-sm text-ink-soft">
                {{ $t->isFull()
                    ? __('All places are taken. If someone withdraws before the draw, a place opens up again.')
                    : __('The line-up is set. The draw is published here before the first serve.') }}
            </p>
        </div>
    @endif
</div>
