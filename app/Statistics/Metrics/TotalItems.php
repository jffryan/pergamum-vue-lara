<?php

namespace App\Statistics\Metrics;

use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;
use App\Statistics\Support\ListQuery;

/**
 * How many distinct books a list covers.
 *
 * Dedupes by book: this counts *works*, so owning the paperback and the
 * audiobook of one novel is one book on the list. {@see TotalPages} answers a
 * question about physical volume instead and deliberately does not dedupe.
 */
class TotalItems extends AbstractMetric
{
    protected array $scopes = [Scope::LIST];

    public function key(): string
    {
        return 'totalItems';
    }

    public function compute(Scope $scope, MetricResults $results): int
    {
        return ListQuery::items($scope)->distinct()->count('versions.book_id');
    }
}
