<?php

namespace App\Services\Exceptions;

use App\Services\LocationService;
use RuntimeException;

/**
 * Raised by {@see LocationService::delete()} when a location still has child
 * locations. Unlike shelved copies, this is not forceable: deleting a room
 * through its bookcases is the cascade footgun the schema's `restrict` FK
 * exists to prevent. Move or delete the children first, explicitly.
 */
class LocationHasChildrenException extends RuntimeException
{
    public function __construct(
        public readonly int $childrenCount,
        public readonly string $reasonCode = 'location_has_children',
    ) {
        parent::__construct("This location contains {$childrenCount} other location(s). Move or delete them first.");
    }
}
