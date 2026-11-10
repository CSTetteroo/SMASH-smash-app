<?php

namespace App\Console\Commands;

use App\Enums\Discipline;
use App\Enums\MatchStatus;
use App\Models\BadmintonMatch;
use App\Models\User;
use App\Services\Rating\RatingEngine;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('smash:recalculate-ratings')]
#[Description('Replay every completed, rated match and rebuild all singles and doubles ratings')]
class RecalculateRatings extends Command
{
    public function handle(): int
    {
        $started = hrtime(true);

        RatingEngine::recalculate();

        $milliseconds = (int) round((hrtime(true) - $started) / 1_000_000);
        $matches = BadmintonMatch::query()->rated()->where('status', MatchStatus::Completed->value)->count();
        $players = User::query()
            ->where(fn ($query) => $query
                ->where(Discipline::Singles->playedColumn(), '>', 0)
                ->orWhere(Discipline::Doubles->playedColumn(), '>', 0))
            ->count();

        $this->components->info(sprintf(
            'Ratings recalculated from %d rated %s for %d %s in %d ms.',
            $matches,
            $matches === 1 ? 'match' : 'matches',
            $players,
            $players === 1 ? 'player' : 'players',
            $milliseconds,
        ));

        return self::SUCCESS;
    }
}
