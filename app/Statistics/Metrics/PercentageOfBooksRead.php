<?php

namespace App\Statistics\Metrics;

use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;

/**
 * How much of the catalogue the user has read, 0–100.
 *
 * Reads both counts out of {@see MetricResults} rather than re-running them,
 * which is the whole point of dependency resolution — the old service ran
 * four queries where two would do.
 */
class PercentageOfBooksRead extends AbstractMetric
{
    public function key(): string
    {
        return 'percentageOfBooksRead';
    }

    public function dependsOn(): array
    {
        return ['totalBooks', 'totalBooksRead'];
    }

    public function compute(Scope $scope, MetricResults $results): float
    {
        $totalBooks = (int) $results->get('totalBooks');
        $booksRead = (int) $results->get('totalBooksRead');

        if ($totalBooks <= 0) {
            return 0.0;
        }

        return round(($booksRead / $totalBooks) * 100, 2);
    }
}
