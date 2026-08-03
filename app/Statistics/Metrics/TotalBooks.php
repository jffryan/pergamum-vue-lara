<?php

namespace App\Statistics\Metrics;

use App\Models\Book;
use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;

/**
 * Every book in the catalogue.
 *
 * Catalog-wide because books aren't owned yet (see /feature-plans/books.md);
 * `meta.catalogWide` says so out loud rather than quietly mixing scopes.
 *
 * Deliberately *not* shelf-scoped, and this one is forced rather than
 * chosen: it is the denominator of `percentageOfBooksRead`, whose numerator
 * counts discarded books. Excluding discarded books from the denominator
 * alone would let the percentage exceed 100.
 */
class TotalBooks extends AbstractMetric
{
    public function key(): string
    {
        return 'totalBooks';
    }

    public function isCatalogWide(): bool
    {
        return true;
    }

    public function compute(Scope $scope, MetricResults $results): int
    {
        return Book::count();
    }
}
