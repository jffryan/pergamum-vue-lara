<?php

namespace App\Statistics\Metrics;

use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;
use App\Statistics\Support\ReadInstanceQuery;

/**
 * How many reads sit at each rating, highest first, on the display scale.
 *
 * Unrated reads (null or 0) are absent rather than bucketed at zero — "I
 * didn't rate it" isn't a rating.
 *
 * The grouping predicates name the column, so they see the doubled storage
 * value; `$row->rating` is hydrated onto a model and comes back through
 * `ReadInstance`'s accessor already on the display scale.
 */
class RatingDistribution extends AbstractMetric
{
    protected array $scopes = [Scope::USER, Scope::LIST];

    public function key(): string
    {
        return 'ratingDistribution';
    }

    public function compute(Scope $scope, MetricResults $results): array
    {
        return ReadInstanceQuery::forScope($scope)
            ->query()
            ->where('read_instances.rating', '>', 0)
            ->selectRaw('read_instances.rating as rating, COUNT(*) as total')
            ->groupBy('read_instances.rating')
            ->orderBy('read_instances.rating', 'desc')
            ->get()
            ->map(fn ($row) => [
                'rating' => round($row->rating, 1),
                'total' => (int) $row->total,
            ])
            ->all();
    }
}
