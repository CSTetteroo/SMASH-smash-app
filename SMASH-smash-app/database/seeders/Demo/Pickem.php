<?php

namespace Database\Seeders\Demo;

use App\Actions\Predictions\PlacePrediction;
use App\Enums\MatchStatus;
use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\MatchPlayer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pick'em: about thirty members predict announced matches (challenge matches and tournament
 * matches once their line-up is known). Each predictor has an activity level and a sharpness
 * (how often they back the true favourite), so the leaderboard has sharp, average and hopeless
 * pickers. Predictions on matches that have started are inserted as rows dated before the start
 * (PlacePrediction refuses those); predictions on open matches go through PlacePrediction.
 */
final class Pickem
{
    public function __construct(private readonly World $world) {}

    /**
     * Predictions on announced matches that have been played, called off or awarded as a walkover.
     */
    public function history(): void
    {
        $matches = BadmintonMatch::query()
            ->whereKey(array_keys($this->world->announced))
            ->whereIn('status', [MatchStatus::Completed->value, MatchStatus::Walkover->value, MatchStatus::Cancelled->value])
            ->with(['participants', 'tournament'])
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($matches as $match) {
            $start = $match->scheduled_at ?? $match->played_at;

            if ($start === null) {
                continue;
            }

            foreach ($this->picks($match, $this->world->announced[$match->id], $start->subMinutes(3), false) as [$userId, $side, $loserGames, $at]) {
                $rows[] = [
                    'user_id' => $userId,
                    'match_id' => $match->id,
                    'predicted_side' => $side->value,
                    'predicted_loser_games' => $loserGames,
                    'created_at' => $at,
                    'updated_at' => $at,
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['created_at']->getTimestamp(), $a['match_id']] <=> [$b['created_at']->getTimestamp(), $b['match_id']]);

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('predictions')->insert(array_map(fn (array $row): array => [
                ...$row,
                'created_at' => $row['created_at']->toDateTimeString(),
                'updated_at' => $row['updated_at']->toDateTimeString(),
            ], $chunk));
        }
    }

    /**
     * Predictions on every announced match that has not started yet, placed through
     * PlacePrediction at the moment they were made.
     */
    public function open(): void
    {
        $now = $this->world->calendar->now;

        $matches = BadmintonMatch::query()
            ->whereKey(array_keys($this->world->announced))
            ->where('status', MatchStatus::Scheduled->value)
            ->where('scheduled_at', '>', $now)
            ->with(['participants', 'tournament'])
            ->orderBy('id')
            ->get();

        foreach ($matches as $match) {
            $this->place($match, $this->world->announced[$match->id], $now->subMinutes(20));
        }
    }

    /**
     * Place predictions on one scheduled match between $from and $until (both in the past).
     */
    public function place(BadmintonMatch $match, CarbonImmutable $from, CarbonImmutable $until): void
    {
        $match->loadMissing(['participants', 'tournament']);
        $picks = $this->picks($match, $from, $until, true);

        usort($picks, fn (array $a, array $b): int => $a[3]->getTimestamp() <=> $b[3]->getTimestamp());

        foreach ($picks as [$userId, $side, $loserGames, $at]) {
            $this->world->travel($at);
            PlacePrediction::handle($this->world->userById($userId), $match, $side, $loserGames);
        }
    }

    /**
     * Who predicts this match, what they pick and when: [user id, side, loser games, moment].
     *
     * @return list<array{0: int, 1: Side, 2: int|null, 3: CarbonImmutable}>
     */
    private function picks(BadmintonMatch $match, CarbonImmutable $from, CarbonImmutable $until, bool $open): array
    {
        $rng = $this->world->picks;
        $sideA = [];
        $sideB = [];

        foreach ($match->participants as $participant) {
            /** @var MatchPlayer $participant */
            if ($participant->side === Side::A) {
                $sideA[] = $participant->user_id;
            } else {
                $sideB[] = $participant->user_id;
            }
        }

        if ($sideA === [] || $sideB === []) {
            return [];
        }

        $probability = $this->world->probability($sideA, $sideB, $match->discipline);
        $favourite = $probability >= 0.5 ? Side::A : Side::B;
        $favouriteChance = max($probability, 1.0 - $probability);
        $interest = $this->interest($match);
        $week = $this->world->calendar->weekOf($from);
        $picks = [];

        foreach ($this->world->profiles as $player) {
            $activity = match (true) {
                $open && $player->key === 'noor' => 0.8,
                $open && $player->key === 'lucas' => 0.65,
                default => $player->predictActivity,
            };

            if ($activity <= 0.0 || in_array($player->id, [...$sideA, ...$sideB], true)) {
                continue;
            }

            $joined = $this->world->userById($player->id)->created_at;

            if (($player->leftWeek !== null && $week > $player->leftWeek) || ($joined !== null && $joined->greaterThan($until))) {
                continue;
            }

            if (! $rng->chance(min(0.95, $activity * $interest))) {
                continue;
            }

            $backsFavourite = $rng->chance($player->predictSharpness);
            $side = $backsFavourite ? $favourite : $favourite->opposite();
            $loserGames = match (true) {
                $match->best_of === 1 => 0,
                $rng->chance(0.15) => null,
                $backsFavourite && $favouriteChance >= 0.7 => $rng->chance(0.65) ? 0 : 1,
                default => $rng->chance(0.55) ? 1 : 0,
            };

            $earliest = $joined !== null && $joined->greaterThan($from) ? $joined : $from;
            $span = max(60, $until->getTimestamp() - $earliest->getTimestamp());
            $at = $earliest->addSeconds((int) ($span * $rng->float()));

            $picks[] = [$player->id, $side, $loserGames, $at->min($until)];
        }

        return $picks;
    }

    /**
     * Finals and semi-finals draw the most picks, early tournament rounds the fewest.
     */
    private function interest(BadmintonMatch $match): float
    {
        $tournament = $match->tournament;

        if ($tournament === null || $match->round === null) {
            return 1.0;
        }

        if (! $tournament->isKnockout()) {
            return 0.8;
        }

        return match ($tournament->totalRounds() - $match->round) {
            0 => 1.6,
            1 => 1.3,
            2 => 1.05,
            default => 0.75,
        };
    }
}
