<?php

namespace App\Services\Exceptions;

use App\Services\LocationService;
use RuntimeException;

/**
 * Raised by {@see LocationService::shelveVersion()} when the copy is
 * discarded. A discarded copy is in the discarded pile by definition and
 * cannot be on a shelf at the same time — restore it first.
 */
class CopyDiscardedException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode = 'copy_discarded',
    ) {
        parent::__construct('A discarded copy cannot be shelved. Restore it first.');
    }
}
