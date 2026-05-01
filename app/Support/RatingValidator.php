<?php

namespace App\Support;

class RatingValidator
{
    public const MIN = 0.5;

    public const MAX = 5.0;

    public static function isValid($rating): bool
    {
        if (! is_numeric($rating)) {
            return false;
        }

        $value = (float) $rating;

        return $value >= self::MIN
            && $value <= self::MAX
            && fmod($value * 2, 1) === 0.0;
    }
}
