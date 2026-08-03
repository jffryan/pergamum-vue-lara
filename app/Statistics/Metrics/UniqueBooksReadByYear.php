<?php

namespace App\Statistics\Metrics;

use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;
use App\Statistics\Support\ReadInstanceQuery;

/**
 * Distinct books read per calendar year — the metric the old
 * `booksReadByYear` label claimed to be. Reading one book twice in a year
 * counts once here and twice in {@see ReadsByYear}.
 */
class UniqueBooksReadByYear extends AbstractMetric
{
    public function key(): string
    {
        return 'uniqueBooksReadByYear';
    }

    public function compute(Scope $scope, MetricResults $results): array
    {
        return ReadInstanceQuery::forScope($scope)
            ->groupedByYear('COUNT(DISTINCT read_instances.book_id)');
    }
}
