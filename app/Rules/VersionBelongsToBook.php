<?php

namespace App\Rules;

use App\Models\Version;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A version_id that names a copy of the book it is being filed under.
 *
 * `ReadInstance::booted()` throws a `\DomainException` on a mismatch, which
 * is the right last line of defence for code paths with no request behind
 * them (bulk import, tinker) but produces a 500 for a request. This rule is
 * the same check one layer earlier, where it can be a 422.
 *
 * Passes when either id is absent — `required` and `exists` own that failure,
 * and reporting it twice would bury the real error.
 */
class VersionBelongsToBook implements ValidationRule
{
    public function __construct(private mixed $bookId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $this->bookId === null) {
            return;
        }

        $versionBookId = Version::whereKey($value)->value('book_id');

        if ($versionBookId === null) {
            return;
        }

        if ((int) $versionBookId !== (int) $this->bookId) {
            $fail("version_id {$value} does not belong to book_id {$this->bookId}");
        }
    }
}
