<?php

namespace App\Statistics\Metrics;

use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;
use App\Statistics\Support\ListQuery;

/**
 * How much shelf a list is, in pages, across every copy on it.
 *
 * Undeduped, deliberately. This measures physical volume rather than works: a
 * first edition and a battered reading copy of the same novel are two real
 * objects with real pages, and both take up room. Mixed media need no special
 * case — an audiobook stores zero pages and so contributes nothing here.
 *
 * The counterpart to {@see TotalItems}, which counts books and does dedupe.
 * The label a surface gives this should carry the qualifier ("all copies"),
 * because a reader who sees it beside "Books on List" will otherwise divide
 * one by the other.
 */
class TotalPages extends AbstractMetric
{
    protected array $scopes = [Scope::LIST];

    public function key(): string
    {
        return 'totalPages';
    }

    public function compute(Scope $scope, MetricResults $results): int
    {
        return (int) ListQuery::items($scope)->sum('versions.page_count');
    }
}
