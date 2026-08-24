<?php

namespace App\Services\Exceptions;

use App\Services\LocationService;
use RuntimeException;

/**
 * Raised by {@see LocationService::update()} when a move would parent a
 * location under itself or one of its own descendants — the one operation on
 * a self-referencing tree that can knot it.
 */
class LocationCycleException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode = 'location_cycle',
    ) {
        parent::__construct('A location cannot be moved into itself or one of its own descendants.');
    }
}
