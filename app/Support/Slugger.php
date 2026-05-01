<?php

namespace App\Support;

use Illuminate\Support\Str;

class Slugger
{
    public const MAX_LENGTH = 60;

    public static function for(string $value, int $maxLength = self::MAX_LENGTH): string
    {
        $slug = Str::slug($value);

        if (strlen($slug) <= $maxLength) {
            return $slug;
        }

        $truncated = substr($slug, 0, $maxLength);
        $lastHyphen = strrpos($truncated, '-');

        if ($lastHyphen !== false && $lastHyphen > 0) {
            $truncated = substr($truncated, 0, $lastHyphen);
        }

        return rtrim($truncated, '-');
    }
}
