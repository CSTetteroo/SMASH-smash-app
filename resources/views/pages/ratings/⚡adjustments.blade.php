<?php

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Models\RatingAdjustment;
use App\Models\User;
use App\Services\Rating\RatingEngine;
use App\Support\SmashConfig;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Rating adjustments')] class extends Component {
    use WithPagination;

    /** When an adjustment starts to count: now, a minute before the player's first match, or a chosen moment. */
    public const TIMINGS = ['now' => 'Now', 'first' => 'Before first match', 'custom' => 'Pick a date'];

    public const PER_PAGE = 15;

    // ---- Filters -------------------------------------------------------

    #[Url(except: '')]
    public string $player = '';

    #[Url(except: '')]
    public string $discipline = '';

    // ---- Create / edit form ------------------------------------------------

    public bool $showForm = false;

    /** The adjustment being edited (null: a new one). Set by the server only. */
    #[Locked]
    public ?int $editingId = null;

    /** The edited adjustment's stored moment (Y-m-d H:i:s), kept exactly when the date field is left alone. */
    #[Locked]
    public ?string $originalEffectiveAt = null;

    public string $userId = '';

    public string $formDiscipline = 'singles';

    public string $amount = '';

    public string $reason = '';

    public string $timing = 'now';

    /** datetime-local value (Y-m-d\TH:i), used when timing is "custom". */
    public string $effectiveAt = '';

    // ---- Delete confirmation ---------------------------------------------------

    public bool $showDelete = false;

    #[Locked]
    public ?int $deletingId = null;

    public function mount(): void
    {
        Gate::authorize('manage');
    }

    // ---- Queries -----------------------------------------------------------

    /**
     * The log, newest effective moment first.
     *
     * @return LengthAwarePaginator<int, RatingAdjustment>
     */
    #[Computed]
    public function adjustments(): LengthAwarePaginator
    {
        $player = $this->playerFilter();
        $discipline = Discipline::tryFrom($this->discipline);

        return RatingAdjustment::query()
            ->with(['user', 'creator'])
            ->when($player !== null, fn ($query) => $query->forPlayer($player))
            ->when($discipline !== null, fn ($query) => $query->forDiscipline($discipline))
            ->latestFirst()
            ->paginate(self::PER_PAGE);
    }

    /**
     * Totals over the whole log (not the filters).
     *
     * @return array{count: int, players: int, added: float, removed: float, latest: ?RatingAdjustment}
     */
    #[Computed]
    public function summary(): array
    {
        $totals = DB::table('rating_adjustments')
            ->selectRaw('count(*) as total, count(distinct user_id) as players')
            ->selectRaw('coalesce(sum(case when amount > 0 then amount else 0 end), 0) as added')
            ->selectRaw('coalesce(sum(case when amount < 0 then amount else 0 end), 0) as removed')
            ->first();

        return [
            'count' => (int) ($totals->total ?? 0),
            'players' => (int) ($totals->players ?? 0),
            'added' => (float) ($totals->added ?? 0),
            'removed' => (float) ($totals->removed ?? 0),
            'latest' => RatingAdjustment::query()->with('creator')->latest('created_at')->latest('id')->first(),
        ];
    }

    /**
     * Players who have at least one adjustment (for the filter).
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function filterPlayers(): Collection
    {
        return User::query()
            ->whereHas('ratingAdjustments')
            ->withCount('ratingAdjustments')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Everyone who can be adjusted (for the form). Only the columns the options need: no emails.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function players(): Collection
    {
        return User::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'is_active', 'singles_rating', 'doubles_rating', 'singles_played', 'doubles_played']);
    }

    #[Computed]
    public function selectedPlayer(): ?User
    {
        $id = filter_var($this->userId, FILTER_VALIDATE_INT);

        return $id === false ? null : $this->players->firstWhere('id', $id);
    }

    /**
     * When the player's first rated match in the chosen discipline was played (for "Before first match").
     */
    #[Computed]
    public function firstMatchAt(): ?CarbonImmutable
    {
        $player = $this->selectedPlayer;
        $discipline = Discipline::tryFrom($this->formDiscipline);

        return $player === null || $discipline === null ? null : RatingAdjustment::firstRatedMatchAt($player, $discipline);
    }

    /**
     * The moment the form describes, or null while it is incomplete.
     */
    #[Computed]
    public function effectiveMoment(): ?CarbonImmutable
    {
        return match ($this->timing) {
            'now' => CarbonImmutable::now(),
            'first' => $this->selectedPlayer === null || Discipline::tryFrom($this->formDiscipline) === null
                ? null
                : ($this->firstMatchAt?->subMinute() ?? RatingAdjustment::seasonStart()),
            'custom' => $this->customMoment(),
            default => null,
        };
    }

    /**
     * "What this does": the player's rating now and after a replay with the form's adjustment
     * (a dry run, nothing is written), and how many other players move with it.
     *
     * @return array{current: float, after: float, played: int, later: int, others: int, othersMax: float}|null
     */
    #[Computed]
    public function preview(): ?array
    {
        $player = $this->selectedPlayer;
        $discipline = Discipline::tryFrom($this->formDiscipline);
        $amount = filter_var($this->amount, FILTER_VALIDATE_INT);
        $at = $this->effectiveMoment;

        if (! $this->showForm || $player === null || $discipline === null || $at === null
            || $amount === false || $amount === 0 || abs($amount) > RatingAdjustment::MAX_AMOUNT) {
            return null;
        }

        $state = RatingEngine::simulate(
            [['id' => $this->editingId, 'user_id' => $player->id, 'discipline' => $discipline, 'amount' => $amount, 'effective_at' => $this->resolvedMoment($at)]],
            $this->editingId === null ? [] : [$this->editingId],
        );

        $initial = SmashConfig::initialRating();
        $stored = DB::table('users')->pluck($discipline->ratingColumn(), 'id');
        $others = 0;
        $othersMax = 0.0;

        foreach ($stored as $id => $rating) {
            if ((int) $id === $player->id) {
                continue;
            }

            $change = abs(($state[$discipline->value][(int) $id]['rating'] ?? $initial) - (float) $rating);

            if ($change >= 0.5) {
                $others++;
                $othersMax = max($othersMax, $change);
            }
        }

        $later = DB::table('match_players')
            ->join('matches', 'matches.id', '=', 'match_players.match_id')
            ->where('match_players.user_id', $player->id)
            ->where('matches.discipline', $discipline->value)
            ->where('matches.status', MatchStatus::Completed->value)
            ->where('matches.is_rated', true)
            ->where('matches.played_at', '>=', $this->resolvedMoment($at))
            ->count();

        return [
            'current' => $player->ratingExact($discipline),
            'after' => $state[$discipline->value][$player->id]['rating'] ?? $initial,
            'played' => $player->played($discipline),
            'later' => $later,
            'others' => $others,
            'othersMax' => $othersMax,
        ];
    }

    /**
     * The delete confirmation: who it affects and where their rating ends up without it.
     *
     * @return array{name: string, discipline: Discipline, amount: string, current: float, after: float}|null
     */
    #[Computed]
    public function deletePreview(): ?array
    {
        if (! $this->showDelete || $this->deletingId === null) {
            return null;
        }

        $adjustment = RatingAdjustment::query()->with('user')->find($this->deletingId);

        if ($adjustment === null) {
            return null;
        }

        $state = RatingEngine::simulate(without: [$adjustment->id]);

        return [
            'name' => $adjustment->user->name,
            'discipline' => $adjustment->discipline,
            'amount' => $adjustment->formattedAmount(),
            'current' => $adjustment->user->ratingExact($adjustment->discipline),
            'after' => $state[$adjustment->discipline->value][$adjustment->user_id]['rating'] ?? SmashConfig::initialRating(),
        ];
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return $this->playerFilter() !== null || Discipline::tryFrom($this->discipline) !== null;
    }

    // ---- Filters -------------------------------------------------------

    public function updatedPlayer(): void
    {
        $this->resetPage();
    }

    public function updatedDiscipline(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('player', 'discipline');
        $this->resetPage();
    }

    // ---- Form ------------------------------------------------------------

    public function create(): void
    {
        Gate::authorize('manage');
        Gate::authorize('create', RatingAdjustment::class);

        $this->resetForm();

        // Coming from a filtered log usually means "another one for this player".
        if ($this->playerFilter() !== null) {
            $this->userId = (string) $this->playerFilter();
        }

        if (Discipline::tryFrom($this->discipline) !== null) {
            $this->formDiscipline = $this->discipline;
        }

        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        Gate::authorize('manage');

        $adjustment = RatingAdjustment::query()->findOrFail($id);
        Gate::authorize('update', $adjustment);

        $this->resetForm();
        $this->editingId = $adjustment->id;
        $this->originalEffectiveAt = $adjustment->effective_at->format('Y-m-d H:i:s');
        $this->userId = (string) $adjustment->user_id;
        $this->formDiscipline = $adjustment->discipline->value;
        $this->amount = (string) (int) round($adjustment->amount);
        $this->reason = $adjustment->reason;
        $this->timing = 'custom';
        $this->effectiveAt = $adjustment->effective_at->format('Y-m-d\TH:i');
        $this->showForm = true;
    }

    public function updatedTiming(): void
    {
        if ($this->timing === 'custom' && $this->effectiveAt === '') {
            $this->effectiveAt = CarbonImmutable::now()->format('Y-m-d\TH:i');
        }
    }

    public function save(): void
    {
        Gate::authorize('manage');

        $this->reason = trim((string) preg_replace('/\s+/u', ' ', $this->reason));

        $validated = $this->validate();

        $adjustment = null;

        if ($this->editingId !== null) {
            $adjustment = RatingAdjustment::query()->findOrFail($this->editingId);
            Gate::authorize('update', $adjustment);
        } else {
            Gate::authorize('create', RatingAdjustment::class);
        }

        $player = User::query()->findOrFail((int) $validated['userId']);
        $discipline = Discipline::from($validated['formDiscipline']);
        $at = $this->effectiveMoment;

        if ($at === null) {
            $this->addError('effectiveAt', __('Choose when the adjustment starts to count.'));

            return;
        }

        $was = $player->ratingExact($discipline);

        DB::transaction(function () use ($adjustment, $player, $discipline, $validated, $at): void {
            $attributes = [
                'user_id' => $player->id,
                'discipline' => $discipline,
                'amount' => (float) (int) $validated['amount'],
                'reason' => $validated['reason'],
                'effective_at' => $this->resolvedMoment($at),
            ];

            if ($adjustment === null) {
                RatingAdjustment::query()->create([...$attributes, 'created_by' => auth()->id()]);
            } else {
                $adjustment->update($attributes);
            }

            RatingEngine::recalculate();
        });

        $now = $player->refresh()->rating($discipline);

        Flux::toast(
            variant: 'success',
            heading: $adjustment === null ? __('Adjustment saved') : __('Adjustment updated'),
            text: __(':name is now on :now in :discipline (was :was).', [
                'name' => $player->name,
                'now' => $now,
                'discipline' => strtolower($discipline->label()),
                'was' => (int) round($was),
            ]),
        );

        $this->showForm = false;
        $this->resetForm();
        $this->clearCaches();
    }

    // ---- Delete ------------------------------------------------------------

    public function confirmDelete(int $id): void
    {
        Gate::authorize('manage');

        $adjustment = RatingAdjustment::query()->findOrFail($id);
        Gate::authorize('delete', $adjustment);

        $this->deletingId = $adjustment->id;
        $this->showDelete = true;
    }

    public function delete(): void
    {
        Gate::authorize('manage');

        $adjustment = $this->deletingId === null ? null : RatingAdjustment::query()->with('user')->find($this->deletingId);

        if ($adjustment === null) {
            $this->showDelete = false;
            $this->deletingId = null;
            Flux::toast(variant: 'warning', text: __('That adjustment was already deleted.'));

            return;
        }

        Gate::authorize('delete', $adjustment);

        $player = $adjustment->user;
        $discipline = $adjustment->discipline;

        DB::transaction(function () use ($adjustment): void {
            $adjustment->delete();
            RatingEngine::recalculate();
        });

        Flux::toast(
            variant: 'success',
            heading: __('Adjustment deleted'),
            text: __(':name is now on :now in :discipline.', [
                'name' => $player->name,
                'now' => $player->refresh()->rating($discipline),
                'discipline' => strtolower($discipline->label()),
            ]),
        );

        $this->showDelete = false;
        $this->deletingId = null;
        $this->clearCaches();

        // Deleting the last row of a page: step back so the log never shows an empty page.
        if ($this->adjustments->isEmpty() && $this->adjustments->currentPage() > 1) {
            $this->previousPage();
        }
    }

    // ---- Validation ------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'userId' => ['required', 'integer', Rule::exists('users', 'id')],
            'formDiscipline' => ['required', Rule::enum(Discipline::class)],
            'amount' => [
                'required',
                'integer',
                'between:-'.RatingAdjustment::MAX_AMOUNT.','.RatingAdjustment::MAX_AMOUNT,
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_numeric($value) && (int) $value === 0) {
                        $fail(__('An adjustment of 0 changes nothing. Use a minus for points off, e.g. −25.'));
                    }
                },
            ],
            'reason' => ['required', 'string', 'min:5', 'max:'.RatingAdjustment::REASON_LENGTH],
            'timing' => ['required', Rule::in(array_keys(self::TIMINGS))],
            'effectiveAt' => [
                'exclude_unless:timing,custom',
                'required',
                'date_format:Y-m-d\TH:i',
                'after_or_equal:2020-01-01',
                'before_or_equal:now',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'userId.required' => __('Choose the player whose rating changes.'),
            'userId.exists' => __('That player no longer exists.'),
            'amount.required' => __('Enter how many rating points to add or take off.'),
            'amount.integer' => __('Use whole rating points, e.g. 150 or −25.'),
            'amount.between' => __('One adjustment can move a rating by at most :max points either way.', ['max' => RatingAdjustment::MAX_AMOUNT]),
            'reason.required' => __('Say why. The reason is what makes the log useful later.'),
            'reason.min' => __('Say a little more about why (at least 5 characters).'),
            'reason.max' => __('Keep the reason under :max characters.', ['max' => RatingAdjustment::REASON_LENGTH]),
            'effectiveAt.required' => __('Pick the date and time the adjustment counts from.'),
            'effectiveAt.date_format' => __('Pick a date and a time.'),
            'effectiveAt.after_or_equal' => __('That date is before SMASH kept ratings.'),
            'effectiveAt.before_or_equal' => __('An adjustment can\'t start in the future. Use "Now" for one that counts from today.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'userId' => __('player'),
            'formDiscipline' => __('discipline'),
            'effectiveAt' => __('date'),
        ];
    }

    // ---- Helpers -----------------------------------------------------------

    private function playerFilter(): ?int
    {
        $id = filter_var($this->player, FILTER_VALIDATE_INT);

        return $id === false || $id < 1 ? null : $id;
    }

    private function customMoment(): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $this->effectiveAt) !== 1) {
            return null;
        }

        $moment = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->effectiveAt);

        return $moment === null ? null : $moment->startOfMinute();
    }

    /**
     * The stored moment: an edited adjustment whose date field was left alone keeps its exact
     * stored time (seconds included), so it never jumps across a match played in the same minute.
     */
    private function resolvedMoment(CarbonImmutable $moment): string
    {
        if ($this->timing === 'custom' && $this->originalEffectiveAt !== null
            && substr($this->originalEffectiveAt, 0, 16) === $moment->format('Y-m-d H:i')) {
            return $this->originalEffectiveAt;
        }

        return $moment->format('Y-m-d H:i:s');
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'originalEffectiveAt', 'userId', 'formDiscipline', 'amount', 'reason', 'timing', 'effectiveAt');
        $this->resetErrorBag();
        $this->resetValidation();
    }

    private function clearCaches(): void
    {
        unset($this->adjustments, $this->summary, $this->filterPlayers, $this->players, $this->selectedPlayer, $this->preview, $this->deletePreview);
    }
}; ?>

