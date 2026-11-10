<?php

use App\Actions\ClubNights\JoinClubNight;
use App\Actions\ClubNights\LeaveClubNight;
use App\Enums\ClubNightKind;
use App\Enums\MatchStatus;
use App\Enums\RsvpStatus;
use App\Models\BadmintonMatch;
use App\Models\ClubNight;
use App\Models\User;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Club nights')] class extends Component {
    use WithPagination;

    /** Kind filter for the upcoming nights ('' = all kinds). */
    #[Url(except: '')]
    public string $kind = '';

    /** Weeks of upcoming nights on show, this week included; "Show later nights" adds more. */
    public int $weeks = 3;

    /** The night a member is about to give up a place for (leave confirmation modal). */
    public ?int $leaving = null;

    public function mount(): void
    {
        $this->normaliseKind();
    }

    public function updatedKind(): void
    {
        $this->normaliseKind();
    }

    public function showLater(): void
    {
        $this->weeks = min($this->weeks + 4, 52);
    }

    #[Computed]
    public function kindFilter(): ?ClubNightKind
    {
        return ClubNightKind::tryFrom($this->kind);
    }

    /**
     * Start of the first week that is not shown yet.
     */
    #[Computed]
    public function horizon(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfWeek()->addWeeks(max(1, min($this->weeks, 52)));
    }

    /**
     * Upcoming nights within the horizon, with everything the cards need (no queries per card).
     *
     * @return Collection<int, ClubNight>
     */
    #[Computed]
    public function upcoming(): Collection
    {
        return $this->upcomingQuery()
            ->where('starts_at', '<', $this->horizon)
            ->withRsvpCounts()
            ->with([
                'rsvps' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
                'rsvps.user',
            ])
            ->get();
    }

    /**
     * The upcoming nights grouped by week: [['label' => 'This week', 'range' => '2–8 November', 'nights' => …], …].
     *
     * @return list<array{key: string, label: string, range: string, nights: \Illuminate\Support\Collection<int, ClubNight>}>
     */
    #[Computed]
    public function weekGroups(): array
    {
        $thisWeek = CarbonImmutable::now()->startOfWeek();

        return $this->upcoming
            ->groupBy(fn (ClubNight $night) => $night->starts_at->startOfWeek()->toDateString())
            ->map(function ($nights, string $key) use ($thisWeek) {
                $start = CarbonImmutable::parse($key);
                $end = $start->endOfWeek();

                $label = match (true) {
                    $start->lessThanOrEqualTo($thisWeek) => __('This week'),
                    $start->equalTo($thisWeek->addWeek()) => __('Next week'),
                    default => __('Week of :date', ['date' => $start->format('j F')]),
                };

                $range = $start->month === $end->month
                    ? $start->format('j').'–'.$end->format('j F')
                    : $start->format('j M').' – '.$end->format('j M');

                return ['key' => $key, 'label' => $label, 'range' => $range, 'nights' => $nights->values()];
            })
            ->values()
            ->all();
    }

    /**
     * Upcoming nights beyond the horizon (for the "Show later nights" button).
     */
    #[Computed]
    public function laterCount(): int
    {
        return $this->upcomingQuery()->where('starts_at', '>=', $this->horizon)->count();
    }

    /**
     * Upcoming nights per kind, for the filter counts.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function kindCounts(): array
    {
        return ClubNight::query()
            ->where('ends_at', '>=', now())
            ->selectRaw('kind, count(*) as aggregate')
            ->groupBy('kind')
            ->pluck('aggregate', 'kind')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * The next night (any kind) for the hero card.
     */
    #[Computed]
    public function next(): ?ClubNight
    {
        return ClubNight::query()
            ->upcoming()
            ->withRsvpCounts()
            ->with([
                'rsvps' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
                'rsvps.user',
            ])
            ->first();
    }

    /**
     * Past nights with attendance (check-ins), RSVPs and friendlies played during the night.
     *
     * @return LengthAwarePaginator<int, ClubNight>
     */
    #[Computed]
    public function past(): LengthAwarePaginator
    {
        return ClubNight::query()
            ->past()
            ->select('club_nights.*')
            ->withCount([
                'rsvps as going_count' => fn (Builder $query) => $query->where('status', RsvpStatus::Going->value),
                'rsvps as checked_in_count' => fn (Builder $query) => $query->whereNotNull('checked_in_at'),
            ])
            ->addSelect(['matches_count' => BadmintonMatch::query()
                ->selectRaw('count(*)')
                ->whereNull('matches.tournament_id')
                ->whereIn('matches.status', [MatchStatus::Completed->value, MatchStatus::Walkover->value])
                ->whereColumn('matches.played_at', '>=', 'club_nights.starts_at')
                ->whereColumn('matches.played_at', '<=', 'club_nights.ends_at'),
            ])
            ->paginate(10, pageName: 'past');
    }

    /**
     * The night in the leave confirmation modal.
     */
    #[Computed]
    public function leavingNight(): ?ClubNight
    {
        return $this->leaving === null ? null : ClubNight::query()->withRsvpCounts()->find($this->leaving);
    }

    public function join(int $nightId): void
    {
        $night = ClubNight::query()->find($nightId);

        if ($night === null) {
            Flux::toast(variant: 'danger', text: __('That club night no longer exists.'));

            return;
        }

        $user = Auth::user();

        if (! $user instanceof User) {
            // Come back to this night after logging in.
            redirect()->setIntendedUrl(route('club-nights.show', $night));
            $this->redirectRoute('login');

            return;
        }

        if (! $user->hasVerifiedEmail()) {
            Flux::toast(variant: 'warning', heading: __('Verify your email first'), text: __('Use the link we emailed you, then RSVP. You can request a new link on the verification page.'));

            return;
        }

        if (Gate::forUser($user)->denies('rsvp', $night)) {
            Flux::toast(variant: 'danger', text: __(':title has already finished, so RSVPs are closed.', ['title' => $night->title]));

            return;
        }

        try {
            $rsvp = JoinClubNight::handle($night, $user);
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());

            return;
        }

        if ($rsvp->isGoing()) {
            Flux::toast(variant: 'success', heading: __('You\'re going'), text: __('See you at :title on :date.', ['title' => $night->title, 'date' => $night->starts_at->format('D j M, H:i')]));
        } else {
            Flux::toast(variant: 'warning', heading: __('You\'re on the waitlist'), text: __(':title is full. You\'re #:position in line and move up automatically when a place frees up.', ['title' => $night->title, 'position' => $night->waitlistPosition($user) ?? 1]));
        }

        $this->refreshNights();
    }

    /**
     * Ask before giving up a place that others are waiting for.
     */
    public function confirmLeave(int $nightId): void
    {
        $this->leaving = $nightId;
        unset($this->leavingNight);

        Flux::modal('leave-night')->show();
    }

    public function leave(int $nightId): void
    {
        $night = ClubNight::query()->withRsvpCounts()->find($nightId);
        $user = Auth::user();

        $this->leaving = null;
        Flux::modal('leave-night')->close();

        if ($night === null || ! $user instanceof User) {
            return;
        }

        if (Gate::forUser($user)->denies('rsvp', $night)) {
            Flux::toast(variant: 'danger', text: __(':title has already finished, so RSVPs are closed.', ['title' => $night->title]));

            return;
        }

        $rsvp = $night->rsvpFor($user);

        if ($rsvp === null) {
            Flux::toast(text: __('You hadn\'t RSVP\'d for :title.', ['title' => $night->title]));

            return;
        }

        $handedOn = $rsvp->isGoing() && $night->waitlistCount() > 0;

        try {
            LeaveClubNight::handle($night, $user);
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());

            return;
        }

        Flux::toast(text: match (true) {
            $rsvp->isWaitlisted() => __('You\'re off the waitlist for :title.', ['title' => $night->title]),
            $handedOn => __('You\'ve left :title. Your place went to the first person on the waitlist.', ['title' => $night->title]),
            default => __('You\'ve left :title. Changed your mind? RSVP again while there are places.', ['title' => $night->title]),
        });

        $this->refreshNights();
    }

    /**
     * @return Builder<ClubNight>
     */
    private function upcomingQuery(): Builder
    {
        return ClubNight::query()
            ->upcoming()
            ->when($this->kindFilter, fn (Builder $query, ClubNightKind $kind) => $query->where('kind', $kind->value));
    }

    private function refreshNights(): void
    {
        unset($this->upcoming, $this->weekGroups, $this->next, $this->leavingNight);
    }

    private function normaliseKind(): void
    {
        if (ClubNightKind::tryFrom($this->kind) === null) {
            $this->kind = '';
        }

        unset($this->kindFilter);
    }
}; ?>

