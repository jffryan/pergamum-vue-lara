<?php

namespace App\Rules;

use App\Support\RatingValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A rating on the display scale: 0.5 to 5, in 0.5 steps.
 *
 * The constraint itself lives in {@see RatingValidator}, which bulk import
 * also calls directly on a code path that has no validator. This rule is the
 * FormRequest-shaped face of the same check, so the two can't drift.
 *
 * Null and empty string pass — a read with no rating is legitimate. Use
 * `nullable` alongside this rule rather than expecting it to enforce presence.
 */
class Rating implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! RatingValidator::isValid($value)) {
            $min = RatingValidator::MIN;
            $max = RatingValidator::MAX;

            $fail("rating '{$value}' must be between {$min} and {$max} in 0.5 steps");
        }
    }
}
