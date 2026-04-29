<?php

namespace App\Services\Exceptions;

use RuntimeException;

class BulkImportHeaderException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reasonCode = 'header_invalid')
    {
        parent::__construct($message);
    }
}
