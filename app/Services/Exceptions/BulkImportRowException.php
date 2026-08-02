<?php

namespace App\Services\Exceptions;

/**
 * Row-scoped rejection: caught inside the import loop and turned into a failed entry
 * in `results`. The controller deliberately does not catch this type, so a row
 * failure can never escape as a whole-file 422.
 */
class BulkImportRowException extends BulkImportException {}
