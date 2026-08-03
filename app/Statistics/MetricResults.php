<?php

namespace App\Statistics;

use RuntimeException;

/**
 * Computed-value bag handed to each metric so dependents can read what they
 * depend on instead of re-running the query. `PercentageOfBooksRead` asking
 * for `totalBooks` here is what fixes the double-count the old service did.
 */
class MetricResults
{
    private array $values = [];

    public function put(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key): mixed
    {
        if (! $this->has($key)) {
            throw new RuntimeException("Metric [{$key}] was not computed before it was needed.");
        }

        return $this->values[$key];
    }

    /**
     * @param  string[]  $keys
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->values, array_flip($keys));
    }
}
