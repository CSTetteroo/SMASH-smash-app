<?php

use App\Actions\Players\DeletePlayer;
use App\Enums\Handedness;
use App\Enums\Programme;
use App\Enums\SkillLevel;
use App\Enums\UserRole;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    public const BIO_MAX = 500;

    #[Locked]
    public User $user;

    public string $name = '';

    public string $email = '';

    public string $role = 'member';

    public string $programme = '';

    public string $level = '';

    public string $handedness = '';

    public string $bio = '';

    public bool $isActive = true;

    public function mount(User $user): void
    {
        $this->authorize('update', $user);

        $this->user = $user;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = $user->role->value;
        $this->programme = $user->programme->value ?? '';
        $this->level = $user->level->value ?? '';
        $this->handedness = $user->handedness->value ?? '';
        $this->bio = (string) $user->bio;
        $this->isActive = $user->is_active;
    }

    public function render(): View
    {
        return $this->view()->title(__('Edit :name', ['name' => $this->user->name]));
    }

    #[Computed]
    public function isSelf(): bool
    {
        return $this->user->is(auth()->user());
    }

    public function save(): void
    {
        $this->authorize('update', $this->user);

        $this->name = trim($this->name);
        $this->email = Str::lower(trim($this->email));
        $this->bio = trim($this->bio);

        $validated = $this->validate();

        if ($this->isSelf && $validated['role'] !== UserRole::Admin->value) {
            $this->addError('role', __('You can\'t remove your own admin role. Ask another committee member to do it.'));

            return;
        }

        if ($this->isSelf && ! $validated['isActive']) {
            $this->addError('isActive', __('You can\'t deactivate your own account.'));

            return;
        }

        $this->user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => UserRole::from($validated['role']),
            'programme' => Programme::tryFrom((string) $validated['programme']),
            'level' => SkillLevel::tryFrom((string) $validated['level']),
            'handedness' => Handedness::tryFrom((string) $validated['handedness']),
            'bio' => $validated['bio'] === '' ? null : $validated['bio'],
            'is_active' => (bool) $validated['isActive'],
        ]);

        $changed = $this->user->isDirty();
        $this->user->save();

        Flux::toast(
            variant: 'success',
            text: $changed
                ? __('Saved. :name\'s profile is up to date.', ['name' => $this->user->name])
                : __('Nothing changed. :name\'s profile is as it was.', ['name' => $this->user->name]),
        );

        $this->redirectRoute('players.show', ['user' => $this->user->id], navigate: true);
    }

    /**
     * Admins: delete the player for good (DeletePlayer removes their friendlies and entries and
     * recalculates every rating).
     */
    public function delete(): void
    {
        $this->authorize('delete', $this->user);

        $name = $this->user->name;

        DeletePlayer::handle($this->user);

        Flux::toast(variant: 'success', text: __(':name has been deleted and all ratings were recalculated.', ['name' => $name]));

        $this->redirectRoute('players.index', navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($this->user->id)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'programme' => ['nullable', Rule::enum(Programme::class)],
            'level' => ['nullable', Rule::enum(SkillLevel::class)],
            'handedness' => ['nullable', Rule::enum(Handedness::class)],
            'bio' => ['nullable', 'string', 'max:'.self::BIO_MAX],
            'isActive' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'name.required' => __('Enter the player\'s full name.'),
            'email.required' => __('Enter the email address they log in with.'),
            'email.email' => __('That doesn\'t look like an email address.'),
            'email.unique' => __('Another member already uses this email address.'),
            'role.*' => __('Choose member or admin.'),
            'programme.*' => __('Choose a programme from the list.'),
            'level.*' => __('Choose a level from the list.'),
            'handedness.*' => __('Choose right- or left-handed.'),
            'bio.max' => __('Keep the bio to :max characters.', ['max' => self::BIO_MAX]),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['isActive' => __('active member')];
    }
}; ?>

<div>
    <x-ui.page-hero
        tone="white"
        :breadcrumbs="[[__('Players'), route('players.index')], [$user->name, route('players.show', $user)], __('Edit')]"
        :kicker="__('Edit player')"
        :title="$user->name"
        :intro="__('Update :first\'s account, player profile and club role.', ['first' => $user->firstName()])"
    >
        <x-slot:meta>
            @if ($user->isAdmin())
                <x-ui.tag tone="navy" variant="solid" icon="shield-check">{{ __('Committee') }}</x-ui.tag>
            @endif
            @unless ($user->is_active)
                <x-ui.tag tone="grey">{{ __('Inactive') }}</x-ui.tag>
            @endunless
            <x-ui.tag tone="grey">{{ __('Member since :date', ['date' => $user->created_at?->format('F Y') ?? '–']) }}</x-ui.tag>
        </x-slot:meta>
    </x-ui.page-hero>

    <x-ui.section width="narrow" spacing="md">
        <x-players.admin-form :player="$user" :is-self="$this->isSelf" :bio-max="self::BIO_MAX">
            <x-ui.form-actions>
                @can('delete', $user)
                    <x-slot:secondary>
                        <flux:modal.trigger name="delete-player">
                            <x-ui.button variant="danger-outline" size="sm" icon="trash" data-test="delete-player">{{ __('Delete player') }}</x-ui.button>
                        </flux:modal.trigger>
                    </x-slot:secondary>
                @endcan
                <x-ui.button variant="ghost" :href="route('players.show', $user)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="cta" type="submit" data-test="save-player">{{ __('Save changes') }}</x-ui.button>
            </x-ui.form-actions>
        </x-players.admin-form>
    </x-ui.section>

    @can('delete', $user)
        <x-players.delete-modal :user="$user" />
    @endcan
</div>
