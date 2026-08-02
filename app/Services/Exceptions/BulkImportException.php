<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * Base for every failure the CSV importer raises. The two subclasses below split on
 * blast radius, not on cause: a file exception rejects the whole upload, a row
 * exception fails one row and lets the loop continue.
 */
abstract class BulkImportException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }
}
