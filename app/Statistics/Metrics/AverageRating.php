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
 * that half-stars fit an integer column. `ReadInstance`'s accessor normally
 * undoes that, but `avg()` passes straight through to the query builder and
 * never hydrates a model, so this is one of the two paths that still has to
 * halve by hand. Widgets receive 0–5 either way.
 */
class AverageRating extends AbstractMetric
{
    // Supports every scope whose base query {@see ReadInstanceQuery} can
    // narrow — the metric never learns which page asked for it.
    protected array $scopes = [Scope::USER, Scope::LIST, Scope::LOCATION];

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
