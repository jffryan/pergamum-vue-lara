<?php

namespace App\Statistics\Metrics;

use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;
use App\Statistics\Support\ReadInstanceQuery;

/**
 * The user's mean rating on the display scale, or null when nothing is rated.
 *
 * `read_instances.rating` is stored doubled (see /documentation/books.md) so
 * that half-stars fit an integer column. Halving happens here, once, for
 * every rating metric — widgets receive 0–5 and never divide.
 */
class AverageRating extends AbstractMetric
{
    // Supports every scope whose base query {@see ReadInstanceQuery} can
    // narrow — the metric never learns which page asked for it.
    protected array $scopes = [Scope::USER, Scope::LIST];

    public function key(): string
    {
        return 'averageRating';
    }

    public function compute(Scope $scope, MetricResults $results): ?float
    {
        $average = ReadInstanceQuery::forScope($scope)
            ->query()
            ->where('read_instances.rating', '>', 0)
            ->avg('read_instances.rating');

        return $average === null ? null : round($average / 2, 1);
    }
}
