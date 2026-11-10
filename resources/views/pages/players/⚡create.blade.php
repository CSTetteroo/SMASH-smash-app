<?php

use App\Enums\Handedness;
use App\Enums\Programme;
use App\Enums\SkillLevel;
use App\Enums\UserRole;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New player')] class extends Component {
    public const BIO_MAX = 500;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $role = 'member';

    public string $programme = '';

    public string $level = '';

    public string $handedness = '';

    public string $bio = '';

    public bool $isActive = true;

    public function mount(): void
    {
        $this->authorize('create', User::class);
    }

    public function save(): void
    {
        $this->authorize('create', User::class);

        $this->name = trim($this->name);
        $this->email = Str::lower(trim($this->email));
        $this->bio = trim($this->bio);

        $validated = $this->validate();

        $generated = $validated['password'] === null || $validated['password'] === '';

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $generated ? Str::password(24) : $validated['password'],
            'role' => UserRole::from($validated['role']),
            'programme' => Programme::tryFrom((string) $validated['programme']),
            'level' => SkillLevel::tryFrom((string) $validated['level']),
            'handedness' => Handedness::tryFrom((string) $validated['handedness']),
            'bio' => $validated['bio'] === '' ? null : $validated['bio'],
            'is_active' => (bool) $validated['isActive'],
        ]);

        // The committee vouches for accounts it creates.
        $user->forceFill(['email_verified_at' => now()])->save();

        Flux::toast(
            variant: 'success',
            heading: __(':name has joined SMASH', ['name' => $user->name]),
            text: $generated
                ? __('We set a random password. :first can choose their own with "Forgot your password?" on the login page.', ['first' => $user->firstName()])
                : __(':first can log in with the password you chose.', ['first' => $user->firstName()]),
            duration: 8000,
        );

        $this->redirectRoute('players.show', ['user' => $user->id], navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['nullable', 'string', Password::default()],
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
        :breadcrumbs="[[__('Players'), route('players.index')], __('New player')]"
        :kicker="__('Players')"
        :title="__('New player')"
        :intro="__('Add a member to the club. They appear in the directory straight away and get a rating after their first match.')"
    />

    <x-ui.section width="narrow" spacing="md">
        <x-players.admin-form :bio-max="self::BIO_MAX">
            <x-ui.form-actions>
                <x-ui.button variant="ghost" :href="route('players.index')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="cta" type="submit" data-test="save-player">{{ __('Add player') }}</x-ui.button>
            </x-ui.form-actions>
        </x-players.admin-form>
    </x-ui.section>
</div>