@php
    $next = $this->next;
    $user = auth()->user();
    $seasonStart = now()->month >= 8 ? now()->year : now()->year - 1;
    $feedUrl = route('club-nights.ics', array_filter(['kind' => $this->kindFilter?->value]));
    $allFeedUrl = route('club-nights.ics');
@endphp

<div>
    <x-ui.page-hero
        :breadcrumbs="[__('Club nights')]"
        :kicker="__('Season :season', ['season' => $seasonStart.'–'.substr((string) ($seasonStart + 1), -2)])"
        :title="__('Club nights')"
        :intro="__('Tuesday and Thursday evenings in the :venue, plus the odd Sunday clinic. Every level is welcome: come for social play, sharpen up at training or climb the ladder.', ['venue' => config('smash.club.venue')])"
        court
    >
        <x-slot:actions>
            <x-ui.button variant="outline" icon="calendar-days" :href="$allFeedUrl" :navigate="false">{{ __('Add to your calendar') }}</x-ui.button>
            @can('manage')
                <x-ui.button variant="primary" icon="plus" :href="route('club-nights.create')">{{ __('New club night') }}</x-ui.button>
            @endcan
        </x-slot:actions>

        @if ($next)
            <x-slot:aside>
                <div class="flex items-start gap-5" data-test="next-club-night">
                    <x-ui.date-badge :date="$next->starts_at" weekday size="lg" />
                    <div class="min-w-0">
                        <p class="kicker">
                            @if ($next->isOngoing())
                                {{ __('On now') }}
                            @elseif ($next->starts_at->isToday())
                                {{ __('Tonight') }}
                            @else
                                {{ __('Next club night') }}
                            @endif
                        </p>
                        <h2 class="mt-1 font-serif text-h3 leading-tight text-heading">
                            <a href="{{ route('club-nights.show', $next) }}" wire:navigate class="decoration-1 underline-offset-4 hover:underline">{{ $next->title }}</a>
                        </h2>
                        <div class="mt-3 space-y-1.5">
                            <x-ui.meta icon="clock">{{ $next->starts_at->format('l') }} · {{ $next->starts_at->format('H:i') }} – {{ $next->ends_at->format('H:i') }}</x-ui.meta>
                            <x-ui.meta icon="map-pin">{{ $next->venue }} · {{ trans_choice(':count court|:count courts', $next->courts) }}</x-ui.meta>
                        </div>
                    </div>
                </div>

                <div class="mt-5 flex flex-wrap items-center gap-2">
                    @if ($next->isOngoing())
                        <x-ui.live variant="solid" :label="__('On now')" />
                    @endif
                    <x-smash.status-badge :status="$next->kind" />
                    <x-club-nights.level :level="$next->level" />
                </div>

                <x-ui.progress
                    class="mt-6"
                    :value="$next->goingCount()"
                    :max="$next->capacity"
                    capacity
                    :label="__('Places taken')"
                    :value-label="$next->isFull() ? ($next->waitlistCount() > 0 ? __('Full · :count waiting', ['count' => $next->waitlistCount()]) : __('Full')) : $next->goingCount().' / '.$next->capacity"
                />

                <x-club-nights.attendees class="mt-4" :users="$next->rsvps->filter->isGoing()" :total="$next->goingCount()" :max="8" />

                <div class="mt-6 border-t border-line pt-5">
                    <x-club-nights.rsvp :night="$next" prominent explain />
                </div>

                <div class="mt-5">
                    <x-ui.link :href="route('club-nights.show', $next)">{{ __('Who\'s going') }}</x-ui.link>
                </div>
            </x-slot:aside>
        @endif
    </x-ui.page-hero>

    {{-- ============================================================== UPCOMING --}}
    <x-ui.section id="upcoming" :kicker="__('RSVP now')" :title="__('Upcoming club nights')">
        <x-slot:actions>
            <x-ui.link :href="$feedUrl" :navigate="false" variant="promo">{{ $this->kindFilter ? __('Subscribe to :kind nights', ['kind' => mb_strtolower($this->kindFilter->label())]) : __('Subscribe to the calendar') }}</x-ui.link>
        </x-slot:actions>

        <x-ui.tabs :label="__('Kind of club night')" variant="pills" class="mb-8">
            <x-ui.tabs.item wire:click="$set('kind', '')" :current="$kind === ''" :count="array_sum($this->kindCounts)">{{ __('All nights') }}</x-ui.tabs.item>
            @foreach (ClubNightKind::cases() as $option)
                <x-ui.tabs.item wire:click="$set('kind', '{{ $option->value }}')" :current="$kind === $option->value" :count="$this->kindCounts[$option->value] ?? 0" wire:key="kind-{{ $option->value }}">{{ $option->label() }}</x-ui.tabs.item>
            @endforeach
        </x-ui.tabs>

        <div wire:loading.class="opacity-60" wire:target="kind, showLater" class="transition-opacity">
            @forelse ($this->weekGroups as $group)
                <section @class(['mt-12' => ! $loop->first]) aria-labelledby="week-{{ $group['key'] }}" wire:key="week-{{ $group['key'] }}">
                    <div class="mb-6 flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 border-b border-line pb-3">
                        <h3 id="week-{{ $group['key'] }}" class="font-serif text-h4 text-heading">{{ $group['label'] }}</h3>
                        <p class="label-caps text-ink-muted">{{ $group['range'] }}</p>
                    </div>

                    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($group['nights'] as $night)
                            <x-club-nights.card :night="$night" wire:key="night-{{ $night->id }}" />
                        @endforeach
                    </div>
                </section>
            @empty
                @if ($this->kindFilter)
                    <x-ui.empty :title="__('No :kind nights coming up', ['kind' => mb_strtolower($this->kindFilter->label())])">
                        {{ $this->laterCount > 0
                            ? __('The next one is a little further ahead.')
                            : __('The committee hasn\'t planned one yet. The other kinds of club night are open to everyone in the meantime.') }}
                        <x-slot:actions>
                            @if ($this->laterCount > 0)
                                <x-ui.button variant="primary" size="sm" wire:click="showLater">{{ __('Show later nights') }}</x-ui.button>
                            @endif
                            <x-ui.button variant="outline" size="sm" wire:click="$set('kind', '')">{{ __('Show all club nights') }}</x-ui.button>
                        </x-slot:actions>
                    </x-ui.empty>
                @else
                    <x-ui.empty :title="__('No club nights planned yet')">
                        {{ $this->laterCount > 0
                            ? __('Nothing in the next few weeks, but there are nights planned further ahead.')
                            : __('The committee posts the schedule a few weeks ahead. Check back soon, or subscribe to the calendar and new nights appear automatically.') }}
                        <x-slot:actions>
                            @if ($this->laterCount > 0)
                                <x-ui.button variant="primary" size="sm" wire:click="showLater">{{ __('Show later nights') }}</x-ui.button>
                            @endif
                            @can('manage')
                                <x-ui.button variant="outline" size="sm" icon="plus" :href="route('club-nights.create')">{{ __('New club night') }}</x-ui.button>
                            @endcan
                        </x-slot:actions>
                    </x-ui.empty>
                @endif
            @endforelse

            @if ($this->upcoming->isNotEmpty() && $this->laterCount > 0)
                <div class="mt-10 flex justify-center">
                    <x-ui.button variant="outline" icon="chevron-down" wire:click="showLater">
                        {{ trans_choice('Show :count later night|Show :count later nights', $this->laterCount) }}
                    </x-ui.button>
                </div>
            @endif
        </div>
    </x-ui.section>

    {{-- ============================================================== HOW IT WORKS --}}
    <x-ui.section id="how-it-works" tone="grey" :kicker="__('New to SMASH?')" :title="__('How club nights work')" :intro="__('Four kinds of evening, one simple rule: RSVP so we know how many courts to set up.')">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div class="grid gap-6 sm:grid-cols-2">
                @foreach (ClubNightKind::cases() as $option)
                    <x-ui.card :highlight="$option->tone()" padding="md" wire:key="kind-card-{{ $option->value }}">
                        <h3 class="font-serif text-h5 text-heading">{{ $option->label() }}</h3>
                        <p class="mt-2 text-[15px] leading-relaxed text-ink-soft">{{ $option->description() }}</p>
                        <button type="button" wire:click="$set('kind', '{{ $option->value }}')" x-on:click="document.getElementById('upcoming')?.scrollIntoView({ behavior: 'smooth' })" class="mt-auto inline-flex min-h-11 items-center gap-2 self-start pt-3 text-[13px] font-semibold uppercase tracking-[0.1em] text-link hover:text-link-hover">
                            {{ trans_choice('{0} None planned yet|{1} :count coming up|[2,*] :count coming up', $this->kindCounts[$option->value] ?? 0) }}
                        </button>
                    </x-ui.card>
                @endforeach
            </div>

            <div class="grid content-start gap-6">
                <x-ui.card padding="md">
                    <h3 class="font-serif text-h5 text-heading">{{ __('Good to know') }}</h3>
                    <ul class="mt-4 space-y-3">
                        <x-ui.meta as="li" icon="check">{{ __('RSVP before you come. Can\'t make it after all? Leave, so someone else can have your place.') }}</x-ui.meta>
                        <x-ui.meta as="li" icon="clock">{{ __('Full? Join the waitlist. When someone cancels, the first person waiting moves up automatically.') }}</x-ui.meta>
                        <x-ui.meta as="li" icon="user-group">{{ __('Bring indoor sports shoes with non-marking soles, and your racket if you have one.') }}</x-ui.meta>
                        <x-ui.meta as="li" icon="chart-bar">{{ __('Friendlies you play count towards the singles and doubles rankings once they\'re logged.') }}</x-ui.meta>
                    </ul>
                </x-ui.card>

                <x-ui.card padding="md" x-data="{ copied: false, copy() { const input = this.$refs.feed; input.select(); if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(input.value) } else { document.execCommand('copy') } this.copied = true; setTimeout(() => this.copied = false, 2000) } }">
                    <h3 class="font-serif text-h5 text-heading">{{ __('Add to your calendar') }}</h3>
                    <p class="mt-2 text-[15px] leading-relaxed text-ink-soft">{{ __('Subscribe with this link in Google Calendar, Outlook or Apple Calendar and new club nights appear automatically.') }}</p>
                    <div class="mt-4 flex items-stretch">
                        <label for="club-night-feed" class="sr-only">{{ __('Calendar feed link') }}</label>
                        <input id="club-night-feed" x-ref="feed" type="text" readonly value="{{ $allFeedUrl }}" class="min-w-0 flex-1 border border-e-0 border-line-strong bg-surface-muted px-3 font-mono text-[13px] text-ink" x-on:focus="$event.target.select()">
                        <x-ui.button variant="primary" size="sm" icon="clipboard-document" x-on:click="copy()" class="min-h-11">
                            <span x-show="! copied">{{ __('Copy') }}</span>
                            <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                        </x-ui.button>
                    </div>
                    <x-ui.link class="mt-4" :href="$allFeedUrl" :navigate="false" variant="promo">{{ __('Download the .ics file') }}</x-ui.link>
                </x-ui.card>
            </div>
        </div>
    </x-ui.section>

    {{-- ============================================================== PAST --}}
    <x-ui.section id="past" :kicker="__('Looking back')" :title="__('Past club nights')">
        @if ($this->past->isEmpty())
            <x-ui.empty :title="__('No club nights yet this season')">{{ __('Attendance and the matches played will show up here after the first night.') }}</x-ui.empty>
        @else
            <x-ui.table>
                <table>
                    <thead>
                        <tr>
                            <th class="w-32 max-sm:hidden">{{ __('Date') }}</th>
                            <th>{{ __('Club night') }}</th>
                            <th class="text-end">{{ __('Attended') }}</th>
                            <th class="text-end max-sm:hidden">{{ __('RSVPs') }}</th>
                            <th class="text-end max-sm:hidden">{{ __('Matches') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->past as $night)
                            <tr wire:key="past-{{ $night->id }}">
                                <td class="whitespace-nowrap max-sm:hidden">
                                    <span class="block font-semibold text-ink">{{ $night->starts_at->format('D j M') }}</span>
                                    <span class="block text-sm text-ink-muted">{{ $night->starts_at->format('H:i') }} – {{ $night->ends_at->format('H:i') }}</span>
                                </td>
                                <td class="sm:min-w-48">
                                    <span class="label-caps mb-1 block text-ink-muted sm:hidden">{{ $night->starts_at->format('D j M') }} · {{ $night->starts_at->format('H:i') }}</span>
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                                        <a href="{{ route('club-nights.show', $night) }}" wire:navigate class="font-semibold text-ink decoration-1 underline-offset-4 hover:text-link hover:underline">{{ $night->title }}</a>
                                        <x-smash.status-badge :status="$night->kind" size="sm" />
                                    </div>
                                </td>
                                <td class="text-end">
                                    @if ($night->checked_in_count > 0)
                                        <span class="num text-[17px] font-semibold text-ink">{{ $night->checked_in_count }}</span>
                                    @else
                                        <span class="num text-[17px] text-ink-muted" title="{{ __('No check-ins recorded') }}">–</span>
                                    @endif
                                </td>
                                <td class="num text-end text-[17px] font-semibold text-ink max-sm:hidden">{{ $night->going_count }}</td>
                                <td class="num text-end text-[17px] font-semibold text-ink max-sm:hidden">{{ $night->matches_count ?: '–' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if ($this->past->hasPages())
                    <x-slot:footer>
                        <flux:pagination :paginator="$this->past" />
                    </x-slot:footer>
                @endif
            </x-ui.table>
        @endif
    </x-ui.section>

    {{-- ============================================================== LEAVE CONFIRMATION --}}
    <flux:modal name="leave-night" class="md:w-[30rem]" x-on:close="$wire.set('leaving', null)">
        @if ($night = $this->leavingNight)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Give up your place at :title?', ['title' => $night->title]) }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ trans_choice(':count person is on the waitlist and gets your place straight away.|:count people are on the waitlist. The first of them gets your place straight away.', $night->waitlistCount()) }}
                        {{ __('If you change your mind, you\'d join the back of the waitlist.') }}
                    </flux:text>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <flux:modal.close>
                        <x-ui.button variant="ghost" size="sm">{{ __('Keep my place') }}</x-ui.button>
                    </flux:modal.close>
                    <x-ui.button variant="danger" size="sm" wire:click="leave({{ $night->id }})">{{ __('Leave') }}</x-ui.button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
