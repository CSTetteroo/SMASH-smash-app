<?php

namespace App\Services\Tournament;

use App\Models\BadmintonMatch;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Collection;

/**
 * A tournament's draw grouped by round, ready to render: knockout rounds ("Round of 16",
 * "Quarter-finals", …, "Final") or round robin matchdays ("Matchday 1"), matches in bracket order
 * with entries, participants and games eager-loaded (no further queries while rendering).
 */
final class Bracket
{
    /**
     * Relations every bracket cell needs.
     */
    public const RELATIONS = [
        'participants.user',
        'games',
        'entryA.player',
        'entryA.partner',
        'entryB.player',
        'entryB.partner',
    ];

    /**
     * Also sets $tournament->matches to the loaded draw, so progress(), champion() and
     * totalRounds() on the same instance need no extra queries.
     *
     * @return list<array{round: int, label: string, matches: Collection<int, BadmintonMatch>}>
     */
    public static function for(Tournament $tournament): array
    {
        $matches = $tournament->matches()
            ->with(self::RELATIONS)
            ->orderBy('round')
            ->orderBy('bracket_position')
            ->orderBy('id')
            ->get();

        $tournament->setRelation('matches', $matches);

        $rounds = [];

        foreach ($matches as $match) {
            $match->setRelation('tournament', $tournament);
            $rounds[(int) $match->round][] = $match;
        }

        ksort($rounds);

        $result = [];

        foreach ($rounds as $round => $roundMatches) {
            $result[] = [
                'round' => $round,
                'label' => $tournament->roundLabel($round, plural: true),
                'matches' => new Collection($roundMatches),
            ];
        }

        return $result;
    }
}
