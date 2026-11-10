<?php

namespace App\Support\Live;

use RuntimeException;

/**
 * A live-scoring request that cannot be carried out. The message is written for the umpire
 * and can be shown as it is.
 */
class LiveScoringException extends RuntimeException {}
