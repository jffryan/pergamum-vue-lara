<?php

namespace App\Statistics\Metrics;

use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;
use App\Statistics\Support\ScopeQuery;

/**
 * Distinct books on a list the user has read at least once.
 *
 * Counts works, like {@see TotalItems}, and counts a read of *any* copy — the
 * list may hold the hardback while the read was recorded against the audio.
 */
class CompletedCount extends AbstractMetric
{
    protected array $scopes = [Scope::LIST, Scope::LOCATION];

    public function key(): string
    {
        return 'completedCount';
    }

    public function compute(Scope $scope, MetricResults $results): int
    {
        return ScopeQuery::items($scope)
            ->whereExists(function ($query) use ($scope) {
                $query->selectRaw('1')
                    ->from('read_instances')
                    ->whereColumn('read_instances.book_id', 'versions.book_id')
                    ->where('read_instances.user_id', $scope->userId);
            })
            ->distinct()
            ->count('versions.book_id');
    }
}
