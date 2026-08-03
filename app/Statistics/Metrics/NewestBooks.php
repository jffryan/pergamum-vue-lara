<?php

namespace App\Statistics\Metrics;

use App\Models\Book;
use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;

/**
 * The five most recently catalogued books still on the shelf.
 *
 * Shelf-scoped, unlike `totalBooks`: it sits in no ratio, so nothing forces
 * its axis, and its job is to answer "what's new on my shelf". Bulk upload
 * sharpens the point — `created_at` is import time, not acquisition time, so
 * importing a historical backlog would otherwise fill the card with books
 * that were discarded years ago.
 */
class NewestBooks extends AbstractMetric
{
    private const LIMIT = 5;

    public function key(): string
    {
        return 'newestBooks';
    }

    public function isCatalogWide(): bool
    {
        return true;
    }

    public function isShelfScoped(): bool
    {
        return true;
    }

    public function compute(Scope $scope, MetricResults $results): array
    {
        return Book::onShelf()
            ->orderByDesc('created_at')
            ->orderByDesc('book_id')
            ->limit(self::LIMIT)
            ->get(['book_id', 'title', 'slug'])
            ->map(fn (Book $book) => [
                'book_id' => $book->book_id,
                'title' => $book->title,
                'slug' => $book->slug,
            ])
            ->all();
    }
}
