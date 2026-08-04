<?php

namespace App\Http\Requests\Concerns;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

/**
 * `date_read` reaches the API in two shapes.
 *
 * The SPA's date inputs emit `Y-m-d`; the book edit form has historically
 * round-tripped `m/d/Y` back out of a display-formatted value, and bulk
 * import accepts both. The controllers used to call
 * `Carbon::createFromFormat('Y-m-d', …)` directly, which throws — a 500 — on
 * the second shape. Normalizing at the request boundary means everything
 * downstream sees one format and can stop guessing.
 */
trait NormalizesReadDates
{
    private const ACCEPTED_FORMATS = ['Y-m-d', 'm/d/Y', 'n/j/Y'];

    /**
     * Return the value as `Y-m-d`, or unchanged if it isn't a date we accept —
     * the `date` rule reports that, not this.
     */
    protected function normalizeReadDate(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value === '' ? null : $value;
        }

        $value = trim($value);

        foreach (self::ACCEPTED_FORMATS as $format) {
            try {
                // Carbon throws on a mismatch rather than returning false, and
                // parses leniently besides — so the round-trip comparison is
                // what actually decides whether this format is the right one.
                $parsed = Carbon::createFromFormat($format, $value);
            } catch (InvalidFormatException) {
                continue;
            }

            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed->format('Y-m-d');
            }
        }

        return $value;
    }

    /**
     * Apply {@see normalizeReadDate()} to `date_read` on every row of a list
     * of read instances, leaving the rest of each row alone.
     */
    protected function normalizeReadDatesIn(mixed $instances): mixed
    {
        if (! is_array($instances)) {
            return $instances;
        }

        return array_map(function ($instance) {
            if (is_array($instance) && array_key_exists('date_read', $instance)) {
                $instance['date_read'] = $this->normalizeReadDate($instance['date_read']);
            }

            return $instance;
        }, $instances);
    }
}
