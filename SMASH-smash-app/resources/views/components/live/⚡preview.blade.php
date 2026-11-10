<?php

use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Support\Live\LiveScore;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 | Local-only, admin-only, read-only preview of the umpire console in a given scoring situation,
 | built in memory on top of a real match (players, format). Nothing is saved, so the console can
 | be reviewed without scoring a match in the dev database. Route: dev.live-preview
 | (/_dev/live-preview/{match}/{scenario}?swapped=1).
 */
new #[Title('Umpire console preview')] class extends Component {
    public const SCENARIOS = ['start', 'serve', 'interval', 'game', 'rally', 'setting', 'golden', 'change-ends', 'match-point', 'decided'];

    /**
     * Rallies from 0–0 in game 1 with side A serving first ("a" = point to A).
     */
    public static function rallies(string $scenario): string
    {
        // Play a game from 0–0 to $a–$b, alternating so it cannot end on the way.
        $to = fn (int $a, int $b): string => str_repeat('ab', min($a, $b)).str_repeat('a', max(0, $a - $b)).str_repeat('b', max(0, $b - $a));
        $first = $to(21, 17);
        $second = $first.$to(18, 21);

        return match ($scenario) {
            'interval' => $to(11, 7),
            'game' => $to(21, 19),
            'rally' => $first.$to(14, 12),
            'setting' => $first.$to(21, 21),
            'golden' => $first.$to(29, 29),
            'change-ends' => $second.$to(11, 8),
            'match-point' => $second.$to(20, 18),
            'decided' => $second.$to(21, 18),
            default => '',
        };
    }

    #[Locked]
    public int $matchId;

    #[Locked]
    public string $scenario = 'rally';

    public bool $swapped = false;

    public string $firstServer = '';

    public function mount(BadmintonMatch $match, string $scenario = 'rally'): void
    {
        abort_unless(app()->environment('local', 'testing'), 404);
        Gate::authorize('manage');
        abort_unless(in_array($scenario, self::SCENARIOS, true), 404);

        $this->matchId = $match->id;
        $this->scenario = $scenario;
        $this->swapped = request()->boolean('swapped');
    }

    #[Computed]
    public function match(): BadmintonMatch
    {
        return BadmintonMatch::query()
            ->with(['participants.user', 'games', 'tournament', 'entryA.player', 'entryA.partner', 'entryB.player', 'entryB.partner'])
            ->findOrFail($this->matchId);
    }

    #[Computed]
    public function state(): ?LiveScore
    {
        $match = $this->match;

        try {
            return match ($this->scenario) {
                'start' => null,
                'serve' => LiveScore::resume([['a' => 21, 'b' => 14], ['a' => 7, 'b' => 12]], $match->best_of, $match->points_per_game),
                default => array_reduce(
                    str_split(self::rallies($this->scenario)),
                    fn (LiveScore $state, string $winner): LiveScore => $state->point(Side::from($winner)),
                    LiveScore::start($match->best_of, $match->points_per_game)->withServer(Side::A),
                ),
            };
        } catch (\Throwable) {
            abort(404, 'This scenario does not fit the format of this match.');
        }
    }

    #[Computed]
    public function phase(): string
    {
        return match ($this->scenario) {
            'start' => 'start',
            'serve' => 'serve',
            'decided' => 'decided',
            default => 'rally',
        };
    }

    public function swap(): void
    {
        $this->swapped = ! $this->swapped;
    }

    /*
     | The console's actions answer with a toast and change nothing, so taps and keyboard
     | shortcuts can be tried out here safely.
     */
    public function start(): void
    {
        $this->previewOnly('start');
    }

    public function point(string $side): void
    {
        $this->previewOnly('point '.(Side::tryFrom($side)?->value ?? '?'));
    }

    public function serve(string $side): void
    {
        $this->previewOnly('serve '.(Side::tryFrom($side)?->value ?? '?'));
    }

    public function undo(): void
    {
        $this->previewOnly('undo');
    }

    public function finish(): void
    {
        $this->previewOnly('finish');
    }

    private function previewOnly(string $action): void
    {
        Flux::toast(heading: __('Preview only'), text: __('":action" would run here; nothing is saved.', ['action' => $action]));
    }
}; ?>

<div>
    <div class="bg-orange-100 py-2 text-sm text-orange-900 dark:bg-orange-950 dark:text-orange-100">
        <div class="container-site flex flex-wrap items-center gap-x-4 gap-y-1">
            <span class="label-caps">{{ __('Preview') }}</span>
            @foreach ($this::SCENARIOS as $name)
                <a href="{{ route('dev.live-preview', ['match' => $matchId, 'scenario' => $name]) }}" @class(['underline-offset-2 hover:underline', 'font-semibold underline' => $name === $scenario]) wire:key="sc-{{ $name }}">{{ $name }}</a>
            @endforeach
        </div>
    </div>

    <x-live.header :match="$this->match" />

    <x-live.console
        :match="$this->match"
        :state="$this->state"
        :phase="$this->phase"
        :swapped="$swapped"
    />

    <x-live.details :match="$this->match" :state="$this->state" :phase="$this->phase" />

    <x-live.confirm :match="$this->match" :state="$this->phase === 'decided' ? $this->state : null" />
</div>
