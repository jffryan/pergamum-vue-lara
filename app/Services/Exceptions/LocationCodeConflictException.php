<?php

namespace App\Services\Exceptions;

use App\Models\Location;
use App\Services\LocationService;
use RuntimeException;

/**
 * Raised by {@see LocationService} when a create or recode would produce a
 * second location sharing a code.
 *
 * Codes are globally unique — the slug derives from the code and is uniquely
 * indexed, so 'O1S5' can exist once no matter where it hangs. Same shape as
 * `GenreNameConflictException`: a 409 carrying the colliding row, so the SPA
 * can link to it without a second round-trip.
 */
class LocationCodeConflictException extends RuntimeException
{
    public function __construct(
        public readonly Location $conflict,
        public readonly string $reasonCode = 'location_code_taken',
    ) {
        parent::__construct("A location with code \"{$conflict->code}\" already exists.");
    }
}
