<?php

namespace App\Statistics\Metrics;

use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;
use App\Statistics\Support\ReadInstanceQuery;

/**
 * Distinct books with at least one *logged* read: a read carrying both a
 * `date_read` and a rating.
 *
 * The third of the read counts, alongside {@see TotalReads} (every read,
 * re-reads included) and {@see TotalBooksRead} (distinct books, however
 * sparse the record). A book read three times counts once here if any one
 * of those reads is logged.
 *
 * "Rated" means `rating > 0`, matching {@see AverageRating}: a blank rating
 * used to be submitted as `""` and stored as `0`, so zero is not a rating.
 */
class LoggedBooksRead extends AbstractMetric
{
    public function key(): string
    {
        return 'loggedBooksRead';
    }

    public function compute(Scope $scope, MetricResults $results): int
    {
        return ReadInstanceQuery::forScope($scope)
            ->query()
            ->whereNotNull('read_instances.date_read')
            ->where('read_instances.rating', '>', 0)
            ->distinct()
            ->count('read_instances.book_id');
    }
}
