<?php

namespace App\Services\Exceptions;

use App\Services\LocationService;
use RuntimeException;

/**
 * Raised by {@see LocationService::delete()} when a location still shelves
 * copies and the caller did not pass `force`.
 *
 * A speed bump in the genre-delete mold: forcing through detaches the
 * versions (their `location_id` goes null — they become "unshelved", not
 * deleted). The count travels along so the SPA can state the impact.
 */
class LocationInUseException extends RuntimeException
{
    public function __construct(
        public readonly int $versionsCount,
        public readonly string $reasonCode = 'location_in_use',
    ) {
        parent::__construct("This location shelves {$versionsCount} cop(ies).");
    }
}
