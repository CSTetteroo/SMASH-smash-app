<?php

namespace App\Actions\Matches;

use App\Models\BadmintonMatch;
use App\Services\Rating\RatingEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete a friendly (with its games, participants and predictions) and recalculate ratings.
 * Tournament matches belong to the draw: reset the draw instead.
 */
final class DeleteMatch
{
    /**
     * @throws ValidationException (key "match") for tournament matches
     */
    public static function handle(BadmintonMatch $match): void
    {
        if ($match->tournament_id !== null) {
            throw ValidationException::withMessages([
                'match' => 'Tournament matches are part of the draw and cannot be deleted. Reset the draw instead.',
            ]);
        }

        DB::transaction(static function () use ($match): void {
            $match->delete();

            RatingEngine::recalculate();
        });
    }
}
