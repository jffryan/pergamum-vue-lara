<?php

namespace App\Services\Exceptions;

/**
 * Whole-file rejection: nothing is imported. Caught by BulkUploadController and
 * rendered as a 422 {reason_code, reason}.
 */
class BulkImportFileException extends BulkImportException {}
