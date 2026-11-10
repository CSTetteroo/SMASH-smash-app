<?php

namespace App\Support\Live;

/**
 * The scoresheet changed since the console last showed it (another device scored a rally),
 * so the request was not applied.
 */
final class ScoreConflict extends LiveScoringException {}
