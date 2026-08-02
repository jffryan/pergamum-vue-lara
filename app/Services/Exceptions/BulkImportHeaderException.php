<?php

namespace App\Services\Exceptions;

class BulkImportHeaderException extends BulkImportFileException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'header_invalid');
    }
}
