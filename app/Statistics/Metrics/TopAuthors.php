<?php

namespace App\Statistics\Metrics;

use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;
use App\Statistics\Support\ScopeQuery;
use Illuminate\Support\Facades\DB;

/**
 * The authors with the most distinct books in the scope, most first.
 *
 * Every credited author counts, not only the primary one — a co-written book
 * is as much one author's as the other's. Capped: on a broad genre the tail
 * is hundreds of one-book authors, and the point is who dominates it. Ties
 * break on filing name so the order is stable between requests.
 */
class TopAuthors extends AbstractMetric
{
    private const LIMIT = 10;

    protected array $scopes = [Scope::LIST, Scope::LOCATION, Scope::GENRE];

    public function key(): string
    {
        return 'topAuthors';
    }

    public function compute(Scope $scope, MetricResults $results): array
    {
        return DB::table('book_author')
            ->join('authors', 'authors.author_id', '=', 'book_author.author_id')
            ->whereIn('book_author.book_id', ScopeQuery::bookIds($scope))
            ->groupBy('authors.author_id', 'authors.first_name', 'authors.last_name', 'authors.slug')
            ->orderByDesc(DB::raw('COUNT(DISTINCT book_author.book_id)'))
            ->orderBy('authors.last_name')
            ->orderBy('authors.first_name')
            ->limit(self::LIMIT)
            ->get([
                'authors.author_id',
                'authors.first_name',
                'authors.last_name',
                'authors.slug',
                DB::raw('COUNT(DISTINCT book_author.book_id) as count'),
            ])
            ->map(fn ($row) => [
                'author_id' => (int) $row->author_id,
                'name' => trim("{$row->first_name} {$row->last_name}"),
                'slug' => $row->slug,
                'count' => (int) $row->count,
            ])
            ->all();
    }
}
