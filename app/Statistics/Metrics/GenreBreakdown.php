<?php

namespace App\Statistics\Metrics;

use App\Statistics\AbstractMetric;
use App\Statistics\MetricResults;
use App\Statistics\Scope;
use App\Statistics\Support\ListQuery;
use Illuminate\Support\Facades\DB;

/**
 * Genres on a list with how many distinct books carry each, commonest first.
 *
 * Counts books rather than copies, so a novel held twice doesn't inflate its
 * genre. Ties break alphabetically to keep the order stable between requests.
 */
class GenreBreakdown extends AbstractMetric
{
    protected array $scopes = [Scope::LIST];

    public function key(): string
    {
        return 'genreBreakdown';
    }

    public function compute(Scope $scope, MetricResults $results): array
    {
        return DB::table('book_genre')
            ->join('genres', 'genres.genre_id', '=', 'book_genre.genre_id')
            ->whereIn('book_genre.book_id', ListQuery::bookIds($scope))
            ->groupBy('genres.genre_id', 'genres.name')
            ->orderByDesc(DB::raw('COUNT(DISTINCT book_genre.book_id)'))
            ->orderBy('genres.name')
            ->get([
                'genres.name as name',
                DB::raw('COUNT(DISTINCT book_genre.book_id) as count'),
            ])
            ->map(fn ($row) => ['name' => $row->name, 'count' => (int) $row->count])
            ->all();
    }
}