<div>
    @php
        $summary = $this->summary;
        $adjustments = $this->adjustments;
        $selected = $this->selectedPlayer;
        $preview = $this->preview;
        $chosen = Discipline::tryFrom($formDiscipline) ?? Discipline::Singles;
        $moment = $this->effectiveMoment;
    @endphp

    <x-ui.page-hero
        tone="white"
        :breadcrumbs="[[__('Admin'), route('admin.index')], __('Rating adjustments')]"
        :kicker="__('Committee · ELO management')"
        :title="__('Rating adjustments')"
        :intro="__('Ratings come from replaying every rated match, so nobody edits a number by hand. When a rating needs something the results can\'t give it, such as a placement for an experienced newcomer, a match played outside SMASH or a penalty, add an adjustment. It counts from the moment you choose, and every later match is re-rated around it.')"
    >
        <x-slot:actions>
            <x-ui.button variant="cta" wire:click="create" data-test="new-adjustment">{{ __('New adjustment') }}</x-ui.button>
            <x-ui.button variant="outline" :href="route('rankings.index')">{{ __('Rankings') }}</x-ui.button>
        </x-slot:actions>

        <x-slot:aside>
            <p class="kicker">{{ __('The log so far') }}</p>
            <dl class="mt-4 divide-y divide-line border-y border-line" data-test="adjustment-summary">
                @foreach ([
                    ['label' => __('Adjustments'), 'value' => $summary['count'] ?: '–', 'tone' => null],
                    ['label' => __('Players adjusted'), 'value' => $summary['players'] ?: '–', 'tone' => null],
                    ['label' => __('Points added'), 'value' => $summary['added'] > 0 ? RatingAdjustment::format($summary['added']) : '–', 'tone' => $summary['added'] > 0 ? 'win' : null],
                    ['label' => __('Points taken off'), 'value' => $summary['removed'] < 0 ? RatingAdjustment::format($summary['removed']) : '–', 'tone' => $summary['removed'] < 0 ? 'loss' : null],
                ] as $row)
                    <div class="flex items-baseline justify-between gap-4 py-2.5">
                        <dt class="text-sm text-ink-soft">{{ $row['label'] }}</dt>
                        <dd @class([
                            'num text-[26px] leading-none font-extrabold',
                            'text-heading' => $row['tone'] === null,
                            'text-win' => $row['tone'] === 'win',
                            'text-loss' => $row['tone'] === 'loss',
                        ])>{{ $row['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-4 text-sm text-ink-muted">
                @if ($summary['latest'])
                    {{ __('Last change :date', ['date' => $summary['latest']->created_at?->format('j M Y')]) }}@if ($summary['latest']->creator) {{ __('by :name', ['name' => $summary['latest']->creator->name]) }}@endif.
                @else
                    {{ __('Nothing adjusted yet: every rating comes straight from match results.') }}
                @endif
            </p>
        </x-slot:aside>
    </x-ui.page-hero>

    <x-ui.section :title="__('Adjustment log')" :kicker="__('Audit trail')" id="log">
        <x-slot:actions>
            <p class="text-sm text-ink-muted" aria-live="polite">
                {{ trans_choice(':count adjustment|:count adjustments', $adjustments->total()) }}
            </p>
        </x-slot:actions>

        <x-ui.toolbar class="mb-6">
            <flux:select wire:model.live="player" :label="__('Player')" class="w-full sm:w-72" data-test="filter-player">
                <option value="">{{ __('All players') }}</option>
                @foreach ($this->filterPlayers as $option)
                    <option value="{{ $option->id }}" wire:key="fp-{{ $option->id }}">{{ $option->name }} ({{ $option->rating_adjustments_count }})</option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="discipline" :label="__('Discipline')" class="w-full sm:w-48" data-test="filter-discipline">
                <option value="">{{ __('Singles and doubles') }}</option>
                @foreach (Discipline::cases() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </flux:select>

            @if ($this->hasFilters)
                <div class="flex min-h-10 items-center">
                    <x-ui.button variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</x-ui.button>
                </div>
            @endif
        </x-ui.toolbar>

        <div wire:loading.class="opacity-60" wire:target="player, discipline, clearFilters, gotoPage, nextPage, previousPage">
            @if ($adjustments->isEmpty())
                @if ($this->hasFilters)
                    <x-ui.empty :title="__('No adjustments match those filters')" icon="funnel">
                        {{ __('This player has no adjustments in that discipline.') }}
                        <x-slot:actions>
                            <x-ui.button variant="outline" size="sm" wire:click="clearFilters">{{ __('Clear filters') }}</x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty>
                @else
                    <x-ui.empty :title="__('No adjustments yet')">
                        {{ __('Every rating comes straight from match results. Add an adjustment when a newcomer needs a placement or a result can\'t be logged as a match.') }}
                        <x-slot:actions>
                            <x-ui.button variant="primary" size="sm" icon="plus" wire:click="create">{{ __('New adjustment') }}</x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty>
                @endif
            @else
                <x-ui.table dense class="max-sm:hidden" data-test="adjustments-table">
                    <table>
                        <thead>
                            <tr>
                                <th class="w-36">{{ __('Counts from') }}</th>
                                <th>{{ __('Player') }}</th>
                                <th>{{ __('Discipline') }}</th>
                                <th class="text-end">{{ __('Amount') }}</th>
                                <th class="min-w-64">{{ __('Reason') }}</th>
                                <th class="max-md:hidden">{{ __('Added by') }}</th>
                                <th class="w-24 text-end"><span class="sr-only">{{ __('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($adjustments as $adjustment)
                                <tr wire:key="adj-{{ $adjustment->id }}" data-test="adjustment-row">
                                    <td class="whitespace-nowrap">
                                        <span class="block text-[15px] font-medium text-ink">{{ $adjustment->effective_at->format('D j M Y') }}</span>
                                        <span class="num block text-[15px] font-semibold text-ink-muted">{{ $adjustment->effective_at->format('H:i') }}</span>
                                    </td>
                                    <td class="min-w-0">
                                        <x-smash.player :user="$adjustment->user" size="sm" />
                                    </td>
                                    <td>
                                        <x-smash.discipline-badge :discipline="$adjustment->discipline" />
                                    </td>
                                    <td class="text-end">
                                        <x-ratings.amount :adjustment="$adjustment" />
                                    </td>
                                    <td class="text-sm leading-relaxed text-ink-soft">{{ $adjustment->reason }}</td>
                                    <td class="whitespace-nowrap text-sm max-md:hidden">
                                        <span class="block text-ink">{{ $adjustment->creator?->name ?? __('Committee') }}</span>
                                        <span class="block text-ink-muted">
                                            {{ $adjustment->created_at?->format('j M Y') }}
                                            @if ($adjustment->wasEdited())
                                                · <span title="{{ __('Edited :date', ['date' => $adjustment->updated_at?->format('j M Y, H:i')]) }}">{{ __('edited') }}</span>
                                            @endif
                                        </span>
                                    </td>
                                    <td class="text-end whitespace-nowrap">
                                        <x-ui.button variant="ghost" size="sm" square icon="pencil-square" wire:click="edit({{ $adjustment->id }})" :aria-label="__('Edit adjustment for :name', ['name' => $adjustment->user->name])" data-test="edit-adjustment" />
                                        <x-ui.button variant="ghost" size="sm" square icon="trash" wire:click="confirmDelete({{ $adjustment->id }})" :aria-label="__('Delete adjustment for :name', ['name' => $adjustment->user->name])" data-test="delete-adjustment" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if ($adjustments->hasPages())
                        <x-slot:footer>
                            <flux:pagination :paginator="$adjustments" />
                        </x-slot:footer>
                    @endif
                </x-ui.table>

                {{-- Phones: one block per adjustment, so the amount and the actions stay in view. --}}
                <ul class="divide-y divide-line border border-line bg-surface sm:hidden" data-test="adjustments-list">
                    @foreach ($adjustments as $adjustment)
                        <li class="px-4 py-4" wire:key="adj-m-{{ $adjustment->id }}">
                            <div class="flex items-start justify-between gap-3">
                                <x-smash.player :user="$adjustment->user" size="sm" class="min-w-0" />
                                <x-ratings.amount :adjustment="$adjustment" size="lg" class="pt-1" />
                            </div>
                            <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1">
                                <x-smash.discipline-badge :discipline="$adjustment->discipline" />
                                <span class="text-sm text-ink-muted">{{ __('From :moment', ['moment' => $adjustment->effective_at->format('D j M Y, H:i')]) }}</span>
                            </div>
                            <p class="mt-2 text-sm leading-relaxed text-ink-soft">{{ $adjustment->reason }}</p>
                            <div class="mt-2 flex items-center justify-between gap-3">
                                <p class="min-w-0 text-[13px] text-ink-muted">
                                    {{ $adjustment->creator?->name ?? __('Committee') }} · {{ $adjustment->created_at?->format('j M Y') }}@if ($adjustment->wasEdited()) · {{ __('edited') }}@endif
                                </p>
                                <div class="-me-2 flex shrink-0">
                                    <x-ui.button variant="ghost" square icon="pencil-square" wire:click="edit({{ $adjustment->id }})" :aria-label="__('Edit adjustment for :name', ['name' => $adjustment->user->name])" />
                                    <x-ui.button variant="ghost" square icon="trash" wire:click="confirmDelete({{ $adjustment->id }})" :aria-label="__('Delete adjustment for :name', ['name' => $adjustment->user->name])" />
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if ($adjustments->hasPages())
                    <div class="mt-4 sm:hidden">
                        <flux:pagination :paginator="$adjustments" />
                    </div>
                @endif
            @endif
        </div>
    </x-ui.section>

    <x-ui.section tone="grey" :title="__('When to use one')" :kicker="__('Committee guide')" :intro="__('Fix the match first if the match is wrong: edit or delete the result and ratings recalculate on their own. Adjustments are for what match results can\'t express.')">
        <div class="grid gap-6 md:grid-cols-3">
            <x-ui.card highlight="green" padding="lg">
                <x-ui.kicker>{{ __('Placement') }}</x-ui.kicker>
                <p class="mt-2 font-serif text-h4 text-heading">{{ __('An experienced newcomer') }}</p>
                <p class="mt-3 text-[15px] leading-relaxed text-ink-soft">{{ __('Someone with years of league badminton shouldn\'t spend ten matches climbing from 1500. Give them a head start that counts from just before their first match.') }}</p>
                <p class="mt-4 text-sm text-ink-muted"><span class="num text-[17px] font-bold text-win">+150</span> · {{ __('singles · before first match') }}</p>
            </x-ui.card>
            <x-ui.card highlight="sky" padding="lg">
                <x-ui.kicker>{{ __('Correction') }}</x-ui.kicker>
                <p class="mt-2 font-serif text-h4 text-heading">{{ __('A result SMASH can\'t log') }}</p>
                <p class="mt-3 text-[15px] leading-relaxed text-ink-soft">{{ __('An inter-university match against a non-member, or a score sheet that went missing. Add the points the committee agrees on, dated to when it happened.') }}</p>
                <p class="mt-4 text-sm text-ink-muted"><span class="num text-[17px] font-bold text-win">+16</span> · {{ __('dated to the match day') }}</p>
            </x-ui.card>
            <x-ui.card highlight="smash" padding="lg">
                <x-ui.kicker>{{ __('Penalty') }}</x-ui.kicker>
                <p class="mt-2 font-serif text-h4 text-heading">{{ __('Points off, agreed by the committee') }}</p>
                <p class="mt-3 text-[15px] leading-relaxed text-ink-soft">{{ __('For example a tournament no-show without a word to anyone. Walkovers are never rated, so this is the only way they cost points.') }}</p>
                <p class="mt-4 text-sm text-ink-muted"><span class="num text-[17px] font-bold text-loss">−15</span> · {{ __('dated to the missed match') }}</p>
            </x-ui.card>
        </div>

        <x-ui.facts class="mt-10" :columns="3" :items="[
            __('What changes') => __('The rating and, if it goes higher, the peak. Opponents in later matches move a little too, because they faced a different rating.'),
            __('What doesn\'t') => __('Matches played, wins and provisional status. A placed newcomer still moves fast for their first ten matches.'),
            __('Undo') => __('Delete the adjustment and every rating goes back to exactly what it would have been without it.'),
        ]" />
    </x-ui.section>

    {{-- Create / edit --}}
    <flux:modal wire:model.self="showForm" name="adjustment-form" class="w-full max-w-2xl">
        <form wire:submit="save" class="space-y-6" data-test="adjustment-form">
            <div>
                <p class="kicker">{{ __('Rating adjustment') }}</p>
                <flux:heading size="xl" class="mt-1">{{ $editingId ? __('Edit adjustment') : __('New adjustment') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Saving replays every rated match with this adjustment in place, so the new ratings show straight away.') }}</flux:text>
            </div>

            <div class="grid gap-5 sm:grid-cols-[minmax(0,1fr)_auto]">
                <flux:select wire:model.live="userId" :label="__('Player')" data-test="form-player">
                    <option value="">{{ __('Choose a player') }}</option>
                    @foreach ($this->players as $option)
                        <option value="{{ $option->id }}" wire:key="pl-{{ $option->id }}">
                            {{ $option->name }} · S {{ $option->rating(Discipline::Singles) }} · D {{ $option->rating(Discipline::Doubles) }}@if (! $option->is_active) · {{ __('inactive') }}@endif
                        </option>
                    @endforeach
                </flux:select>

                <flux:radio.group wire:model.live="formDiscipline" :label="__('Discipline')" variant="segmented">
                    @foreach (Discipline::cases() as $option)
                        <flux:radio :value="$option->value" :label="$option->label()" />
                    @endforeach
                </flux:radio.group>
            </div>

            <div class="grid gap-5 sm:grid-cols-[12rem_minmax(0,1fr)]">
                <flux:input
                    wire:model.live.debounce.400ms="amount"
                    type="number"
                    step="1"
                    min="-{{ RatingAdjustment::MAX_AMOUNT }}"
                    max="{{ RatingAdjustment::MAX_AMOUNT }}"
                    inputmode="numeric"
                    :label="__('Amount')"
                    :placeholder="__('e.g. 150')"
                    :description:trailing="__('Rating points. Minus for points off.')"
                    data-test="form-amount"
                />

                <div class="min-w-0 space-y-3">
                    <flux:radio.group wire:model.live="timing" :label="__('Counts from')" variant="segmented" class="max-sm:[&_ui-radio]:px-2">
                        @foreach (self::TIMINGS as $value => $label)
                            <flux:radio :value="$value" :label="__($label)" />
                        @endforeach
                    </flux:radio.group>

                    @if ($timing === 'custom')
                        <flux:input wire:model.live.debounce.400ms="effectiveAt" type="datetime-local" :label="__('Date and time')" max="{{ now()->format('Y-m-d\TH:i') }}" data-test="form-effective-at" />
                    @else
                        <flux:error name="effectiveAt" />
                    @endif

                    <p class="text-sm leading-relaxed text-ink-muted" data-test="timing-help">
                        @if ($timing === 'now')
                            {{ __('Counts before every match played from now on.') }}
                        @elseif ($timing === 'first')
                            @if ($selected === null)
                                {{ __('Choose a player first: it counts from one minute before their first :discipline match.', ['discipline' => strtolower($chosen->label())]) }}
                            @elseif ($this->firstMatchAt)
                                {{ __('From :moment, one minute before :name\'s first :discipline match.', ['moment' => $moment?->format('D j M Y, H:i'), 'name' => $selected->firstName(), 'discipline' => strtolower($chosen->label())]) }}
                            @else
                                {{ __(':name hasn\'t played :discipline yet, so it counts from the season start (:date), before every match they play.', ['name' => $selected->firstName(), 'discipline' => strtolower($chosen->label()), 'date' => $moment?->format('j M Y')]) }}
                            @endif
                        @elseif ($moment)
                            {{ __('Counts before every match played from :moment on.', ['moment' => $moment->format('D j M Y, H:i')]) }}
                        @endif
                    </p>
                </div>
            </div>

            <flux:textarea
                wire:model.blur="reason"
                rows="2"
                maxlength="{{ RatingAdjustment::REASON_LENGTH }}"
                :label="__('Reason')"
                :placeholder="__('e.g. Placement: four seasons of league singles at BC Leuven before joining SMASH.')"
                :description="__('Shown in the log. Say what happened, so the committee still understands it next season.')"
                data-test="form-reason"
            />

            {{-- What this does --}}
            <div class="border border-line bg-canvas-muted p-5" data-test="adjustment-preview" aria-live="polite">
                <p class="label-caps text-ink-muted">{{ __('What this does') }}</p>
                @if ($preview && $selected)
                    <div class="mt-3 flex flex-wrap items-end gap-x-6 gap-y-3">
                        <div>
                            <p class="text-sm text-ink-soft">{{ $selected->name }} · {{ strtolower($chosen->label()) }}</p>
                            <div class="mt-1 flex items-center gap-3">
                                <x-smash.rating :value="$preview['current']" size="lg" muted :provisional="false" />
                                <flux:icon.arrow-long-right class="size-6 text-ink-muted" aria-hidden="true" />
                                <x-smash.rating :value="$preview['after']" size="lg" :provisional="false" />
                                <span class="sr-only">{{ __('from :from to :to', ['from' => (int) round($preview['current']), 'to' => (int) round($preview['after'])]) }}</span>
                            </div>
                        </div>
                        <x-smash.rating-delta :value="$preview['after'] - $preview['current']" variant="chip" size="lg" :label="__('Change')" />
                    </div>
                    <ul class="mt-4 space-y-1 text-sm leading-relaxed text-ink-soft">
                        <li>
                            @if ($editingId && abs($preview['after'] - $preview['current']) < 0.5)
                                {{ __('As it stands nothing changes. Edit the amount, player or date to see the effect here.') }}
                            @elseif ($preview['later'] > 0)
                                {{ trans_choice('Their :count later :discipline match is re-rated, so the final change can differ from the amount.|Their :count later :discipline matches are re-rated, so the final change can differ from the amount.', $preview['later'], ['discipline' => strtolower($chosen->label())]) }}
                            @else
                                {{ __('No later :discipline matches to re-rate: the rating moves by exactly the amount.', ['discipline' => strtolower($chosen->label())]) }}
                            @endif
                        </li>
                        @if ($preview['others'] > 0)
                            <li>{{ trans_choice('One other player moves by up to :max points.|:count other players move by up to :max points each.', $preview['others'], ['max' => max(1, (int) round($preview['othersMax']))]) }}</li>
                        @endif
                        @if ($preview['played'] < config('smash.rating.provisional_matches'))
                            <li>{{ __('Still provisional: :played of :needed rated :discipline matches played.', ['played' => $preview['played'], 'needed' => config('smash.rating.provisional_matches'), 'discipline' => strtolower($chosen->label())]) }}</li>
                        @endif
                    </ul>
                @else
                    <p class="mt-2 text-sm text-ink-soft">{{ __('Choose a player and an amount to see their rating before and after.') }}</p>
                @endif
            </div>

            <div class="flex flex-wrap justify-end gap-2 border-t border-line pt-5">
                <flux:modal.close>
                    <x-ui.button variant="ghost">{{ __('Cancel') }}</x-ui.button>
                </flux:modal.close>
                <x-ui.button variant="cta" type="submit" data-test="save-adjustment">{{ $editingId ? __('Save changes') : __('Save adjustment') }}</x-ui.button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete confirmation --}}
    <flux:modal wire:model.self="showDelete" name="delete-adjustment" class="w-full md:w-[30rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this adjustment?') }}</flux:heading>
                @if ($deleting = $this->deletePreview)
                    <flux:text class="mt-2">
                        {{ __('The :amount :discipline adjustment for :name goes, and every match is replayed without it: :name moves from :current to :after.', [
                            'amount' => $deleting['amount'],
                            'discipline' => strtolower($deleting['discipline']->label()),
                            'name' => $deleting['name'],
                            'current' => (int) round($deleting['current']),
                            'after' => (int) round($deleting['after']),
                        ]) }}
                    </flux:text>
                @endif
                <flux:text class="mt-2">{{ __('The log entry is removed for good. To change the amount or date instead, edit it.') }}</flux:text>
            </div>
            <div class="flex flex-wrap justify-end gap-2">
                <flux:modal.close>
                    <x-ui.button variant="ghost" size="sm">{{ __('Keep it') }}</x-ui.button>
                </flux:modal.close>
                <x-ui.button variant="danger" size="sm" wire:click="delete" data-test="confirm-delete">{{ __('Delete adjustment') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</div>
