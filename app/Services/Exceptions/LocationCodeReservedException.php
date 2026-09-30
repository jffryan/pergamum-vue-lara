<?php

namespace App\Services\Exceptions;

use App\Services\LocationService;
use RuntimeException;

/**
 * Raised by {@see LocationService} when a code would slug to one of the
 * virtual locations (`unshelved`, `discarded`). Those are routes and
 * derived views, not rows — a real location under that slug would be
 * unreachable behind them.
 */
class LocationCodeReservedException extends RuntimeException
{
    public function __construct(
        public readonly string $locationCode,
        public readonly string $reasonCode = 'location_code_reserved',
    ) {
        parent::__construct("'{$locationCode}' is reserved for a built-in location and cannot be used as a code.");
    }
}
