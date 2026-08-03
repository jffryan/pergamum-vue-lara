<?php

namespace App\Statistics\Metrics;

use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;
use App\Statistics\Support\ReadInstanceQuery;

/**
 * Reads per calendar year — `COUNT(*)`, so a re-read counts again.
 *
 * Formerly `booksReadByYear`, which was the same query under a label that
 * said "books". {@see UniqueBooksReadByYear} is the metric that name
 * described; this one keeps the numbers and drops the claim.
 */
class ReadsByYear extends AbstractMetric
{
    public function key(): string
    {
        return 'readsByYear';
    }

    public function compute(Scope $scope, MetricResults $results): array
    {
        return ReadInstanceQuery::forScope($scope)->groupedByYear('COUNT(*)');
    }
}
