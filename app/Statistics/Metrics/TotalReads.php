<?php

namespace App\Statistics\Metrics;

use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;
use App\Statistics\Support\ReadInstanceQuery;

/**
 * Every read the user has recorded, re-reads included.
 *
 * Deliberately unfiltered by `date_read`: the dashboard used to derive this
 * by summing `readsByYear`, which silently dropped undated reads.
 */
class TotalReads extends AbstractMetric
{
    public function key(): string
    {
        return 'totalReads';
    }

    public function compute(Scope $scope, MetricResults $results): int
    {
        return ReadInstanceQuery::forScope($scope)->query()->count();
    }
}
