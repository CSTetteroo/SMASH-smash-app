<?php

namespace App\Services\Tournament;

use App\Enums\Side;
use App\Models\BadmintonMatch;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use Illuminate\Support\Collection;

/**
 * The table of a tournament (mainly round robins): every entry with its finished matches,
 * sorted by wins, game difference, point difference, then name. This is the same order
 * Tournament::champion() / runnerUp() use, so the table and the champion always agree.
 * Byes and cancelled or unfinished matches do not count.
 */
final class Standings
{
    /**
     * @return Collection<int, StandingRow>
     */
    public static function for(Tournament $tournament): Collection
    {
        $entries = $tournament->entries()->with(['player', 'partner'])->orderBy('id')->get();
        $matches = $tournament->matches()->with('games')->get();

        /** @var array<int, array{played: int, won: int, gamesFor: int, gamesAgainst: int, pointsFor: int, pointsAgainst: int}> $table */
        $table = [];

        foreach ($entries as $entry) {
            $table[$entry->id] = ['played' => 0, 'won' => 0, 'gamesFor' => 0, 'gamesAgainst' => 0, 'pointsFor' => 0, 'pointsAgainst' => 0];
        }

        foreach ($matches as $match) {
            /** @var BadmintonMatch $match */
            $match->setRelation('tournament', $tournament);

            if (! $match->isFinished() || $match->winning_side === null || $match->isBye()) {
                continue;
            }

            foreach (Side::cases() as $side) {
                $entryId = $side === Side::A ? $match->entry_a_id : $match->entry_b_id;

                if ($entryId === null || ! isset($table[$entryId])) {
                    continue;
                }

                $opponent = $side->opposite();
                $table[$entryId]['played']++;
                $table[$entryId]['won'] += $match->winning_side === $side ? 1 : 0;
                $table[$entryId]['gamesFor'] += $match->gamesWon($side);
                $table[$entryId]['gamesAgainst'] += $match->gamesWon($opponent);
                $table[$entryId]['pointsFor'] += $match->pointsWon($side);
                $table[$entryId]['pointsAgainst'] += $match->pointsWon($opponent);
            }
        }

        $sorted = $entries->sort(function (TournamentEntry $a, TournamentEntry $b) use ($table): int {
            $rowA = $table[$a->id];
            $rowB = $table[$b->id];

            return [$rowB['won'], $rowB['gamesFor'] - $rowB['gamesAgainst'], $rowB['pointsFor'] - $rowB['pointsAgainst'], $a->name(), $a->id]
                <=> [$rowA['won'], $rowA['gamesFor'] - $rowA['gamesAgainst'], $rowA['pointsFor'] - $rowA['pointsAgainst'], $b->name(), $b->id];
        })->values();

        $rows = [];

        foreach ($sorted as $index => $entry) {
            $row = $table[$entry->id];

            $rows[] = new StandingRow(
                position: $index + 1,
                entry: $entry,
                played: $row['played'],
                won: $row['won'],
                lost: $row['played'] - $row['won'],
                gamesFor: $row['gamesFor'],
                gamesAgainst: $row['gamesAgainst'],
                pointsFor: $row['pointsFor'],
                pointsAgainst: $row['pointsAgainst'],
            );
        }

        return new Collection($rows);
    }
}
