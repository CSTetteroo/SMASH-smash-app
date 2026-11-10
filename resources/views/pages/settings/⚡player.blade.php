<?php

use App\Enums\Handedness;
use App\Enums\Programme;
use App\Enums\SkillLevel;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Player profile')] #[Layout('layouts::app', ['contained' => true])] class extends Component {
    public const BIO_MAX = 500;

    public string $programme = '';

    public string $level = '';

    public string $handedness = '';

    public string $bio = '';

    public function mount(): void
    {
        $user = $this->player;

        $this->programme = $user->programme->value ?? '';
        $this->level = $user->level->value ?? '';
        $this->handedness = $user->handedness->value ?? '';
        $this->bio = (string) $user->bio;
    }

    /**
     * Always the logged-in member: there is no id to tamper with.
     */
    #[Computed]
    public function player(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * Members change only these four fields. Name and email live under Profile; role, active status
     * and ratings are never editable here.
     */
    public function save(): void
    {
        $this->bio = trim($this->bio);

        $validated = $this->validate([
            'programme' => ['nullable', Rule::enum(Programme::class)],
            'level' => ['nullable', Rule::enum(SkillLevel::class)],
            'handedness' => ['nullable', Rule::enum(Handedness::class)],
            'bio' => ['nullable', 'string', 'max:'.self::BIO_MAX],
        ], [
            'programme.*' => __('Choose a programme from the list.'),
            'level.*' => __('Choose a level from the list.'),
            'handedness.*' => __('Choose right- or left-handed.'),
            'bio.max' => __('Keep your bio to :max characters.', ['max' => self::BIO_MAX]),
        ]);

        $user = $this->player;

        $user->forceFill([
            'programme' => Programme::tryFrom((string) $validated['programme']),
            'level' => SkillLevel::tryFrom((string) $validated['level']),
            'handedness' => Handedness::tryFrom((string) $validated['handedness']),
            'bio' => $validated['bio'] === '' ? null : $validated['bio'],
        ])->save();

        Flux::toast(variant: 'success', text: __('Player profile saved. Everybody sees the new version on your profile.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Player profile') }}</flux:heading>

    <x-pages::settings.layout
        :heading="__('Player profile')"
        :subheading="__('What other members see on your public profile and in the player directory.')"
    >
        <form wire:submit="save" class="my-6 w-full space-y-6" novalidate>
            <x-players.profile-fields self :bio-max="self::BIO_MAX" />

            <div class="flex flex-wrap items-center gap-4 border-t border-line pt-6">
                <x-ui.button variant="primary" type="submit" data-test="save-player-profile">{{ __('Save') }}</x-ui.button>
                <x-ui.link :href="route('players.show', $this->player)">{{ __('View your profile') }}</x-ui.link>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
