<?php

namespace App\Services\Exceptions;

class BulkImportListNameException extends BulkImportFileException
{
    public function __construct(string $name)
    {
        parent::__construct("a list named '{$name}' already exists", 'list_name_taken');
    }
}
