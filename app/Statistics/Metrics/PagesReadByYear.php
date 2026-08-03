<?php

namespace App\Statistics\Metrics;

use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;
use App\Statistics\Support\ReadInstanceQuery;

/**
 * Pages read per calendar year.
 *
 * Measured but partial by construction: a format that declares
 * `expects_page_count = false` stores no page count at all, so an audio-heavy
 * year reads as near-zero here. That is what keeps this series and
 * `audioRuntimeByYear` disjoint rather than double-counting. See
 * {@see EstimatedTotalPagesByYear} for the combined view — this series stays
 * independently requestable precisely so a surface can show the honest split
 * instead of the estimate.
 */
class PagesReadByYear extends AbstractMetric
{
    public function key(): string
    {
        return 'pagesReadByYear';
    }

    public function compute(Scope $scope, MetricResults $results): array
    {
        return ReadInstanceQuery::forScope($scope)
            ->joinVersions()
            ->groupedByYear('SUM(versions.page_count)');
    }
}
