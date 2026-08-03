<?php

namespace App\Statistics\Metrics;

use App\Models\Book;
use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;

/**
 * Distinct books the user has read at least once, re-reads collapsed.
 *
 * Counts discarded copies: having got rid of a book doesn't undo reading it.
 */
class TotalBooksRead extends AbstractMetric
{
    public function key(): string
    {
        return 'totalBooksRead';
    }

    public function compute(Scope $scope, MetricResults $results): int
    {
        return Book::whereHas('readInstances', function ($query) use ($scope) {
            $query->where('user_id', $scope->userId);
        })->count();
    }
}
