<?php

namespace App\Statistics\Metrics;

use App\Models\Book;
use App\Models\Scopes\BelongsToCurrentUser;
use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;

/**
 * Distinct books the user has read at least once, re-reads collapsed.
 *
 * Counts discarded copies: having got rid of a book doesn't undo reading it.
 */
class TotalBooksRead extends AbstractMetric
{
    public function key(): string
    {
        return 'totalBooksRead';
    }

    public function compute(Scope $scope, MetricResults $results): int
    {
        // Same reasoning as ReadInstanceQuery::forScope: the scope's subject
        // decides whose reads count, not the session.
        return Book::whereHas('readInstances', function ($query) use ($scope) {
            $query->withoutGlobalScope(BelongsToCurrentUser::class)
                ->where('read_instances.user_id', $scope->userId);
        })->count();
    }
}
